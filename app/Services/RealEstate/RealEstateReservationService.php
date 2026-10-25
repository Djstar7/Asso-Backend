<?php

namespace App\Services\RealEstate;

use App\Models\RealEstate\Listing;
use App\Models\RealEstate\Reservation;
use App\Models\RealEstate\Unit;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\CommissionService;
use App\Services\ExchangeRateService;
use App\Services\FirebaseMessagingService;
use App\Services\KPayCatalog;
use App\Services\MobileMoneyGateway;
use App\Services\PaymentMethodService;
use App\Services\StripeService;
use App\Services\WalletService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Réservation d'une maison (avance de loyer) ou acompte sur un terrain.
 *
 * Au paiement :
 *  - la part ASSO (10 %) et la commission cabinet (5 %) sont créditées
 *    séparément et ne sont jamais remboursées ;
 *  - la part du bailleur (ou du cabinet) est créditée sur son Wallet puis
 *    bloquée, le temps de la visite ;
 *  - une place est retirée au logement, contrat et facture sont générés.
 * La part bloquée est débloquée à la confirmation du locataire, ou à la fin
 * du délai sans litige (commande immo:auto-release).
 *
 * Paiement calqué sur PackageSubscriptionService (Wallet, Mobile Money, carte).
 */
class RealEstateReservationService
{
    public const DOCS_DISK = 'local';

    /** Nouvelle présentation : les anciennes factures sont régénérées à la demande. */
    public const INVOICE_FILENAME = 'facture-v3.pdf';

    public const CONTRACT_FILENAME = 'contrat-v3.pdf';

    public function __construct(private RealEstatePricingService $pricing) {}

    /**
     * Contrôles avant paiement ; lance une exception au message affichable.
     */
    public function assertBookable(User $tenant, Listing $listing, ?Unit $unit, array $data): void
    {
        if ($listing->isSale() || $listing->status !== Listing::STATUS_PUBLISHED) {
            throw new \DomainException(__('real_estate.reservation.not_available'));
        }
        if ($listing->owner_id === $tenant->id || $this->pricing->commissionCabinet($listing)?->user_id === $tenant->id) {
            throw new \DomainException(__('real_estate.reservation.own_listing'));
        }
        if ($listing->units()->exists()) {
            if (! $unit || $unit->listing_id !== $listing->id) {
                throw new \DomainException(__('real_estate.reservation.unit_required'));
            }
            if ($unit->units_available <= 0) {
                throw new \DomainException(__('real_estate.reservation.unit_full'));
            }
        }
        if (! $listing->isLand() && $listing->min_lease_months && ! empty($data['months']) && $data['months'] < $listing->min_lease_months) {
            throw new \DomainException(__('real_estate.reservation.min_lease', ['months' => $listing->min_lease_months]));
        }
        if (! $this->payee($listing) || ! $this->pricing->commissionCabinet($listing)?->user) {
            throw new \DomainException(__('real_estate.reservation.not_available'));
        }
    }

    /** Bénéficiaire de la part bloquée : bailleur ou compte du cabinet. */
    public function payee(Listing $listing): ?User
    {
        return $listing->isManagedByCabinet()
            ? $listing->cabinet?->user
            : $listing->owner;
    }

    public function create(User $tenant, Listing $listing, ?Unit $unit, array $data, string $method): Reservation
    {
        $this->assertBookable($tenant, $listing, $unit, $data);
        $quote = $this->pricing->quote($listing, $unit, $data['advance_months'] ?? null);
        $partnerCabinet = $this->pricing->commissionCabinet($listing);

        return Reservation::create([
            'reference' => 'IMMO-'.now()->format('ymd').'-'.Str::upper(Str::random(5)),
            'listing_id' => $listing->id,
            'unit_id' => $unit?->id,
            'tenant_id' => $tenant->id,
            'payee_id' => $this->payee($listing)->id,
            'partner_cabinet_id' => $partnerCabinet->id,
            'partner_payee_id' => $partnerCabinet->user_id,
            'partner_cabinet_name' => $partnerCabinet->name,
            'net_payee_kind' => $listing->isManagedByCabinet() ? 'cabinet' : 'landlord',
            'kind' => $listing->type,
            'move_in_date' => $data['move_in_date'] ?? null,
            'months' => $data['months'] ?? null,
            'occupants' => $data['occupants'] ?? null,
            'applicant_status' => $data['applicant_status'] ?? null,
            'school_or_employer' => $data['school_or_employer'] ?? null,
            'message' => $data['message'] ?? null,
            'displayed_unit_price' => $quote['unit_price'],
            'base_amount' => $quote['base_amount'],
            'commission_amount' => $quote['commission'],
            'partner_commission_amount' => $quote['commission'],
            'deposit_percent' => $quote['deposit_percent'],
            'advance_months' => $quote['advance_months'],
            'owner_amount' => $quote['owner_amount'],
            'platform_amount' => $quote['platform_amount'],
            'total_amount' => $quote['total'],
            'payment_method' => $method,
            'status' => 'pending_payment',
            'contract_accepted_at' => now(),
        ]);
    }

