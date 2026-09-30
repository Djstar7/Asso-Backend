<?php

namespace App\Services;

use App\Models\DiaspoOffer;
use App\Models\DiaspoVerificationEvent;
use App\Models\Setting;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Règles de vérification d'identité Diaspo (P7).
 *
 * Un voyageur non vérifié peut publier : son offre est visible avec la mention
 * « Profil non vérifié » mais n'est pas réservable. S'il ne fait pas valider son
 * identité avant l'échéance de régularisation (réglée par ASSO), l'offre est retirée.
 * Toutes les transitions sont journalisées dans diaspo_verification_events.
 */
class DiaspoVerificationService
{
    public const GRACE_DAYS_KEY = 'diaspo_verification_grace_days';
    public const DEFAULT_GRACE_DAYS = 7;
    /** Rappel envoyé quand il reste au plus ce nombre d'heures avant l'échéance. */
    public const REMINDER_HOURS = 48;

    public const DEADLINE_REMOVAL_REASON = 'Pièces d\'identité non fournies ou non conformes dans le délai de régularisation';

    public static function graceDays(): int
    {
        return max(1, (int) Setting::get(self::GRACE_DAYS_KEY, self::DEFAULT_GRACE_DAYS));
    }

    public static function setGraceDays(int $days): void
    {
        Setting::set(self::GRACE_DAYS_KEY, $days, 'integer', 'diaspo', 'Délai de régularisation de l\'identité Diaspo (jours)');
    }

    public static function log(
        string $event,
        ?int $userId,
        ?int $offerId = null,
        ?int $actorId = null,
        ?string $from = null,
        ?string $to = null,
        ?string $reason = null,
        array $meta = [],
    ): DiaspoVerificationEvent {
        return DiaspoVerificationEvent::create([
            'user_id' => $userId,
            'diaspo_offer_id' => $offerId,
            'actor_id' => $actorId,
            'event' => $event,
            'status_from' => $from,
            'status_to' => $to,
            'reason' => $reason,
            'meta' => $meta ?: null,
        ]);
    }

    /** Offre créée par un profil non vérifié : publiée tout de suite, avec échéance. */
    public function publishUnverified(DiaspoOffer $offer): void
    {
        $deadline = now()->addDays(self::graceDays());
        $offer->forceFill(['verification_deadline_at' => $deadline])->saveQuietly();

        self::log(
            DiaspoVerificationEvent::OFFER_PUBLISHED_UNVERIFIED,
            $offer->user_id,
            $offer->id,
            $offer->user_id,
            to: 'pending',
            meta: ['deadline' => $deadline->toIso8601String()],
        );
    }

    /** Échéance la plus proche parmi les offres encore non vérifiées du voyageur. */
    public static function nextDeadlineFor(User $user): ?CarbonInterface
    {
        $value = DiaspoOffer::where('user_id', $user->id)
            ->awaitingVerification()
            ->min('verification_deadline_at');

        return $value ? \Illuminate\Support\Carbon::parse($value) : null;
    }

    public function documentsSubmitted(User $user, string $previousStatus): void
    {
        self::log(
            DiaspoVerificationEvent::DOCUMENTS_SUBMITTED,
            $user->id,
            actorId: $user->id,
            from: $previousStatus,
            to: 'pending',
        );
    }

    /**
     * Identité validée : la mention « Profil non vérifié » disparaît de toutes les
     * offres encore publiées (ou restées en attente) du voyageur.
     */
    public function approveIdentity(User $user, ?int $adminId): void
    {
        DB::transaction(function () use ($user, $adminId) {
            $previous = $user->diaspo_verification_status;
            $user->update([
                'diaspo_verification_status' => 'verified',
                'diaspo_verified_at' => now(),
                'diaspo_rejection_reason' => null,
            ]);
            self::log(DiaspoVerificationEvent::IDENTITY_VERIFIED, $user->id, actorId: $adminId, from: $previous, to: 'verified');

            $offers = DiaspoOffer::where('user_id', $user->id)->awaitingVerification()->get();
            foreach ($offers as $offer) {
                $offer->update([
                    'status' => 'approved',
                    'verification_status' => 'verified',
                    'verified_at' => now(),
                    'verified_by' => $adminId,
                    'rejection_reason' => null,
                ]);
                self::log(DiaspoVerificationEvent::OFFER_VERIFIED, $user->id, $offer->id, $adminId, 'pending', 'verified');
            }
        });

        $this->notify($user, $user->translate('notifications.diaspo_verified.title'), $user->translate('notifications.diaspo_verified.body'), [
            'type' => 'diaspo_verified',
            'action' => 'open_diaspo',
        ]);
    }

    /**
     * Pièces refusées : les offres restent publiées avec la mention « Profil non
     * vérifié » ; le voyageur peut renvoyer des pièces jusqu'à l'échéance.
     */
    public function rejectIdentity(User $user, string $reason, ?int $adminId): void
    {
        DB::transaction(function () use ($user, $reason, $adminId) {
            $previous = $user->diaspo_verification_status;
            $user->update([
                'diaspo_verification_status' => 'rejected',
                'diaspo_verified_at' => null,
                'diaspo_rejection_reason' => $reason,
            ]);
            self::log(DiaspoVerificationEvent::IDENTITY_REJECTED, $user->id, actorId: $adminId, from: $previous, to: 'rejected', reason: $reason);
        });

        $deadline = self::nextDeadlineFor($user);
        $body = $deadline
            ? $user->translate('notifications.diaspo_rejected.body_with_deadline', [
                'reason' => $reason,
                'date' => $deadline->format('d/m/Y'),
            ])
            : $user->translate('notifications.diaspo_rejected.body', ['reason' => $reason]);

        $this->notify($user, $user->translate('notifications.diaspo_rejected.title'), $body, [
            'type' => 'diaspo_rejected',
            'action' => 'open_diaspo',
            'reason' => $reason,
        ]);
    }

