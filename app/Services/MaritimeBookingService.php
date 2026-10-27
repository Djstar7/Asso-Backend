<?php

namespace App\Services;

use App\Models\MaritimeBooking;
use App\Models\MaritimeDeparture;
use App\Models\ParcelNature;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Cycle d'un envoi maritime : réservation + encaissement (KPay / Stripe),
 * confirmation ou échec du paiement, étapes enregistrées par l'admin,
 * clôture au code remis par le destinataire, remboursement si le colis
 * n'est pas déposé à temps.
 *
 * Règlement à la clôture : le fret va au Wallet du compte interne du
 * partenaire (PartnerAccountService, retiré pour lui depuis sa fiche admin,
 * comme pour les partenaires de livraison) ; commission et assurance vont au
 * compte plateforme ASSO.
 */
class MaritimeBookingService
{
    public function __construct(private FirebaseMessagingService $fcm) {}

    // ------------------------------------------------------------------
    // Réservation
    // ------------------------------------------------------------------

    /**
     * Crée la réservation et lance l'encaissement.
     *
     * @return array{booking: MaritimeBooking, payment: array, message: string}
     *
     * @throws MaritimeBookingException refus métier (422)
     */
    public function book(User $sender, int $departureId, array $data): array
    {
        return DB::transaction(function () use ($sender, $departureId, $data) {
            $departure = MaritimeDeparture::with(['originHub', 'destinationHub', 'company'])->lockForUpdate()->findOrFail($departureId);

            if (isset($data['volume_cbm'])) {
                if (($departure->freight_rate_snapshot['transport_mode'] ?? 'sea') !== 'sea') {
                    throw new MaritimeBookingException('Le fret aérien se réserve en kilogrammes.', 'kg_booked');
                }
                $data['kg_booked'] = round((float) $data['volume_cbm'] * MaritimeDeparture::KG_PER_CBM, 2);
            }

            if (! $departure->isBookable()) {
                throw new MaritimeBookingException(__('maritime.trip_impossible'));
            }
            if ((float) $departure->remaining_kg < (float) $data['kg_booked']) {
                throw new MaritimeBookingException(
                    ($departure->freight_rate_snapshot['transport_mode'] ?? 'sea') === 'sea'
                        ? 'Volume disponible : '.((float) $departure->remaining_kg / MaritimeDeparture::KG_PER_CBM).' CBM.'
                        : __('maritime.not_enough_kg', ['kg' => (float) $departure->remaining_kg]),
                    ($departure->freight_rate_snapshot['transport_mode'] ?? 'sea') === 'sea' ? 'volume_cbm' : 'kg_booked'
                );
            }

            if (strtoupper($data['recipient_country']) !== $departure->destinationHub->country) {
                throw new MaritimeBookingException(__('transport.destination_country_mismatch'), 'recipient_country');
            }

            $currency = strtoupper($departure->currency);
            if ($departure->requiresQuote() && empty($data['freight_inquiry_id'])) {
                throw new MaritimeBookingException('Un devis accepté est requis pour le fret maritime Chine → autres pays.');
            }
            // Départ au m³ : kilos convertis en m³, puis tarif au m³ (voir MaritimeDeparture).
            $freightSubtotal = $departure->partnerFreightFor((float) $data['kg_booked']);
            $freight = $departure->publicFreightFor((float) $data['kg_booked']);
            $inquiry = null;
            if (! empty($data['freight_inquiry_id'])) {
                $inquiry = \App\Models\FreightInquiry::where('user_id', $sender->id)->lockForUpdate()->findOrFail($data['freight_inquiry_id']);
                if ($inquiry->status !== 'accepted' || ! $inquiry->expires_at?->isFuture()
                    || $inquiry->maritime_departure_id !== $departure->id
                    || round($inquiry->kg, 2) !== round((float) $data['kg_booked'], 2)
                    || $inquiry->currency !== $currency || $inquiry->maritime_booking_id) {
                    throw new MaritimeBookingException('Le devis doit être accepté, valide et correspondre au départ et à la quantité réservée.');
                }
                if ($inquiry->departure_snapshot != $departure->quoteIdentity()) {
                    throw new MaritimeBookingException('Les conditions du départ ont changé. Contactez ASSO pour un nouveau devis.');
                }
                $freightSubtotal = $inquiry->partner_amount;
                $freight = $inquiry->freight_amount;
            }
            $commission = round($freight - $freightSubtotal, 2);

            try {
                // Valeur saisie dans la devise de l'expéditeur, ramenée à celle du départ.
                $declaredValue = ParcelInsuranceService::declaredValueIn(
                    (float) $data['declared_value'], $data['declared_value_currency'] ?? null, $currency
                );
                $nature = ParcelNature::findOrFail($data['parcel_nature_id']);
                $insuranceQuote = ParcelInsuranceService::quote($nature, $declaredValue);
            } catch (\RuntimeException $e) {
                throw new MaritimeBookingException($e->getMessage());
            }
            if ($declaredValue <= 0) {
                throw new MaritimeBookingException(__('validation.gt.numeric', ['attribute' => 'declared_value', 'value' => 0]));
            }
            $insuranceOptIn = (bool) ($data['insurance_opt_in'] ?? false);
            $insurance = $insuranceOptIn ? $insuranceQuote : 0.0;
            $total = round($freight + $insurance, 2);

            $method = $data['payment_method'];
            $totalPivot = $currency === PaymentMethodService::PIVOT
                ? $total
                : ExchangeRateService::convertAmount($currency, PaymentMethodService::PIVOT, $total);
            if ($totalPivot === null) {
                throw new MaritimeBookingException(__('payments.rate_unavailable', ['currency' => $currency, 'pivot' => PaymentMethodService::PIVOT]));
            }
            if ($method !== 'wallet' && $totalPivot < PaymentMethodService::minPivotFor($method)) {
                throw new MaritimeBookingException(__('payments.amount_too_low_for_method'));
            }

            $booking = MaritimeBooking::create([
                'maritime_departure_id' => $departure->id,
                'sender_user_id' => $sender->id,
                'kg_booked' => $data['kg_booked'],
                'volume_cbm' => ($departure->freight_rate_snapshot['transport_mode'] ?? 'sea') === 'sea' ? (float) $data['kg_booked'] / MaritimeDeparture::KG_PER_CBM : null,
                'parcels_count' => $data['parcels_count'] ?? 1,
                'sender_first_name' => $data['sender_first_name'],
                'sender_last_name' => $data['sender_last_name'],
                'sender_phone' => $data['sender_phone'],
                'emergency_phone' => $data['emergency_phone'],
                'recipient_first_name' => $data['recipient_first_name'],
                'recipient_last_name' => $data['recipient_last_name'],
                'recipient_country' => strtoupper($data['recipient_country']),
                'recipient_phone' => $data['recipient_phone'],
                'parcel_nature_id' => $data['parcel_nature_id'],
                'declared_value' => $declaredValue,
                'insurance_opt_in' => $insuranceOptIn,
                'insurance_rate_applied' => (float) $nature->insurance_rate,
                'insurance_quote_amount' => $insuranceQuote,
                'insurance_status' => $insuranceOptIn ? 'pending_payment' : 'not_opted_in',
                'insurance_amount' => 0,
                'price_per_kg' => $departure->price_per_kg,
                'freight_subtotal' => $freightSubtotal,
                'commission_amount' => $commission,
                'total_price' => $total,
                'currency' => $currency,
                'partner_freight_payment_timing' => $departure->freight_rate_snapshot['freight_payment_timing'] ?? 'completion',
                'dropoff_deadline' => $departure->dropoffDeadline(),
                'confirmation_code' => str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT),
                'status' => MaritimeBooking::STATUS_PENDING,
                'payment_status' => 'pending',
                'payment_method' => $method,
            ]);
            $this->recordBookingHistory(
                $booking,
                $insuranceOptIn ? 'insurance_opted_in' : 'insurance_opted_out',
                $booking->insurance_status,
                null,
                null,
                ['rate' => (float) $nature->insurance_rate, 'quote_amount' => $insuranceQuote]
            );

            $departure->decrement('remaining_kg', (float) $data['kg_booked']);
            $inquiry?->update(['status' => 'booked', 'maritime_booking_id' => $booking->id]);

            $payment = match ($method) {
                'wallet' => $this->payWithWallet($booking, $sender, round($totalPivot, 2)),
                'kpay' => $this->initKpay($booking, $data),
                'stripe' => $this->initStripe($booking),
            };

            return [
                // Payé au Wallet : relu pour renvoyer le statut « payé ».
                'booking' => $booking->fresh(),
                'payment' => $payment,
                'message' => match ($method) {
                    'wallet' => __('maritime.booking_paid_with_wallet'),
                    'kpay' => __('maritime.booking_created_confirm_on_phone'),
                    default => __('maritime.booking_created_complete_card'),
                },
            ];
        });
    }

    /** Paiement par le solde Wallet ASSO (XAF) : débit puis envoi payé immédiatement. */
    private function payWithWallet(MaritimeBooking $booking, User $sender, float $amountXaf): array
    {
        try {
            $tx = app(WalletService::class)->debit(
                user: $sender,
                amount: $amountXaf,
                description: WalletTransaction::label('maritime_payment', ['booking' => $booking->id]),
                referenceType: 'maritime_booking',
                referenceId: $booking->id,
                metadata: ['maritime_booking_id' => $booking->id],
                provider: 'kpay'
            );
        } catch (\Exception $e) {
            // Solde insuffisant : la transaction englobante annule l'envoi.
            throw new MaritimeBookingException(__('payments.wallet_insufficient'));
        }

        $booking->update(['payment_reference' => "WALLET-{$tx->id}"]);
        $this->confirmPayment($booking);

        return ['flow' => 'wallet', 'method' => 'wallet', 'reference' => $booking->payment_reference];
    }

    private function initKpay(MaritimeBooking $booking, array $data): array
    {
        $payCurrency = KPayCatalog::currencyForProvider($data['provider']);
        $payAmount = $booking->currency === $payCurrency
            ? (float) $booking->total_price
            : ExchangeRateService::convertAmount($booking->currency, $payCurrency, (float) $booking->total_price);
        if ($payAmount === null) {
            throw new MaritimeBookingException(__('payments.rate_unavailable', ['currency' => $booking->currency, 'pivot' => $payCurrency]));
        }

        $result = app(MobileMoneyGateway::class)->initializePayment([
            'amount' => (float) round($payAmount),
            'provider' => $data['provider'],
            'phone_number' => $data['phone_number'],
            'description' => "Envoi maritime #{$booking->id}",
            'external_reference' => "MARITIME-{$booking->id}",
        ]);
        if (empty($result['success'])) {
            throw new \Exception($result['message'] ?? __('payments.kpay_init_failed'));
        }

        $booking->update(['payment_reference' => $result['id'] ?? null]);

        return ['flow' => 'phone', 'method' => 'kpay', 'reference' => $booking->payment_reference];
    }

    private function initStripe(MaritimeBooking $booking): array
    {
        $stripe = new StripeService;
        if (! $stripe->isConfigured()) {
            throw new MaritimeBookingException(__('payments.card_temporarily_unavailable'));
        }

        $currency = PaymentMethodService::currencyFor('stripe') ?? 'USD';
        $amount = $booking->currency === strtoupper($currency)
            ? (float) $booking->total_price
            : ExchangeRateService::convertAmount($booking->currency, $currency, (float) $booking->total_price);
        if ($amount === null) {
            throw new MaritimeBookingException(__('payments.card_currency_conversion_unavailable'));
        }

        $intent = $stripe->createPaymentIntent(
            (float) $amount,
            $currency,
            ['asso_kind' => 'maritime_booking', 'booking_id' => (string) $booking->id]
        );
        if (empty($intent['id']) || empty($intent['client_secret'])) {
            throw new \Exception(__('payments.stripe_init_failed'));
        }

        $booking->update(['payment_reference' => $intent['id']]);

        return [
            'flow' => 'card',
            'method' => 'stripe',
            'reference' => $intent['id'],
            'client_secret' => $intent['client_secret'],
            'payment_intent_id' => $intent['id'],
            'publishable_key' => $intent['publishable_key'] ?? null,
            'amount' => round((float) $amount, 2),
            'currency' => strtoupper($currency),
        ];
    }

    // ------------------------------------------------------------------
    // Paiement
    // ------------------------------------------------------------------

    /** Re-vérifie un paiement en attente auprès de son rail (polling de l'app). */
    public function syncPayment(MaritimeBooking $booking): void
    {
        if ($booking->payment_status !== 'pending' || ! $booking->payment_reference || $booking->status !== MaritimeBooking::STATUS_PENDING) {
            return;
        }

        if ($booking->payment_method === 'stripe') {
            try {
                $intent = (new StripeService)->retrievePaymentIntent($booking->payment_reference);
            } catch (\Throwable $e) {
                Log::warning('[Maritime] Stripe retrieve PaymentIntent: '.$e->getMessage());

                return;
            }
            $status = strtolower($intent['status'] ?? '');
            if ($status === 'succeeded') {
                $this->confirmPayment($booking);
            } elseif ($status === 'canceled') {
                $this->failPayment($booking);
            }

            return;
        }

        $result = app(MobileMoneyGateway::class)->checkPaymentStatus($booking->payment_reference);
        $status = strtoupper($result['status'] ?? 'UNKNOWN');
        if (in_array($status, ['SUCCESS', 'SUCCESSFUL', 'COMPLETED'])) {
            $this->confirmPayment($booking);
        } elseif (in_array($status, ['FAILED', 'FAILURE', 'REJECTED', 'CANCELLED', 'CANCELED'])) {
            $this->failPayment($booking);
        }
    }

    /** Paiement confirmé (idempotent) : expéditeur et équipe ASSO prévenus. */
    public function confirmPayment(MaritimeBooking $booking): void
    {
        $confirmed = DB::transaction(function () use ($booking) {
            $b = MaritimeBooking::whereKey($booking->id)->lockForUpdate()->first();
            if (! $b || $b->payment_status !== 'pending' || $b->status !== MaritimeBooking::STATUS_PENDING) {
                return false;
            }
            $insuranceStatus = $b->insurance_opt_in ? 'active' : 'not_opted_in';
            $b->update([
                'payment_status' => 'completed',
                'status' => MaritimeBooking::STATUS_PAID,
                'paid_at' => now(),
                'insurance_status' => $insuranceStatus,
                'insurance_amount' => $b->insurance_opt_in ? $b->insurance_quote_amount : 0,
            ]);
            $this->recordBookingHistory($b, 'payment_completed', $insuranceStatus, null, null, [
                'payment_method' => $b->payment_method,
                'insurance_amount' => (float) $b->insurance_amount,
            ]);

            return true;
        });
        if (! $confirmed) {
            return;
        }

        $b = $booking->fresh(['departure.originHub', 'sender']);
        $hub = $b->departure->originHub;
        $this->notifySender($b, 'maritime_booking_paid', [
            'hub' => "{$hub->name}, {$hub->address}, {$hub->city}",
            'date' => $b->dropoff_deadline->format('d/m/Y'),
        ]);
        $this->notifyStaff($b);
    }

    /** Échec de paiement : annule et rend les kilos (idempotent). */
    public function failPayment(MaritimeBooking $booking): void
    {
        DB::transaction(function () use ($booking) {
            $b = MaritimeBooking::whereKey($booking->id)->lockForUpdate()->first();
            if (! $b || $b->payment_status !== 'pending' || $b->status !== MaritimeBooking::STATUS_PENDING) {
                return;
            }
            $insuranceStatus = $b->insurance_opt_in ? 'payment_failed' : 'not_opted_in';
            $b->update([
                'status' => MaritimeBooking::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'cancel_reason' => 'payment_failed',
                'insurance_status' => $insuranceStatus,
            ]);
            $this->recordBookingHistory($b, 'payment_failed', $insuranceStatus, null, 'Payment failed.');
            MaritimeDeparture::whereKey($b->maritime_departure_id)->increment('remaining_kg', (float) $b->kg_booked);
        });
    }

    // ------------------------------------------------------------------
    // Annulation et remboursement
    // ------------------------------------------------------------------

    /**
     * Annulation par l'expéditeur, avant le dépôt du colis : remboursement
     * intégral au Wallet (XAF), kilos rendus au départ.
     */
    public function cancelBySender(MaritimeBooking $booking, ?string $reason = null): void
    {
        DB::transaction(function () use ($booking, $reason) {
            $b = MaritimeBooking::whereKey($booking->id)->lockForUpdate()->first();
            if (! in_array($b->status, [MaritimeBooking::STATUS_PENDING, MaritimeBooking::STATUS_PAID], true)) {
                throw new MaritimeBookingException(__('maritime.cannot_cancel'));
            }

            if ($b->payment_status === 'completed') {
                $this->refund($b, (float) $b->total_price);
            }
            $b->status = MaritimeBooking::STATUS_CANCELLED;
            $b->cancel_reason = $reason ?: 'cancelled_by_sender';
            $b->cancelled_at = now();
            if ($b->insurance_opt_in) {
                $b->insurance_status = 'refunded';
            }
            $b->save();
            $this->recordBookingHistory($b, 'booking_cancelled', $b->insurance_status, null, $b->cancel_reason);

            MaritimeDeparture::whereKey($b->maritime_departure_id)->increment('remaining_kg', (float) $b->kg_booked);
        });
    }

    /**
     * Colis non déposé avant la date limite : remboursement du total moins la
     * commission ASSO. Renvoie le nombre d'envois remboursés.
     */
    public function refundMissedDropoffs(): int
    {
        $count = 0;
        $ids = MaritimeBooking::where('status', MaritimeBooking::STATUS_PAID)
            ->whereDate('dropoff_deadline', '<', today())
            ->pluck('id');

        foreach ($ids as $id) {
            try {
                $done = DB::transaction(function () use ($id) {
                    $b = MaritimeBooking::whereKey($id)->lockForUpdate()->first();
                    if (! $b || $b->status !== MaritimeBooking::STATUS_PAID || $b->payment_status !== 'completed') {
                        return null;
                    }
                    $refundable = round((float) $b->total_price - (float) $b->commission_amount, 2);
                    $this->refund($b, $refundable);
                    $b->status = MaritimeBooking::STATUS_CANCELLED;
                    $b->cancel_reason = 'dropoff_missed';
                    $b->cancelled_at = now();
                    if ($b->insurance_opt_in) {
                        $b->insurance_status = 'refunded';
                    }
                    $b->save();
                    $this->recordBookingHistory($b, 'booking_cancelled', $b->insurance_status, null, $b->cancel_reason);

                    return $b;
                });
            } catch (\RuntimeException $e) {
                // Sans taux de change, on réessaiera au prochain passage.
                Log::error('[Maritime] Remboursement impossible', ['booking_id' => $id, 'error' => $e->getMessage()]);

                continue;
            }

            if ($done) {
                $count++;
                $this->notifySender($done, 'maritime_dropoff_missed', [
                    'amount' => number_format((float) $done->refunded_amount, 0, ',', ' ').' FCFA',
                ]);
            }
        }

        return $count;
    }

    /** Crédite le Wallet de l'expéditeur (montant dans la devise du départ, converti en XAF). */
    private function refund(MaritimeBooking $b, float $amount): void
    {
        $xaf = $this->toWalletCurrency($b->currency, $amount);
        if ($xaf > 0) {
            app(WalletService::class)->credit(
                User::findOrFail($b->sender_user_id),
                $xaf,
                null,
                WalletTransaction::label('maritime_refund', ['booking' => $b->id]),
                ['maritime_booking_id' => $b->id, 'currency' => $b->currency, 'amount' => $amount],
                'kpay'
            );
        }
        $b->payment_status = 'refunded';
        $b->refunded_amount = $xaf;
        $b->refunded_at = now();
    }

    // ------------------------------------------------------------------
    // Étapes et clôture (admin)
    // ------------------------------------------------------------------

    /** Passe à l'étape suivante (dépôt, embarquement, arrivée) et prévient l'expéditeur. */
    public function advance(MaritimeBooking $booking): MaritimeBooking
    {
        $b = DB::transaction(function () use ($booking) {
            $b = MaritimeBooking::whereKey($booking->id)->lockForUpdate()->first();
            $next = MaritimeBooking::NEXT_STEP[$b->status] ?? null;
            if (! $next || $b->payment_status !== 'completed') {
                throw new MaritimeBookingException('Aucune étape suivante pour ce statut.');
            }
            $b->status = $next;
            if (($next === MaritimeBooking::STATUS_ARRIVED && $b->partner_freight_payment_timing === 'arrival')
                || ($next === MaritimeBooking::STATUS_SHIPPED && $b->partner_freight_payment_timing === 'departure')) {
                $this->settlePartner($b);
            }
            $b->{"{$next}_at"} = now();
            $b->save();
            $this->recordBookingHistory($b, 'booking_status', $next, null, null);

            return $b;
        });

        $b->load('departure.destinationHub');
        $hub = $b->departure->destinationHub;
        $this->notifySender($b, "maritime_{$b->status}", [
            'hub' => "{$hub->name}, {$hub->city}",
        ]);

        return $b;
    }

    /**
     * Code remis par le destinataire au QG d'arrivée : clôture et règlement.
     *
     * @throws MaritimeBookingException code faux ou mauvais statut
     */
    public function complete(MaritimeBooking $booking, string $code): MaritimeBooking
    {
        if (! hash_equals($booking->confirmation_code, $code)) {
            throw new MaritimeBookingException(__('maritime.code_incorrect'));
        }

        try {
            $b = DB::transaction(function () use ($booking) {
                $b = MaritimeBooking::whereKey($booking->id)->lockForUpdate()->first();
                if ($b->status !== MaritimeBooking::STATUS_ARRIVED || $b->payment_status !== 'completed') {
                    throw new MaritimeBookingException(__('maritime.not_arrived_yet'));
                }

                $this->settle($b);
                $b->update(['status' => MaritimeBooking::STATUS_COMPLETED, 'completed_at' => now()]);

                return $b;
            });
        } catch (\RuntimeException $e) {
            if ($e instanceof MaritimeBookingException) {
                throw $e;
            }
            Log::error('[Maritime] Règlement impossible', ['booking_id' => $booking->id, 'error' => $e->getMessage()]);
            throw new MaritimeBookingException(__('maritime.settlement_unavailable'));
        }

        $this->notifySender($b, 'maritime_completed', []);

        return $b;
    }

    /** Fret au Wallet du partenaire ; commission et assurance au compte ASSO. */
    private function settle(MaritimeBooking $b): void
    {
        $wallet = app(WalletService::class);
        $metadata = [
            'maritime_booking_id' => $b->id,
            'direct_settlement' => true,
            'currency' => $b->currency,
            'freight_subtotal' => (float) $b->freight_subtotal,
            'commission_amount' => (float) $b->commission_amount,
            'insurance_amount' => (float) $b->insurance_amount,
        ];

        $commission = $this->toWalletCurrency($b->currency, (float) $b->commission_amount);
        $insurance = $this->toWalletCurrency($b->currency, (float) $b->insurance_amount);

        $this->settlePartner($b);

        $platform = CommissionService::platformAccount();
        if (! $platform) {
            Log::warning('[Maritime] Compte plateforme ASSO introuvable, commission non créditée', ['booking_id' => $b->id]);

            return;
        }
        if ($commission > 0) {
            $wallet->credit($platform, $commission, null, WalletTransaction::label('asso_commission_maritime', ['booking' => $b->id]), $metadata, 'kpay');
        }
        if ($insurance > 0) {
            $wallet->credit($platform, $insurance, null, WalletTransaction::label('asso_parcel_insurance', ['reference' => "Maritime #{$b->id}"]), $metadata, 'kpay');
        }
    }

    /** Appelé uniquement dans la transaction qui verrouille l’envoi. */
    private function settlePartner(MaritimeBooking $booking): void
    {
        if ($booking->partner_freight_paid_at !== null) {
            return;
        }
        $amount = $this->toWalletCurrency($booking->currency, (float) $booking->freight_subtotal);
        PartnerAccountService::credit(
            (int) MaritimeDeparture::whereKey($booking->maritime_departure_id)->value('deliverer_company_id'),
            $amount, WalletTransaction::label('maritime_freight', ['booking' => $booking->id]),
            'maritime_booking', $booking->id,
            ['maritime_booking_id' => $booking->id, 'currency' => $booking->currency, 'freight_subtotal' => (float) $booking->freight_subtotal, 'timing' => $booking->partner_freight_payment_timing]
        );
        $booking->forceFill(['partner_freight_paid_at' => now(), 'partner_freight_paid_xaf' => $amount])->save();
        $this->recordBookingHistory($booking, 'partner_freight_paid', $booking->status, null, null, ['amount_xaf' => $amount]);
    }

    private function toWalletCurrency(string $currency, float $amount): float
    {
        if ($amount <= 0) {
            return 0.0;
        }
        $converted = ExchangeRateService::convertAmount($currency, PaymentMethodService::PIVOT, $amount);
        if ($converted === null) {
            throw new \RuntimeException(__('payments.rate_unavailable', ['currency' => $currency, 'pivot' => PaymentMethodService::PIVOT]));
        }

        return round($converted, 2);
    }

    // ------------------------------------------------------------------
    // Notifications
    // ------------------------------------------------------------------

    private function notifySender(MaritimeBooking $b, string $type, array $replace): void
    {
        $sender = User::find($b->sender_user_id);
        if (! $sender) {
            return;
        }

        try {
            $this->fcm->sendToUser(
                $sender,
                $sender->localized("notifications.{$type}.title", $replace),
                $sender->localized("notifications.{$type}.body", $replace),
                ['type' => $type, 'maritime_booking_id' => (string) $b->id]
            );
        } catch (\Throwable $e) {
            Log::warning("[Maritime] FCM {$type}: ".$e->getMessage());
        }
    }

    private function recordBookingHistory(
        MaritimeBooking $booking,
        string $event,
        string $status,
        ?int $actorId,
        ?string $reason,
        array $metadata = []
    ): void {
        $booking->insuranceHistory()->create([
            'event' => $event,
            'status' => $status,
            'actor_user_id' => $actorId,
            'reason' => $reason,
            'metadata' => $metadata ?: null,
        ]);
    }

    /** Comptes back-office ayant accès à Diaspo : à contacter l'expéditeur après paiement. */
    public static function staff(): \Illuminate\Support\Collection
    {
        return User::whereIn('role', config('admin_access.staff_roles', []))
            ->get()
            ->filter(fn (User $user) => ! $user->isTechnicalAccount() && $user->hasAdminPermission('diaspo'))
            ->values();
    }

    private function notifyStaff(MaritimeBooking $b): void
    {
        $replace = [
            'name' => $b->senderName(),
            'phone' => $b->sender_phone,
            'kg' => (float) $b->kg_booked,
            'route' => $b->departure->originHub->city.' → '.($b->departure->destinationHub()->value('city') ?? ''),
        ];

        foreach (self::staff() as $employee) {
            $title = (string) $employee->localized('notifications.maritime_staff_paid.title', $replace);
            $body = (string) $employee->localized('notifications.maritime_staff_paid.body', $replace);
            try {
                $this->fcm->sendToUser($employee, $title, $body, [
                    'type' => 'maritime_staff_paid',
                    'role' => 'staff',
                    'maritime_booking_id' => (string) $b->id,
                ]);
            } catch (\Throwable $e) {
                Log::warning('[Maritime] FCM staff: '.$e->getMessage());
            }
            if (! $employee->email) {
                continue;
            }
            try {
                $url = route('admin.maritime.bookings.show', $b->id);
                Mail::raw("{$body}\n\n{$url}", fn ($m) => $m->to($employee->email)->subject($title));
            } catch (\Throwable $e) {
                Log::warning('[Maritime] e-mail staff: '.$e->getMessage());
            }
        }
    }
}
