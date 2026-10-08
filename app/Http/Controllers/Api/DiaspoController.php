<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DiaspoBooking;
use App\Models\DiaspoOffer;
use App\Models\DiaspoVerification;
use App\Models\Setting;
use App\Models\User;
use App\Services\CommissionService;
use App\Services\ExchangeRateService;
use App\Services\PaymentMethodService;
use App\Services\StripeService;
use App\Services\WalletService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Diaspo — partage de kg entre voyageurs.
 * Réservation payée par KPay direct (PayIn) ; les fonds sont séquestrés par la
 * plateforme puis libérés au voyageur à la confirmation de réception.
 */
class DiaspoController extends Controller
{

    private function paginate($query, Request $request, ?int $viewerId = null): array
    {
        $perPage = (int) $request->query('per_page', 20);
        $p = $query->paginate($perPage);
        return [
            'current_page' => $p->currentPage(),
            // $viewerId transmis à toApi : nécessaire pour masquer le code secret des
            // bookings aux non-acheteurs (ignoré par les modèles sans ce paramètre).
            'data' => collect($p->items())->map(fn($m) => $m->toApi($viewerId))->values(),
            'per_page' => $p->perPage(),
            'last_page' => $p->lastPage(),
            'total' => $p->total(),
            'next_page_url' => $p->nextPageUrl(),
            'prev_page_url' => $p->previousPageUrl(),
        ];
    }

    // ==================== VÉRIFICATION ====================

    public function verificationStatus(Request $request)
    {
        $v = DiaspoVerification::where('user_id', $request->user()->id)->first();
        $status = $v->status ?? 'unverified';
        return response()->json([
            'success' => true,
            'data' => [
                'verification_status' => $status,
                'can_create_offers' => $status === 'verified',
                'rejection_reason' => $v->rejection_reason ?? null,
            ],
        ]);
    }

    public function uploadVerification(Request $request)
    {
        $request->validate([
            'document_type' => 'nullable|string|in:cni,passport',
            'document_front' => 'nullable|file|image|max:5120',
            'document_back' => 'nullable|file|image|max:5120',
        ]);

        $user = $request->user();
        $data = ['status' => 'pending', 'document_type' => $request->input('document_type', 'cni')];

        if ($request->hasFile('document_front')) {
            $data['document_front'] = $request->file('document_front')->store('diaspo/verifications', 'public');
        }
        if ($request->hasFile('document_back')) {
            $data['document_back'] = $request->file('document_back')->store('diaspo/verifications', 'public');
        }

        DiaspoVerification::updateOrCreate(['user_id' => $user->id], $data);

        return response()->json([
            'success' => true,
            'message' => __('diaspo.documents_received'),
            'data' => ['verification_status' => 'pending', 'can_create_offers' => false],
        ]);
    }

    // ==================== OFFRES ====================

    public function offers(Request $request)
    {
        $q = DiaspoOffer::with('user')
            ->where('status', 'active')
            ->where('remaining_kg', '>', 0)
            ->where('departure_datetime', '>', now());

        foreach ([
            'departure_country', 'arrival_country', 'departure_city', 'arrival_city',
        ] as $f) {
            if ($request->filled($f)) {
                $q->where($f, 'ilike', '%' . $request->query($f) . '%');
            }
        }
        if ($request->filled('max_price')) {
            $q->where('price_per_kg', '<=', (float) $request->query('max_price'));
        }
        $q->orderBy('departure_datetime');

        return response()->json(['success' => true, 'data' => $this->paginate($q, $request)]);
    }

    public function myOffers(Request $request)
    {
        $q = DiaspoOffer::with('user')->where('user_id', $request->user()->id)->latest();
        return response()->json(['success' => true, 'data' => $this->paginate($q, $request)]);
    }

    public function showOffer($id)
    {
        $offer = DiaspoOffer::with('user')->findOrFail($id);
        $offer->increment('views_count');
        return response()->json(['success' => true, 'data' => $offer->toApi()]);
    }