    /** Paiement depuis le solde du Wallet : débit puis confirmation, tout ou rien. */
    public function payWithWallet(Reservation $reservation): Reservation
    {
        DB::transaction(function () use ($reservation) {
            $reservation = Reservation::whereKey($reservation->id)->lockForUpdate()->firstOrFail();
            if ($reservation->status !== 'pending_payment') {
                return;
            }
            app(WalletService::class)->debit(
                user: $reservation->tenant,
                amount: (float) $reservation->total_amount,
                description: WalletTransaction::label('immo_reservation_paid', ['reference' => $reservation->reference]),
                referenceType: 'real_estate_reservation',
                referenceId: $reservation->id,
            );
            $reservation->update(['payment_reference' => 'WALLET-'.$reservation->reference]);
            $this->confirm($reservation);
        });

        return $reservation->fresh();
    }

    /**
     * Initie Mobile Money ou carte. Renvoie les données Stripe (Payment Sheet)
     * ou null pour Mobile Money.
     */
    public function initiatePayment(Reservation $reservation, ?string $provider, ?string $phone): ?array
    {
        $total = (float) $reservation->total_amount;
        $externalRef = $reservation->reference;

        if ($reservation->payment_method === 'kpay_direct') {
            $currency = KPayCatalog::currencyForProvider($provider);
            $amount = (float) round($total);
            if ($currency !== 'XAF') {
                $converted = ExchangeRateService::convertAmount('XAF', $currency, $total);
                if ($converted === null) {
                    throw new \DomainException(__('payments.conversion_unavailable_retry', ['currency' => $currency]));
                }
                $amount = (float) round($converted);
            }
            $result = app(MobileMoneyGateway::class)->initializePayment([
                'amount' => $amount,
                'provider' => $provider,
                'phone_number' => $phone,
                'description' => "ASSO Immobilier {$reservation->reference}",
                'external_reference' => $externalRef,
            ]);
            if (empty($result['success'])) {
                throw new \DomainException($result['message'] ?? __('payments.kpay_init_failed'));
            }
            $reservation->update(['payment_reference' => $result['id'] ?? $externalRef]);

            return null;
        }

        if ($reservation->payment_method === 'stripe_direct') {
            $stripe = app(StripeService::class);
            if (! $stripe->isConfigured()) {
                throw new \DomainException(__('payments.card_temporarily_unavailable'));
            }
            $currency = PaymentMethodService::currencyFor('stripe') ?? 'USD';
            $amount = strtoupper($currency) === 'XAF' ? (float) round($total) : ExchangeRateService::convertAmount('XAF', $currency, $total);
            if ($amount === null) {
                throw new \DomainException(__('payments.card_conversion_unavailable', ['currency' => $currency]));
            }
            $intent = $stripe->createPaymentIntent((float) $amount, $currency, [
                'asso_kind' => 'real_estate_reservation',
                'reservation_id' => (string) $reservation->id,
            ]);
            if (empty($intent['id']) || empty($intent['client_secret'])) {
                throw new \DomainException(__('payments.stripe_init_failed'));
            }
            $reservation->update(['payment_reference' => $intent['id']]);

            return [
                'client_secret' => $intent['client_secret'],
                'payment_intent_id' => $intent['id'],
                'publishable_key' => $intent['publishable_key'] ?? null,
            ];
        }

        return null;
    }

