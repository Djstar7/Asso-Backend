<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DiaspoBooking;
use App\Models\DiaspoOffer;
use App\Models\DiaspoVerification;
use App\Models\Setting;
use App\Models\User;
use App\Services\KPayService;
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
    // Taux de commission Diaspo par défaut : 5 % (aligné sur l'affichage mobile).
    // Configurable via le Setting `diaspo_commission_rate` (en pourcentage).
    private const DEFAULT_COMMISSION_RATE = 5.0;

    /** Taux de commission Diaspo sous forme de fraction (ex. 0.05 pour 5 %). */
    private function commissionRate(): float
    {
        return ((float) Setting::get('diaspo_commission_rate', self::DEFAULT_COMMISSION_RATE)) / 100;
    }

    private function paginate($query, Request $request): array
    {
        $perPage = (int) $request->query('per_page', 20);
        $p = $query->paginate($perPage);
        return [
            'current_page' => $p->currentPage(),
            'data' => collect($p->items())->map(fn($m) => $m->toApi())->values(),
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
            'message' => 'Documents reçus. Votre vérification est en cours de traitement.',
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
            return response()->json(['success' => false, 'message' => 'Votre identité doit être vérifiée pour créer une offre.'], 403);
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

        return response()->json(['success' => true, 'message' => 'Offre créée', 'data' => $offer->load('user')->toApi()], 201);
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
        return response()->json(['success' => true, 'message' => 'Offre mise à jour', 'data' => $offer->fresh()->load('user')->toApi()]);
    }

    public function destroyOffer(Request $request, $id)
    {
        $offer = DiaspoOffer::where('user_id', $request->user()->id)->findOrFail($id);
        if ($offer->bookings()->whereIn('status', ['paid', 'confirmed'])->exists()) {
            return response()->json(['success' => false, 'message' => 'Impossible de supprimer : des réservations sont en cours.'], 422);
        }
        $offer->delete();
        return response()->json(['success' => true, 'message' => 'Offre supprimée']);
    }

    // ==================== RÉSERVATIONS ====================

    /** POST /v1/diaspo/offers/{id}/book — crée une réservation + PayIn KPay direct. */
    public function bookOffer(Request $request, $id)
    {
        $data = $request->validate([
            'kg_booked' => 'required|numeric|min:0.5',
            'provider' => 'required|string',       // code opérateur KPay
            'phone_number' => 'required|string',   // numéro Mobile Money
            'notes' => 'nullable|string|max:500',
        ]);

        $buyer = $request->user();

        return DB::transaction(function () use ($id, $data, $buyer) {
            $offer = DiaspoOffer::lockForUpdate()->findOrFail($id);

            if ($offer->user_id === $buyer->id) {
                return response()->json(['success' => false, 'message' => 'Vous ne pouvez pas réserver votre propre offre.'], 422);
            }
            // Offre réservable = vérifiée et publiée (schéma unifié : statut 'approved').
            if ($offer->status !== 'approved' || (float) $offer->remaining_kg < $data['kg_booked']) {
                return response()->json(['success' => false, 'message' => 'Offre non disponible ou kg insuffisants.'], 422);
            }

            $subtotal = round($data['kg_booked'] * (float) $offer->price_per_kg, 2);
            $commission = round($subtotal * $this->commissionRate(), 2);
            $total = $subtotal + $commission;

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
                'notes' => $data['notes'] ?? null,
            ]);

            // Réserver les kg
            $offer->decrement('remaining_kg', $data['kg_booked']);
            $offer->increment('bookings_count');

            // Initier le PayIn KPay (montant en devise de l'offre)
            $result = (new KPayService())->initializePayment([
                'amount' => (float) round($total),
                'provider' => $data['provider'],
                'phone_number' => $data['phone_number'],
                'description' => "Réservation diaspo #{$booking->id}",
                'external_reference' => "DIASPO-{$booking->id}",
            ]);

            if (empty($result['success'])) {
                throw new \Exception($result['message'] ?? "Échec de l'initiation du paiement KPay.");
            }

            $booking->update(['payment_reference' => $result['id'] ?? null]);

            return response()->json([
                'success' => true,
                'message' => 'Réservation créée. Validez le paiement sur votre téléphone.',
                'data' => $booking->load(['offer.user', 'buyer', 'seller'])->toApi(),
                'payment_reference' => $booking->payment_reference,
                'booking_id' => $booking->id,
            ], 201);
        });
    }

    /** GET /v1/diaspo/bookings/{id}/payment-status — re-vérifie KPay + confirme. */
    public function bookingPaymentStatus(Request $request, $id)
    {
        $booking = DiaspoBooking::where('id', $id)
            ->where('buyer_user_id', $request->user()->id)
            ->firstOrFail();

        if ($booking->payment_status === 'pending' && $booking->payment_reference) {
            $result = (new KPayService())->checkPaymentStatus($booking->payment_reference);
            $status = strtoupper($result['status'] ?? 'UNKNOWN');
            if (in_array($status, ['SUCCESS', 'SUCCESSFUL', 'COMPLETED'])) {
                $this->confirmBookingPayment($booking);
                $booking->refresh();
            } elseif (in_array($status, ['FAILED', 'FAILURE', 'ERROR', 'REJECTED', 'CANCELLED', 'CANCELED'])) {
                $this->failBookingPayment($booking);
                $booking->refresh();
            }
        }

        return response()->json([
            'success' => true,
            'data' => ['booking_id' => $booking->id, 'payment_status' => $booking->payment_status, 'status' => $booking->status],
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
                    $seller, '📦 Nouvelle réservation payée',
                    "Une réservation de {$booking->kg_booked} kg a été payée.",
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

    public function bookings(Request $request)
    {
        $uid = $request->user()->id;
        $q = DiaspoBooking::with(['offer.user', 'buyer', 'seller'])
            ->where(fn($x) => $x->where('buyer_user_id', $uid)->orWhere('seller_user_id', $uid))
            ->latest();
        return response()->json(['success' => true, 'data' => $this->paginate($q, $request)]);
    }

    public function showBooking(Request $request, $id)
    {
        $uid = $request->user()->id;
        $booking = DiaspoBooking::with(['offer.user', 'buyer', 'seller'])
            ->where('id', $id)
            ->where(fn($x) => $x->where('buyer_user_id', $uid)->orWhere('seller_user_id', $uid))
            ->firstOrFail();
        return response()->json(['success' => true, 'data' => $booking->toApi()]);
    }

    /** POST /v1/diaspo/bookings/{id}/cancel — annule + rembourse (mini-wallet) + libère les kg. */
    public function cancelBooking(Request $request, $id)
    {
        $booking = DiaspoBooking::where('id', $id)
            ->where('buyer_user_id', $request->user()->id)
            ->firstOrFail();

        if (in_array($booking->status, ['completed', 'cancelled'])) {
            return response()->json(['success' => false, 'message' => 'Réservation déjà finalisée.'], 422);
        }

        DB::transaction(function () use ($booking, $request) {
            $b = DiaspoBooking::whereKey($booking->id)->lockForUpdate()->first();

            // Rembourser dans le mini-wallet acheteur si déjà payé
            if ($b->payment_status === 'completed') {
                $buyer = User::find($b->buyer_user_id);
                $buyer->creditKpay($b->currency, (float) $b->total_price);
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

        return response()->json(['success' => true, 'message' => 'Réservation annulée', 'data' => $booking->fresh()->load(['offer.user', 'buyer', 'seller'])->toApi()]);
    }

    /** POST /v1/diaspo/bookings/{id}/confirm-receipt — l'acheteur confirme : fonds libérés au voyageur. */
    public function confirmReceipt(Request $request, $id)
    {
        $booking = DiaspoBooking::where('id', $id)
            ->where('buyer_user_id', $request->user()->id)
            ->firstOrFail();

        if ($booking->payment_status !== 'completed' || $booking->status === 'completed') {
            return response()->json(['success' => false, 'message' => 'Action impossible sur cette réservation.'], 422);
        }

        DB::transaction(function () use ($booking) {
            $b = DiaspoBooking::whereKey($booking->id)->lockForUpdate()->first();
            if ($b->status === 'completed') return;

            // Libérer le sous-total au voyageur (la plateforme garde la commission)
            User::where('id', $b->seller_user_id)->increment('pending_earnings', (float) $b->subtotal);

            $b->update([
                'status' => 'completed',
                'confirmed_by_buyer_at' => now(),
            ]);
        });

        return response()->json(['success' => true, 'message' => 'Réception confirmée. Le voyageur a été crédité.', 'data' => $booking->fresh()->load(['offer.user', 'buyer', 'seller'])->toApi()]);
    }

    /** POST /v1/diaspo/bookings/{id}/seller-confirm-code — le voyageur valide le code de l'acheteur. */
    public function sellerConfirmCode(Request $request, $id)
    {
        $request->validate(['confirmation_code' => 'required|string|size:6']);
        $booking = DiaspoBooking::where('id', $id)
            ->where('seller_user_id', $request->user()->id)
            ->firstOrFail();

        if ($booking->confirmation_code !== $request->input('confirmation_code')) {
            return response()->json(['success' => false, 'message' => 'Code de confirmation incorrect.'], 422);
        }
        if ($booking->payment_status !== 'completed') {
            return response()->json(['success' => false, 'message' => 'La réservation n\'est pas encore payée.'], 422);
        }

        // Voyageur a validé le code de l'acheteur → réservation confirmée (en transit).
        $booking->update(['status' => 'confirmed']);
        return response()->json(['success' => true, 'message' => 'Code validé.', 'data' => $booking->fresh()->load(['offer.user', 'buyer', 'seller'])->toApi()]);
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

        return response()->json(['success' => true, 'data' => $booking->toApi()]);
    }
}