    public function storeOffer(Request $request)
    {
        $user = $request->user();
        $verified = DiaspoVerification::where('user_id', $user->id)->where('status', 'verified')->exists();
        if (!$verified) {
            return response()->json(['success' => false, 'message' => __('diaspo.identity_required_to_create_offer')], 403);
        }

        $data = $request->validate([
            'departure_country' => 'required|string',
            'departure_city' => 'required|string',
            'departure_datetime' => 'required|date|after:now',
            'arrival_country' => 'required|string',
            'arrival_city' => 'required|string',
            'arrival_datetime' => 'required|date|after:departure_datetime',
            'price_per_kg' => 'required|numeric|min:0',
            'available_kg' => 'required|numeric|min:0.5',
            'currency' => 'nullable|string|size:3',
        ]);

        $offer = DiaspoOffer::create(array_merge($data, [
            'user_id' => $user->id,
            'status' => 'approved',
            'verification_status' => 'verified',
            'verified_at' => now(),
            'remaining_kg' => $data['available_kg'],
            'currency' => strtoupper($data['currency'] ?? 'XAF'),
        ]));

        return response()->json(['success' => true, 'message' => __('diaspo.offer_created'), 'data' => $offer->load('user')->toApi()], 201);
    }

    public function updateOffer(Request $request, $id)
    {
        $offer = DiaspoOffer::where('user_id', $request->user()->id)->findOrFail($id);
        $data = $request->validate([
            'price_per_kg' => 'sometimes|numeric|min:0',
            'available_kg' => 'sometimes|numeric|min:0.5',
            'status' => 'sometimes|in:active,closed,cancelled',
        ]);
        if (isset($data['available_kg'])) {
            $booked = (float) $offer->available_kg - (float) $offer->remaining_kg;
            $data['remaining_kg'] = max(0, $data['available_kg'] - $booked);
        }
        $offer->update($data);
        return response()->json(['success' => true, 'message' => __('diaspo.offer_updated'), 'data' => $offer->fresh()->load('user')->toApi()]);
    }

    public function destroyOffer(Request $request, $id)
    {
        $offer = DiaspoOffer::where('user_id', $request->user()->id)->findOrFail($id);
        if ($offer->bookings()->whereIn('status', ['paid', 'confirmed'])->exists()) {
            return response()->json(['success' => false, 'message' => __('diaspo.offer_delete_has_bookings')], 422);
        }
        $offer->delete();
        return response()->json(['success' => true, 'message' => __('diaspo.offer_deleted')]);
    }

    // ==================== RÉSERVATIONS ====================