    /** Relit le paiement chez le prestataire (polling, filet de sécurité). */
    public function checkAndConfirm(Reservation $reservation): void
    {
        if ($reservation->status !== 'pending_payment' || ! $reservation->payment_reference) {
            return;
        }

        if ($reservation->payment_method === 'kpay_direct') {
            $result = app(MobileMoneyGateway::class)->checkPaymentStatus($reservation->payment_reference);
            $status = strtoupper($result['status'] ?? 'UNKNOWN');
            if (in_array($status, ['SUCCESS', 'SUCCESSFUL', 'COMPLETED'], true)) {
                $this->confirm($reservation);
            } elseif (in_array($status, ['FAILED', 'FAILURE', 'REJECTED', 'CANCELLED', 'CANCELED'], true)) {
                $this->fail($reservation);
            }
        } elseif ($reservation->payment_method === 'stripe_direct') {
            try {
                $intent = app(StripeService::class)->retrievePaymentIntent($reservation->payment_reference);
            } catch (\Throwable $e) {
                Log::warning('[IMMO] Lecture PaymentIntent impossible: '.$e->getMessage());

                return;
            }
            $status = strtolower($intent['status'] ?? '');
            if ($status === 'succeeded') {
                $this->confirm($reservation);
            } elseif ($status === 'canceled') {
                $this->fail($reservation);
            }
        }
    }

    /**
     * Paiement encaissé (idempotent) : part ASSO acquise, part bailleur créditée
     * puis bloquée, place retirée, contrat et facture, notifications.
     */
    public function confirm(Reservation $reservation): void
    {
        $confirmed = false;
        $soldOut = false;

        DB::transaction(function () use ($reservation, &$confirmed, &$soldOut) {
            $r = Reservation::whereKey($reservation->id)->lockForUpdate()->first();
            if (! $r || $r->status !== 'pending_payment') {
                return;
            }
            $wallet = app(WalletService::class);

            // Le dernier logement a pu partir pendant le paiement Mobile Money :
            // l'argent est encaissé, on le rend en entier sur le Wallet.
            if ($r->unit_id) {
                $unit = Unit::whereKey($r->unit_id)->lockForUpdate()->first();
                if (! $unit || $unit->units_available <= 0) {
                    $wallet->credit($r->tenant, (float) $r->total_amount, null,
                        WalletTransaction::label('immo_reservation_refunded', ['reference' => $r->reference]),
                        ['reference_type' => 'real_estate_reservation', 'reference_id' => $r->id]);
                    $r->update(['status' => 'refunded', 'paid_at' => now()]);
                    $soldOut = true;

                    return;
                }
                $unit->decrement('units_available');
            }

            $listing = $r->listing;
            $days = (int) RealEstatePricingService::setting($listing->isLand() ? 'immo_land_release_days' : 'immo_visit_window_days');

            if ($r->platform_amount > 0) {
                $platform = CommissionService::platformAccount();
                if (! $platform) {
                    throw new \RuntimeException('The ASSO platform wallet is not configured.');
                }
                $wallet->credit($platform, (float) $r->platform_amount, null,
                    WalletTransaction::label($listing->isLand() ? 'asso_commission_land' : 'asso_commission_rent', ['reference' => $r->reference]),
                    ['reference_type' => 'real_estate_reservation', 'reference_id' => $r->id]);
            }

            if ($r->partner_commission_amount > 0) {
                $partner = $r->partnerPayee;
                if (! $partner) {
                    throw new \RuntimeException('The real estate partner wallet is no longer available.');
                }
                $wallet->credit($partner, (float) $r->partner_commission_amount, null,
                    WalletTransaction::label('immo_partner_commission', ['reference' => $r->reference]),
                    ['reference_type' => 'real_estate_reservation', 'reference_id' => $r->id, 'component' => 'partner_commission']);
            }

            $payee = $r->payee;
            $wallet->credit($payee, (float) $r->owner_amount, null,
                WalletTransaction::label('immo_reservation_received', ['reference' => $r->reference]),
                ['reference_type' => 'real_estate_reservation', 'reference_id' => $r->id]);
            $wallet->lockFunds($payee, (float) $r->owner_amount,
                WalletTransaction::label('immo_funds_held', ['reference' => $r->reference]),
                'real_estate_reservation', $r->id);

            $r->update([
                'status' => 'paid',
                'paid_at' => now(),
                'visit_deadline_at' => now()->addDays($days),
            ]);
            $confirmed = true;
        });

        $reservation->refresh();
        if ($confirmed) {
            $this->generateDocuments($reservation);
            $this->notify($reservation->payee, 'real_estate_reservation_paid_owner', $reservation);
            $this->notify($reservation->tenant, 'real_estate_reservation_paid_tenant', $reservation);
        } elseif ($soldOut) {
            $this->notify($reservation->tenant, 'real_estate_reservation_sold_out', $reservation);
        }
    }