    public function extendDeadline(DiaspoOffer $offer, int $days, ?int $adminId): void
    {
        $base = $offer->verification_deadline_at && $offer->verification_deadline_at->isFuture()
            ? $offer->verification_deadline_at
            : now();
        $previous = $offer->verification_deadline_at;
        $deadline = $base->copy()->addDays($days);

        $offer->forceFill([
            'verification_deadline_at' => $deadline,
            'verification_reminder_sent_at' => null,
        ])->saveQuietly();

        self::log(DiaspoVerificationEvent::DEADLINE_EXTENDED, $offer->user_id, $offer->id, $adminId, meta: [
            'previous_deadline' => $previous?->toIso8601String(),
            'deadline' => $deadline->toIso8601String(),
            'days' => $days,
        ]);
    }

    /** Rappel unique aux voyageurs dont l'échéance approche. */
    public function sendReminders(): int
    {
        $offers = DiaspoOffer::with('user')
            ->awaitingVerification()
            ->whereNull('verification_reminder_sent_at')
            ->whereNotNull('verification_deadline_at')
            ->where('verification_deadline_at', '>', now())
            ->where('verification_deadline_at', '<=', now()->addHours(self::REMINDER_HOURS))
            ->get();

        foreach ($offers as $offer) {
            $offer->forceFill(['verification_reminder_sent_at' => now()])->saveQuietly();
            self::log(DiaspoVerificationEvent::DEADLINE_REMINDER_SENT, $offer->user_id, $offer->id, meta: [
                'deadline' => $offer->verification_deadline_at->toIso8601String(),
            ]);

            if ($offer->user) {
                $this->notify(
                    $offer->user,
                    $offer->user->translate('notifications.diaspo_verification_deadline.title'),
                    $offer->user->translate('notifications.diaspo_verification_deadline.body', [
                        'from' => $offer->departure_city,
                        'to' => $offer->arrival_city,
                        'date' => $offer->verification_deadline_at->format('d/m/Y'),
                        'time' => $offer->verification_deadline_at->format('H:i'),
                    ]),
                    ['type' => 'diaspo_verification_deadline', 'action' => 'open_diaspo', 'offer_id' => (string) $offer->id],
                );
            }
        }

        return $offers->count();
    }

    /**
     * Retire les offres dont l'échéance est dépassée sans identité validée.
     * Une offre portant une réservation payée n'est jamais retirée automatiquement
     * (cas impossible en temps normal puisqu'elle n'est pas réservable) : elle est
     * signalée dans les logs pour arbitrage manuel.
     */
    public function removeExpiredUnverifiedOffers(): int
    {
        $offers = DiaspoOffer::with('user')
            ->awaitingVerification()
            ->whereNotNull('verification_deadline_at')
            ->where('verification_deadline_at', '<=', now())
            ->get();

        $removed = 0;
        foreach ($offers as $offer) {
            if ($offer->user?->isDiaspoVerified()) {
                continue;
            }
            if ($offer->bookings()->whereIn('status', ['paid', 'confirmed'])->exists()) {
                Log::warning('[DIASPO-VERIFICATION] Offre non retirée : réservation payée en cours', ['offer_id' => $offer->id]);
                continue;
            }

            DB::transaction(function () use ($offer) {
                $offer->update([
                    'status' => 'rejected',
                    'verification_status' => 'rejected',
                    'rejection_reason' => self::DEADLINE_REMOVAL_REASON,
                    'removal_reason' => self::DEADLINE_REMOVAL_REASON,
                ]);
                self::log(
                    DiaspoVerificationEvent::OFFER_REMOVED_DEADLINE,
                    $offer->user_id,
                    $offer->id,
                    from: 'pending',
                    to: 'removed',
                    reason: self::DEADLINE_REMOVAL_REASON,
                    meta: [
                        'deadline' => $offer->verification_deadline_at->toIso8601String(),
                        'identity_status' => $offer->user?->diaspo_verification_status,
                    ],
                );
                $offer->delete();
            });
            $removed++;

            if ($offer->user) {
                $this->notify(
                    $offer->user,
                    $offer->user->translate('notifications.diaspo_offer_removed.title'),
                    $offer->user->translate('notifications.diaspo_offer_removed.body', [
                        'from' => $offer->departure_city,
                        'to' => $offer->arrival_city,
                    ]),
                    ['type' => 'diaspo_offer_removed', 'action' => 'open_diaspo', 'offer_id' => (string) $offer->id],
                );
            }
        }

        return $removed;
    }

    private function notify(User $user, string $title, string $body, array $data): void
    {
        try {
            app(FirebaseMessagingService::class)->sendToUser($user, $title, $body, $data);
        } catch (\Throwable $e) {
            Log::error('[DIASPO-VERIFICATION] Notification non envoyée', [
                'user_id' => $user->id,
                'type' => $data['type'] ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