    /**
     * POST /v1/diaspo/offers/{id}/book — crée une réservation + initie l'encaissement.
     *
     * Multi-rail : `payment_method` ∈ kpay | stripe. Chaque rail renvoie un
     * bloc `payment` décrivant le sous-parcours mobile à dérouler :
     *   - kpay     : { flow: 'phone' } → validation Mobile Money (provider + numéro)
     *   - stripe   : { flow: 'card' }  → Payment Sheet avec client_secret (SDK flutter_stripe)
     * Le suivi du paiement se fait ensuite via bookingPaymentStatus (polling) qui
     * re-vérifie le statut auprès du bon rail.
     */
    public function bookOffer(Request $request, $id)
    {
        $data = $request->validate([
            'kg_booked' => 'required|numeric|min:0.5',
            'payment_method' => 'required|in:kpay,stripe',
            'provider' => 'required_if:payment_method,kpay|string',       // code opérateur KPay
            'phone_number' => 'required_if:payment_method,kpay|string',   // numéro Mobile Money
            'notes' => 'nullable|string|max:500',
        ]);

        $buyer = $request->user();
        $method = $data['payment_method'];

        if (!PaymentMethodService::isEnabled($method)) {
            return response()->json(['success' => false, 'message' => __('payments.method_unavailable')], 422);
        }

        return DB::transaction(function () use ($id, $data, $buyer, $method) {
            $offer = DiaspoOffer::lockForUpdate()->findOrFail($id);

            if ($offer->user_id === $buyer->id) {
                return response()->json(['success' => false, 'message' => __('diaspo.cannot_book_own_offer')], 422);
            }
            // Offre réservable = vérifiée et publiée (schéma unifié : statut 'approved').
            if ($offer->status !== 'approved' || (float) $offer->remaining_kg < $data['kg_booked']) {
                return response()->json(['success' => false, 'message' => __('diaspo.offer_unavailable_or_insufficient_kg')], 422);
            }
            // « Profil non vérifié » : l'offre est visible mais aucun paiement n'est
            // séquestré tant que l'identité du voyageur n'est pas validée (elle peut
            // encore être retirée à l'échéance de régularisation).
            if ($offer->verification_status !== 'verified') {
                return response()->json(['success' => false, 'message' => __('diaspo.traveler_not_verified')], 422);
            }

            // Le voyageur touche son prix ; le client paie le prix public au kilo
            // (majoré de la commission ASSO, exactement celui affiché dans l'app).
            $subtotal = round($data['kg_booked'] * (float) $offer->price_per_kg, 2);
            $total = round($data['kg_booked'] * $offer->publicPricePerKg(), 2);
            $commission = round($total - $subtotal, 2);

            // Garde-fou serveur : le montant doit atteindre le minimum du moyen choisi
            // (comparaison dans la devise pivot XAF, via les taux stockés).
            $totalPivot = strtoupper($offer->currency) === PaymentMethodService::PIVOT
                ? $total
                : (ExchangeRateService::convertAmount($offer->currency, PaymentMethodService::PIVOT, $total) ?? $total);
            if ($totalPivot < PaymentMethodService::minPivotFor($method)) {
                return response()->json(['success' => false, 'message' => __('payments.amount_too_low_for_method')], 422);
            }

            $booking = DiaspoBooking::create([
                'diaspo_offer_id' => $offer->id,
                'buyer_user_id' => $buyer->id,
                'seller_user_id' => $offer->user_id,
                'kg_booked' => $data['kg_booked'],
                'price_per_kg' => $offer->price_per_kg,
                'subtotal' => $subtotal,
                'commission_amount' => $commission,
                'total_price' => $total,
                'currency' => $offer->currency,
                'status' => 'pending',
                'confirmation_code' => str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT),
                'payment_status' => 'pending',
                'payment_method' => $method,
                'notes' => $data['notes'] ?? null,
            ]);

            // Réserver les kg
            $offer->decrement('remaining_kg', $data['kg_booked']);
            $offer->increment('bookings_count');

            return match ($method) {
                'kpay' => $this->initKpayBooking($booking, $data, $total),
                'stripe' => $this->initStripeBooking($booking, $offer, $total),
            };
        });
    }

    /** Initie l'encaissement KPay (Mobile Money) et renvoie la réponse de réservation. */
    private function initKpayBooking(DiaspoBooking $booking, array $data, float $total)
    {
        $result = app(\App\Services\MobileMoneyGateway::class)->initializePayment([
            'amount' => (float) round($total),
            'provider' => $data['provider'],
            'phone_number' => $data['phone_number'],
            'description' => "Réservation diaspo #{$booking->id}",
            'external_reference' => "DIASPO-{$booking->id}",
        ]);

        if (empty($result['success'])) {
            throw new \Exception($result['message'] ?? __('payments.kpay_init_failed'));
        }

        $booking->update(['payment_reference' => $result['id'] ?? null]);

        return $this->bookingCreatedResponse($booking, __('diaspo.booking_created_confirm_on_phone'), [
            'flow' => 'phone',
            'method' => 'kpay',
            'reference' => $booking->payment_reference,
        ]);
    }

    /** Initie l'encaissement carte Stripe NATIF (PaymentIntent) — devise via config, taux stockés. */
    private function initStripeBooking(DiaspoBooking $booking, DiaspoOffer $offer, float $total)
    {
        $stripe = new StripeService();
        if (!$stripe->isConfigured()) {
            throw new \Exception(__('payments.card_temporarily_unavailable'));
        }

        $currency = PaymentMethodService::currencyFor('stripe') ?? 'USD';
        $amount = strtoupper($offer->currency) === strtoupper($currency)
            ? $total
            : ExchangeRateService::convertAmount($offer->currency, $currency, $total);
        if ($amount === null) {
            throw new \Exception(__('payments.card_currency_conversion_unavailable'));
        }

        // Carte NATIVE : PaymentIntent → client_secret confirmé côté mobile par la Payment
        // Sheet (SDK flutter_stripe). Pas de WebView. La confirmation serveur se fait au
        // polling (syncStripeBooking) et via le webhook payment_intent.succeeded.
        $intent = $stripe->createPaymentIntent(
            (float) $amount,
            $currency,
            ['asso_kind' => 'diaspo_booking', 'booking_id' => (string) $booking->id]
        );

        if (empty($intent['id']) || empty($intent['client_secret'])) {
            throw new \Exception(__('payments.stripe_init_failed'));
        }

        $booking->update(['payment_reference' => $intent['id']]);

        return $this->bookingCreatedResponse($booking, __('diaspo.booking_created_complete_card'), [
            'flow' => 'card',
            'method' => 'stripe',
            'reference' => $intent['id'],
            'client_secret' => $intent['client_secret'],
            'payment_intent_id' => $intent['id'],
            'publishable_key' => $intent['publishable_key'] ?? null,
            'amount' => round((float) $amount, 2),
            'currency' => strtoupper($currency),
        ]);
    }

    /** Réponse HTTP standard de création de réservation (tous rails). */
    private function bookingCreatedResponse(DiaspoBooking $booking, string $message, array $payment)
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            // Réponse à l'acheteur (créateur de la réservation) → code visible pour lui.
            'data' => $booking->load(['offer.user', 'buyer', 'seller'])->toApi($booking->buyer_user_id),
            'payment' => $payment,
            'payment_reference' => $booking->payment_reference,
            'booking_id' => $booking->id,
        ], 201);
    }

    /** GET /v1/diaspo/bookings/{id}/payment-status — re-vérifie KPay + confirme. */
    public function bookingPaymentStatus(Request $request, $id)
    {
        $booking = DiaspoBooking::where('id', $id)
            ->where('buyer_user_id', $request->user()->id)
            ->firstOrFail();

        if ($booking->payment_status === 'pending' && $booking->payment_reference) {
            $method = $booking->payment_method ?: 'kpay';

            if ($method === 'stripe') {
                $this->syncStripeBooking($booking);
            } else {
                $result = app(\App\Services\MobileMoneyGateway::class)->checkPaymentStatus($booking->payment_reference);
                $status = strtoupper($result['status'] ?? 'UNKNOWN');
                if (in_array($status, ['SUCCESS', 'SUCCESSFUL', 'COMPLETED'])) {
                    $this->confirmBookingPayment($booking);
                } elseif (in_array($status, ['FAILED', 'FAILURE', 'REJECTED', 'CANCELLED', 'CANCELED'])) {
                    $this->failBookingPayment($booking);
                }
            }
            $booking->refresh();
        }

        // Mobile Money refusé : motif de l'opérateur (wrong_network, insufficient_funds…).
        $paymentFailure = $booking->status === 'cancelled' && $booking->payment_status === 'pending'
            && ($booking->payment_method ?: 'kpay') !== 'stripe'
            ? app(\App\Services\MobileMoneyGateway::class)->failureFor($booking->payment_reference)
            : null;

        return response()->json([
            'success' => true,
            'data' => [
                'booking_id' => $booking->id,
                'payment_status' => $booking->payment_status,
                'status' => $booking->status,
                'payment_failure' => $paymentFailure,
            ],
        ]);
    }

    /** Confirme le paiement d'une réservation (idempotent) — fonds séquestrés. */
    public function confirmBookingPayment(DiaspoBooking $booking): void
    {
        DB::transaction(function () use ($booking) {
            $b = DiaspoBooking::whereKey($booking->id)->lockForUpdate()->first();
            // Schéma unifié (upstream) : payment_status ∈ pending|completed|refunded, status ∈
            // pending|paid|confirmed|cancelled|completed. Cycle : pending → paid (payé) →
            // confirmed (voyageur valide le code) → completed (réception).
            if (!$b || $b->payment_status === 'completed') return;
            $b->update(['payment_status' => 'completed', 'status' => 'paid', 'paid_at' => now()]);
        });
        try {
            $seller = User::find($booking->seller_user_id);
            if ($seller) {
                app(\App\Services\FirebaseMessagingService::class)->sendToUser(
                    $seller, $seller->translate('notifications.diaspo_booking_paid.title'),
                    $seller->translate('notifications.diaspo_booking_paid.body', ['kg' => $booking->kg_booked]),
                    ['type' => 'diaspo_booking_paid', 'booking_id' => (string) $booking->id]
                );
            }
        } catch (\Exception $e) {
            Log::warning('[Diaspo] FCM booking_paid: ' . $e->getMessage());
        }
    }

    /**
     * Échec de paiement : annule la réservation et libère les kg réservés (idempotent).
     * Appelé par le polling (bookingPaymentStatus) ET le webhook KPay (PaymentController).
     *
     * Le schéma unifié ne modélise pas d'état de paiement « échoué » (payment_status ∈
     * pending|completed|refunded). On laisse donc payment_status à 'pending' et on marque
     * la réservation 'cancelled' ; l'idempotence de la libération des kg s'appuie sur ce
     * statut (pas de double crédit de remaining_kg).
     */
    public function failBookingPayment(DiaspoBooking $booking): void
    {
        DB::transaction(function () use ($booking) {
            $b = DiaspoBooking::whereKey($booking->id)->lockForUpdate()->first();
            if (!$b || $b->payment_status !== 'pending' || $b->status === 'cancelled') return;
            $b->update(['status' => 'cancelled', 'cancelled_at' => now()]);
            DiaspoOffer::whereKey($b->diaspo_offer_id)->increment('remaining_kg', (float) $b->kg_booked);
        });
    }

    /**
     * Re-vérifie et finalise un paiement carte Stripe NATIF en attente (PaymentIntent).
     * `succeeded` → confirmé ; `canceled` → échec (libère les kg). Sinon on reste en attente.
     */
    private function syncStripeBooking(DiaspoBooking $booking): void
    {
        try {
            $intent = (new StripeService())->retrievePaymentIntent($booking->payment_reference);
        } catch (\Throwable $e) {
            Log::warning('[Diaspo] Stripe retrieve PaymentIntent: ' . $e->getMessage());
            return;
        }

        $status = strtolower($intent['status'] ?? '');
        if ($status === 'succeeded') {
            $this->confirmBookingPayment($booking);
        } elseif ($status === 'canceled') {
            $this->failBookingPayment($booking);
        }
    }

    public function bookings(Request $request)
    {
        $uid = $request->user()->id;
        $role = $request->query('role'); // 'buyer' (Mes Achats) | 'seller' (Mes Ventes) | null (tous)

        $q = DiaspoBooking::with(['offer.user', 'buyer', 'seller']);

        // Masquer les réservations dont le paiement n'a PAS abouti : une réservation
        // n'apparaît (acheteur comme vendeur) qu'une fois le paiement confirmé.
        $q->where('payment_status', '!=', 'pending');

        if ($role === 'buyer') {
            // Mes Achats : uniquement les réservations où je suis l'acheteur
            // (→ le code secret m'est visible, car buyer_user_id === viewer).
            $q->where('buyer_user_id', $uid);
        } elseif ($role === 'seller') {
            // Mes Ventes : uniquement les réservations où je suis le vendeur/voyageur
            // (→ code secret masqué).
            $q->where('seller_user_id', $uid);
        } else {
            $q->where(fn($x) => $x->where('buyer_user_id', $uid)->orWhere('seller_user_id', $uid));
        }
        $q->latest();

        return response()->json(['success' => true, 'data' => $this->paginate($q, $request, $uid)]);
    }

    public function showBooking(Request $request, $id)
    {
        $uid = $request->user()->id;
        $booking = DiaspoBooking::with(['offer.user', 'buyer', 'seller'])
            ->where('id', $id)
            ->where(fn($x) => $x->where('buyer_user_id', $uid)->orWhere('seller_user_id', $uid))
            ->firstOrFail();
        return response()->json(['success' => true, 'data' => $booking->toApi($uid)]);
    }

    /** POST /v1/diaspo/bookings/{id}/cancel — annule + rembourse (mini-wallet) + libère les kg. */
    public function cancelBooking(Request $request, $id)
    {
        $booking = DiaspoBooking::where('id', $id)
            ->where('buyer_user_id', $request->user()->id)
            ->firstOrFail();

        // « confirmed » : code de livraison déjà validé, le colis a voyagé.
        if (in_array($booking->status, ['completed', 'cancelled', 'confirmed'])) {
            return response()->json(['success' => false, 'message' => __('diaspo.booking_already_finalized')], 422);
        }

        DB::transaction(function () use ($booking, $request) {
            $b = DiaspoBooking::whereKey($booking->id)->lockForUpdate()->first();

            // Rembourser dans le mini-wallet acheteur si déjà payé.
            // La devise vit sur l'OFFRE, pas sur la réservation : sans ce
            // repli le remboursement échouait sur une devise nulle.
            if ($b->payment_status === 'completed') {
                $buyer = User::find($b->buyer_user_id);
                $currency = DiaspoOffer::whereKey($b->diaspo_offer_id)->value('currency') ?: 'XAF';
                $buyer->creditKpay($currency, (float) $b->total_price);
                $b->refunded_at = now();
                $b->payment_status = 'refunded';
            }

            $b->status = 'cancelled';
            $b->cancel_reason = $request->input('cancel_reason', 'Annulé par l\'acheteur');
            $b->cancelled_at = now();
            $b->save();

            // Libérer les kg réservés
            DiaspoOffer::whereKey($b->diaspo_offer_id)->increment('remaining_kg', (float) $b->kg_booked);
        });

        return response()->json(['success' => true, 'message' => __('diaspo.booking_cancelled'), 'data' => $booking->fresh()->load(['offer.user', 'buyer', 'seller'])->toApi($request->user()->id)]);
    }

    /**
     * POST /v1/diaspo/bookings/{id}/confirm-receipt — confirmation de réception par l'acheteur.
     *
     * Les fonds ne sont versés qu'à la validation du code de livraison
     * ([sellerConfirmCode]). Cette route ne règle plus que les réservations
     * restées « confirmed » : code validé avant que la validation ne règle
     * elle-même la course, voyageur jamais payé.
     */
    public function confirmReceipt(Request $request, $id)
    {
        $booking = DiaspoBooking::where('id', $id)
            ->where('buyer_user_id', $request->user()->id)
            ->firstOrFail();

        if ($booking->payment_status !== 'completed' || $booking->status !== 'confirmed') {
            return response()->json(['success' => false, 'message' => __('diaspo.booking_action_not_allowed')], 422);
        }

        if ($error = $this->completeBooking($booking, 'confirmed')) {
            return $error;
        }

        return response()->json(['success' => true, 'message' => __('diaspo.receipt_confirmed_traveler_credited'), 'data' => $booking->fresh()->load(['offer.user', 'buyer', 'seller'])->toApi($request->user()->id)]);
    }

    /**
     * Clôt la réservation et la règle dans la même transaction, si elle est
     * toujours au statut attendu (deux validations simultanées ne créditent
     * qu'une fois). Renvoie une réponse d'erreur, ou null en cas de succès.
     */
    private function completeBooking(DiaspoBooking $booking, string $expectedStatus): ?\Illuminate\Http\JsonResponse
    {
        try {
            $settled = DB::transaction(function () use ($booking, $expectedStatus) {
                $b = DiaspoBooking::whereKey($booking->id)->lockForUpdate()->first();
                if ($b->status !== $expectedStatus || $b->payment_status !== 'completed') {
                    return false;
                }

                $this->settleBooking($b);

                $b->update([
                    'status' => 'completed',
                    // Remettre son code vaut, pour l'acheteur, confirmation de réception.
                    'confirmed_by_buyer_at' => $b->confirmed_by_buyer_at ?? now(),
                ]);

                return true;
            });
        } catch (\RuntimeException $e) {
            // Sans taux de change, rien n'est crédité et la réservation reste
            // au même statut : la validation pourra être refaite.
            Log::error('[Diaspo] Règlement impossible', ['booking_id' => $booking->id, 'error' => $e->getMessage()]);

            return response()->json(['success' => false, 'message' => __('diaspo.settlement_unavailable')], 503);
        }

        return $settled
            ? null
            : response()->json(['success' => false, 'message' => __('diaspo.booking_already_finalized')], 422);
    }

    /**
     * Règle une réservation livrée, sur le modèle du règlement d'une commande :
     * le voyageur reçoit son sous-total, ASSO la commission, tous deux sur le
     * Wallet (solde XAF). Le sous-total allait auparavant dans `pending_earnings`,
     * colonne des gains de parrainage sans aucun mécanisme de libération, et la
     * commission n'était créditée nulle part.
     *
     * @throws \RuntimeException si la devise de l'offre ne peut être convertie.
     */
    private function settleBooking(DiaspoBooking $b): void
    {
        $currency = strtoupper(DiaspoOffer::withTrashed()->whereKey($b->diaspo_offer_id)->value('currency') ?: 'XAF');
        $travelerAmount = $this->toWalletCurrency($currency, (float) $b->subtotal);
        $commissionAmount = $this->toWalletCurrency($currency, (float) $b->commission_amount);

        $wallet = app(WalletService::class);
        $metadata = [
            'diaspo_booking_id' => $b->id,
            'direct_settlement' => true,
            'currency' => $currency,
            'subtotal' => (float) $b->subtotal,
            'commission_amount' => (float) $b->commission_amount,
        ];

        if ($travelerAmount > 0) {
            $wallet->credit(
                User::findOrFail($b->seller_user_id),
                $travelerAmount,
                null,
                "Réservation Diaspo #{$b->id}",
                $metadata,
                'kpay'
            );
        }

        if ($commissionAmount > 0) {
            $platform = CommissionService::platformAccount();
            if ($platform) {
                $wallet->credit(
                    $platform,
                    $commissionAmount,
                    null,
                    "Commission ASSO — Réservation Diaspo #{$b->id}",
                    $metadata,
                    'kpay'
                );
            } else {
                Log::warning('[Diaspo] Compte plateforme ASSO introuvable, commission non créditée', [
                    'booking_id' => $b->id,
                    'commission' => $commissionAmount,
                ]);
            }
        }
    }

    /** Montant de l'offre converti dans la devise du Wallet (XAF). */
    private function toWalletCurrency(string $currency, float $amount): float
    {
        if ($amount <= 0) {
            return 0.0;
        }

        $converted = ExchangeRateService::convertAmount($currency, PaymentMethodService::PIVOT, $amount);
        if ($converted === null) {
            throw new \RuntimeException("Taux {$currency} → " . PaymentMethodService::PIVOT . ' indisponible');
        }

        return round($converted, 2);
    }

    /** POST /v1/diaspo/bookings/{id}/seller-confirm-code — le voyageur valide le code de l'acheteur. */
    public function sellerConfirmCode(Request $request, $id)
    {
        $request->validate(['confirmation_code' => 'required|string|size:6']);
        $booking = DiaspoBooking::where('id', $id)
            ->where('seller_user_id', $request->user()->id)
            ->firstOrFail();

        if ($booking->confirmation_code !== $request->input('confirmation_code')) {
            return response()->json(['success' => false, 'message' => __('diaspo.confirmation_code_incorrect')], 422);
        }
        if ($booking->payment_status !== 'completed') {
            return response()->json(['success' => false, 'message' => __('diaspo.booking_not_paid_yet')], 422);
        }
        if ($booking->status !== 'paid') {
            return response()->json(['success' => false, 'message' => __('diaspo.booking_already_finalized')], 422);
        }

        // L'acheteur ne remet son code qu'à la livraison du colis : sa validation
        // prouve le transit et déclenche seule le versement au voyageur et à ASSO.
        if ($error = $this->completeBooking($booking, 'paid')) {
            return $error;
        }

        return response()->json(['success' => true, 'message' => __('diaspo.code_validated'), 'data' => $booking->fresh()->load(['offer.user', 'buyer', 'seller'])->toApi($request->user()->id)]);
    }

    /** POST /v1/diaspo/confirm-by-code — recherche une réservation par son code. */
    public function confirmByCode(Request $request)
    {
        $request->validate(['confirmation_code' => 'required|string|size:6']);
        $uid = $request->user()->id;
        $booking = DiaspoBooking::with(['offer.user', 'buyer', 'seller'])
            ->where('confirmation_code', $request->input('confirmation_code'))
            ->where(fn($x) => $x->where('buyer_user_id', $uid)->orWhere('seller_user_id', $uid))
            ->firstOrFail();

        return response()->json(['success' => true, 'data' => $booking->toApi($uid)]);
    }
}