    public function fail(Reservation $reservation): void
    {
        Reservation::whereKey($reservation->id)->where('status', 'pending_payment')->update(['status' => 'cancelled']);
    }

    /** « Le logement est conforme » : la part du bailleur est débloquée. */
    public function confirmConformity(Reservation $reservation): void
    {
        $this->release($reservation, 'confirmed');
    }

    /** Fin du délai sans litige : déblocage automatique. */
    public function autoRelease(): int
    {
        $count = 0;
        Reservation::where('status', 'paid')
            ->whereNotNull('visit_deadline_at')
            ->where('visit_deadline_at', '<=', now())
            ->get()
            ->each(function (Reservation $r) use (&$count) {
                if ($this->release($r, 'auto')) {
                    $count++;
                }
            });

        return $count;
    }

    private function release(Reservation $reservation, string $how): bool
    {
        $released = DB::transaction(function () use ($reservation, $how) {
            $r = Reservation::whereKey($reservation->id)->lockForUpdate()->first();
            if (! $r || $r->status !== 'paid') {
                return false;
            }
            app(WalletService::class)->unlockFunds($r->payee, (float) $r->owner_amount,
                WalletTransaction::label('immo_funds_released', ['reference' => $r->reference]),
                'real_estate_reservation', $r->id);
            $r->update([
                'status' => 'released',
                'confirmed_at' => $how === 'confirmed' ? now() : $r->confirmed_at,
                'released_at' => now(),
            ]);

            return true;
        });

        if ($released) {
            $reservation->refresh();
            $this->notify($reservation->payee, 'real_estate_funds_released', $reservation);
        }

        return $released;
    }

    /** Contrat court et facture, en PDF, sur le disque privé. */
    public function generateDocuments(Reservation $reservation): void
    {
        $reservation->loadMissing(['listing.cabinet', 'listing.owner', 'unit', 'tenant', 'payee']);
        $dir = "real_estate/reservations/{$reservation->id}";
        $data = [
            'r' => $reservation,
            'listing' => $reservation->listing,
            'commissionRate' => $this->pricing->partnerCommissionRate(),
            'days' => (int) RealEstatePricingService::setting($reservation->kind === 'land' ? 'immo_land_release_days' : 'immo_visit_window_days'),
        ];

        try {
            $contractPath = $dir.'/'.self::CONTRACT_FILENAME;
            Storage::disk(self::DOCS_DISK)->put($contractPath, Pdf::loadView('pdf.real_estate_contract', $data)->output());
            $invoicePath = $dir.'/'.self::INVOICE_FILENAME;
            Storage::disk(self::DOCS_DISK)->put($invoicePath, Pdf::loadView('pdf.real_estate_invoice', $data)->output());
            $reservation->update(['contract_path' => $contractPath, 'invoice_path' => $invoicePath]);
        } catch (\Throwable $e) {
            Log::error('[IMMO] Documents non générés', ['reservation' => $reservation->id, 'error' => $e->getMessage()]);
        }
    }

    private function notify(?User $user, string $key, Reservation $r): void
    {
        if (! $user) {
            return;
        }
        $replace = [
            'title' => $r->listing?->title,
            'reference' => $r->reference,
            'amount' => number_format((float) $r->owner_amount, 0, ',', ' '),
            'days' => $r->visit_deadline_at ? max(1, (int) ceil(now()->diffInDays($r->visit_deadline_at))) : '',
        ];
        try {
            app(FirebaseMessagingService::class)->sendToUser(
                $user,
                $user->localized("notifications.$key.title", $replace),
                $user->localized("notifications.$key.body", $replace),
                ['type' => $key, 'action' => 'open_real_estate_reservation', 'reservation_id' => $r->id],
            );
        } catch (\Throwable $e) {
            Log::error('[IMMO] Notification non envoyée', ['key' => $key, 'error' => $e->getMessage()]);
        }
    }
}
