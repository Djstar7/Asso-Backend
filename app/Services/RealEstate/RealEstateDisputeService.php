<?php

namespace App\Services\RealEstate;

use App\Models\RealEstate\Dispute;
use App\Models\RealEstate\DisputeReason;
use App\Models\RealEstate\Reservation;
use App\Models\RealEstate\Unit;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\FirebaseMessagingService;
use App\Services\WalletService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Litiges immobiliers.
 *
 * Ouvert par le locataire (ou l'acheteur) avant la fin du délai de visite, le
 * litige suspend le déblocage automatique. ASSO tranche :
 *  - remboursement : la part bloquée du bailleur (ou du cabinet) revient au
 *    locataire, la place est remise en vente ;
 *  - déblocage : la part est versée au bailleur.
 * Les frais ASSO et la commission du cabinet déjà crédités ne sont pas remboursés.
 */
class RealEstateDisputeService
{
    /** @param UploadedFile[] $photos */
    public function open(Reservation $reservation, User $tenant, int $reasonId, string $description, array $photos = []): Dispute
    {
        if ($reservation->tenant_id !== $tenant->id) {
            throw new \DomainException(__('real_estate.dispute.not_allowed'));
        }
        if ($reservation->status !== 'paid' || ($reservation->visit_deadline_at && $reservation->visit_deadline_at->isPast())) {
            throw new \DomainException(__('real_estate.dispute.window_closed'));
        }
        $reason = DisputeReason::for($reservation->kind)->whereKey($reasonId)->first();
        if (! $reason) {
            throw new \DomainException(__('real_estate.dispute.unknown_reason'));
        }

        $dispute = DB::transaction(function () use ($reservation, $reason, $description) {
            $r = Reservation::whereKey($reservation->id)->lockForUpdate()->first();
            if ($r->status !== 'paid') {
                throw new \DomainException(__('real_estate.dispute.window_closed'));
            }
            $r->update(['status' => 'disputed']);

            return Dispute::create([
                'reservation_id' => $r->id,
                'reason_id' => $reason->id,
                'reason_label' => $reason->getTranslation('label', 'fr') ?? $reason->label,
                'description' => $description,
                'status' => 'open',
            ]);
        });
        $this->attach($dispute, 'tenant', $photos);

        $reservation->refresh();
        $this->notify($reservation->payee, 'real_estate_dispute_opened_payee', $reservation);

        return $dispute;
    }

    /** Réponse et pièces du bailleur, ou nouvelles pièces du locataire. */
    public function addEvidence(Dispute $dispute, User $user, ?string $text, array $photos = []): void
    {
        if ($dispute->status !== 'open') {
            throw new \DomainException(__('real_estate.dispute.closed'));
        }
        $reservation = $dispute->reservation;
        $author = match ($user->id) {
            $reservation->tenant_id => 'tenant',
            $reservation->payee_id => 'payee',
            default => throw new \DomainException(__('real_estate.dispute.not_allowed')),
        };
        if ($author === 'payee' && filled($text)) {
            $dispute->update(['payee_response' => $text]);
        } elseif ($author === 'tenant' && filled($text)) {
            $dispute->update(['description' => $dispute->description."\n\n".$text]);
        }
        $this->attach($dispute, $author, $photos);
    }

    /** Décision d'ASSO : rembourser le locataire ou débloquer au bailleur. */
    public function decide(Dispute $dispute, string $decision, ?string $note, ?int $adminId): void
    {
        DB::transaction(function () use ($dispute, $decision, $note, $adminId) {
            $d = Dispute::whereKey($dispute->id)->lockForUpdate()->first();
            if ($d->status !== 'open') {
                throw new \DomainException(__('real_estate.dispute.closed'));
            }
            $r = Reservation::whereKey($d->reservation_id)->lockForUpdate()->first();
            $wallet = app(WalletService::class);
            $amount = (float) $r->owner_amount;

            if ($decision === 'refund') {
                // La part bloquée quitte le Wallet du bailleur et revient au locataire.
                $wallet->releaseEscrow($r->payee, $amount,
                    WalletTransaction::label('immo_dispute_refunded_payee', ['reference' => $r->reference]),
                    'real_estate_reservation', $r->id);
                $wallet->credit($r->tenant, $amount, null,
                    WalletTransaction::label('immo_dispute_refund', ['reference' => $r->reference]),
                    ['reference_type' => 'real_estate_reservation', 'reference_id' => $r->id]);
                if ($r->unit_id) {
                    Unit::whereKey($r->unit_id)->increment('units_available');
                }
                $r->update(['status' => 'refunded']);
                $d->update(['status' => 'refunded']);
            } else {
                $wallet->unlockFunds($r->payee, $amount,
                    WalletTransaction::label('immo_funds_released', ['reference' => $r->reference]),
                    'real_estate_reservation', $r->id);
                $r->update(['status' => 'released', 'released_at' => now()]);
                $d->update(['status' => 'released']);
            }
            $d->update(['decision_note' => $note, 'decided_by' => $adminId, 'decided_at' => now()]);
        });

        $reservation = $dispute->reservation->fresh();
        $key = $decision === 'refund' ? 'real_estate_dispute_refunded' : 'real_estate_dispute_released';
        $this->notify($reservation->tenant, "{$key}_tenant", $reservation);
        $this->notify($reservation->payee, "{$key}_payee", $reservation);
    }

    /** @param UploadedFile[] $photos */
    private function attach(Dispute $dispute, string $author, array $photos): void
    {
        foreach (array_slice($photos, 0, 8) as $photo) {
            $dispute->attachments()->create([
                'author' => $author,
                'path' => $photo->store("real_estate/disputes/{$dispute->id}", 'public'),
            ]);
        }
    }

    private function notify(?User $user, string $key, Reservation $r): void
    {
        if (! $user) {
            return;
        }
        $replace = ['title' => $r->listing?->title, 'reference' => $r->reference];
        try {
            app(FirebaseMessagingService::class)->sendToUser(
                $user,
                $user->localized("notifications.$key.title", $replace),
                $user->localized("notifications.$key.body", $replace),
                ['type' => $key, 'action' => 'open_real_estate_reservation', 'reservation_id' => $r->id],
            );
        } catch (\Throwable $e) {
            Log::error('[IMMO] Notification litige non envoyée', ['key' => $key, 'error' => $e->getMessage()]);
        }
    }
}
