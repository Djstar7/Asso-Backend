<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Diaspo (kg-sharing for travellers) — FAKE/stub implementation for testing.
 * Reads return well-shaped fake data; writes echo a fabricated resource.
 * No persistence.
 */
class DiaspoController extends Controller
{
    /** Fake offer matching DiaspoOffer.fromJson (all required fields present). */
    private function fakeOffer(int $id): array
    {
        $routes = [
            ['France', 'Paris', 'Bénin', 'Cotonou', 'EUR', 12.0],
            ['Canada', 'Montréal', 'Bénin', 'Cotonou', 'CAD', 15.0],
            ['France', 'Lyon', "Côte d'Ivoire", 'Abidjan', 'EUR', 10.0],
            ['États-Unis', 'New York', 'Sénégal', 'Dakar', 'USD', 14.0],
        ];
        $r = $routes[$id % count($routes)];
        $available = 20.0 + ($id % 5) * 5;
        $remaining = $available - ($id % 4) * 2;

        return [
            'id' => $id,
            'user_id' => 300 + $id,
            'status' => 'active',
            'verification_status' => 'verified',
            'verified_at' => Carbon::now()->subDays(2)->toIso8601String(),
            'verified_by' => 1,
            'rejection_reason' => null,
            'departure_country' => $r[0],
            'departure_city' => $r[1],
            'departure_datetime' => Carbon::now()->addDays($id + 1)->toIso8601String(),
            'arrival_country' => $r[2],
            'arrival_city' => $r[3],
            'arrival_datetime' => Carbon::now()->addDays($id + 2)->toIso8601String(),
            'price_per_kg' => $r[5],
            'available_kg' => $available,
            'remaining_kg' => $remaining,
            'currency' => $r[4],
            'views_count' => (13 * $id) % 100,
            'bookings_count' => $id % 4,
            'formatted_price' => number_format($r[5], 0) . ' ' . $r[4] . '/kg',
            'is_available' => $remaining > 0,
            'trip_duration_hours' => 8.0 + ($id % 5),
            'user' => [
                'id' => 300 + $id,
                'first_name' => 'Voyageur',
                'last_name' => '#' . $id,
                'avatar' => null,
                'phone' => null,
            ],
            'created_at' => Carbon::now()->subDays($id)->toIso8601String(),
            'updated_at' => Carbon::now()->subDays($id)->toIso8601String(),
        ];
    }

    /** Fake booking matching DiaspoBooking.fromJson. */
    private function fakeBooking(int $id, ?int $offerId = null): array
    {
        $offerId = $offerId ?? (1 + ($id % 4));
        $offer = $this->fakeOffer($offerId);
        $kg = 3.0;
        $subtotal = $kg * $offer['price_per_kg'];
        $commission = round($subtotal * 0.1, 2);
        $total = $subtotal + $commission;

        return [
            'id' => $id,
            'diaspo_offer_id' => $offerId,
            'buyer_user_id' => 20,
            'seller_user_id' => $offer['user_id'],
            'kg_booked' => $kg,
            'price_per_kg' => $offer['price_per_kg'],
            'subtotal' => $subtotal,
            'commission_amount' => $commission,
            'total_price' => $total,
            'status' => 'pending',
            'confirmation_code' => str_pad((string) (($id * 7) % 1000000), 6, '0', STR_PAD_LEFT),
            'confirmed_by_buyer_at' => null,
            'payment_status' => 'pending',
            'payment_reference' => null,
            'paid_at' => null,
            'refunded_at' => null,
            'conversation_id' => null,
            'notes' => null,
            'cancel_reason' => null,
            'cancelled_at' => null,
            'formatted_total' => number_format($total, 0) . ' ' . $offer['currency'],
            'is_completed' => false,
            'diaspo_offer' => $offer,
            'buyer' => ['id' => 20, 'first_name' => 'Client', 'last_name' => 'Test', 'avatar' => null, 'phone' => null],
            'seller' => $offer['user'],
            'created_at' => Carbon::now()->subDays($id)->toIso8601String(),
            'updated_at' => Carbon::now()->subDays($id)->toIso8601String(),
        ];
    }

    private function paginate(array $items, int $page, int $perPage): array
    {
        return [
            'current_page' => $page,
            'data' => $items,
            'per_page' => $perPage,
            'last_page' => 1,
            'total' => count($items),
            'next_page_url' => null,
            'prev_page_url' => null,
        ];
    }

    // ==================== VERIFICATION ====================

    /** GET /v1/diaspo/verification-status */
    public function verificationStatus()
    {
        return response()->json([
            'success' => true,
            'data' => [
                'verification_status' => 'unverified', // unverified | pending | verified | rejected
                'can_create_offers' => false,
                'rejection_reason' => null,
            ],
        ]);
    }

    /** POST /v1/diaspo/upload-verification */
    public function uploadVerification(Request $request)
    {
        return response()->json([
            'success' => true,
            'message' => 'Documents reçus. Votre vérification est en cours de traitement.',
            'data' => [
                'verification_status' => 'pending',
                'can_create_offers' => false,
            ],
        ]);
    }

    // ==================== OFFERS ====================

    /** GET /v1/diaspo/offers */
    public function offers(Request $request)
    {
        $page = (int) $request->query('page', 1);
        $perPage = (int) $request->query('per_page', 20);
        $items = $page === 1 ? array_map(fn($i) => $this->fakeOffer($i), range(1, 4)) : [];

        return response()->json([
            'success' => true,
            'data' => $this->paginate($items, $page, $perPage),
        ]);
    }

    /** GET /v1/diaspo/offers/my-offers */
    public function myOffers(Request $request)
    {
        return response()->json([
            'success' => true,
            'data' => $this->paginate([], (int) $request->query('page', 1), (int) $request->query('per_page', 20)),
        ]);
    }

    /** GET /v1/diaspo/offers/{id} */
    public function showOffer($id)
    {
        return response()->json(['success' => true, 'data' => $this->fakeOffer((int) $id)]);
    }

    /** POST /v1/diaspo/offers */
    public function storeOffer(Request $request)
    {
        $offer = $this->fakeOffer(random_int(1000, 9999));
        // Reflect submitted values when present.
        foreach (['departure_country','departure_city','arrival_country','arrival_city','currency'] as $k) {
            if ($request->filled($k)) $offer[$k] = $request->input($k);
        }
        if ($request->filled('price_per_kg')) $offer['price_per_kg'] = (float) $request->input('price_per_kg');
        if ($request->filled('available_kg')) {
            $offer['available_kg'] = (float) $request->input('available_kg');
            $offer['remaining_kg'] = (float) $request->input('available_kg');
        }
        $offer['status'] = 'active';

        return response()->json(['success' => true, 'message' => 'Offre créée', 'data' => $offer], 201);
    }

    /** PUT /v1/diaspo/offers/{id} */
    public function updateOffer(Request $request, $id)
    {
        $offer = $this->fakeOffer((int) $id);
        foreach ($request->all() as $k => $v) {
            if (array_key_exists($k, $offer)) $offer[$k] = $v;
        }
        return response()->json(['success' => true, 'message' => 'Offre mise à jour', 'data' => $offer]);
    }

    /** DELETE /v1/diaspo/offers/{id} */
    public function destroyOffer($id)
    {
        return response()->json(['success' => true, 'message' => 'Offre supprimée']);
    }

    /** POST /v1/diaspo/offers/{id}/book */
    public function bookOffer(Request $request, $id)
    {
        $booking = $this->fakeBooking(random_int(1000, 9999), (int) $id);
        if ($request->filled('kg_booked')) {
            $kg = (float) $request->input('kg_booked');
            $offer = $this->fakeOffer((int) $id);
            $subtotal = $kg * $offer['price_per_kg'];
            $commission = round($subtotal * 0.1, 2);
            $booking['kg_booked'] = $kg;
            $booking['subtotal'] = $subtotal;
            $booking['commission_amount'] = $commission;
            $booking['total_price'] = $subtotal + $commission;
            $booking['formatted_total'] = number_format($subtotal + $commission, 0) . ' ' . $offer['currency'];
        }
        return response()->json(['success' => true, 'message' => 'Réservation créée', 'data' => $booking], 201);
    }

    /** POST /v1/diaspo/confirm-by-code */
    public function confirmByCode(Request $request)
    {
        $booking = $this->fakeBooking(random_int(1000, 9999));
        $booking['status'] = 'confirmed';
        return response()->json(['success' => true, 'message' => 'Code confirmé', 'data' => $booking]);
    }

    // ==================== BOOKINGS ====================

    /** GET /v1/diaspo/bookings */
    public function bookings(Request $request)
    {
        return response()->json([
            'success' => true,
            'data' => $this->paginate([], (int) $request->query('page', 1), (int) $request->query('per_page', 20)),
        ]);
    }

    /** GET /v1/diaspo/bookings/{id} */
    public function showBooking($id)
    {
        return response()->json(['success' => true, 'data' => $this->fakeBooking((int) $id)]);
    }

    /** POST /v1/diaspo/bookings/{id}/cancel */
    public function cancelBooking(Request $request, $id)
    {
        $booking = $this->fakeBooking((int) $id);
        $booking['status'] = 'cancelled';
        $booking['cancel_reason'] = $request->input('cancel_reason', 'Annulé par l\'utilisateur');
        $booking['cancelled_at'] = Carbon::now()->toIso8601String();
        return response()->json(['success' => true, 'message' => 'Réservation annulée', 'data' => $booking]);
    }

    /** POST /v1/diaspo/bookings/{id}/confirm-receipt */
    public function confirmReceipt($id)
    {
        $booking = $this->fakeBooking((int) $id);
        $booking['status'] = 'completed';
        $booking['is_completed'] = true;
        return response()->json(['success' => true, 'message' => 'Réception confirmée', 'data' => $booking]);
    }

    /** POST /v1/diaspo/bookings/{id}/seller-confirm-code */
    public function sellerConfirmCode(Request $request, $id)
    {
        $booking = $this->fakeBooking((int) $id);
        $booking['status'] = 'confirmed';
        return response()->json(['success' => true, 'message' => 'Code vendeur confirmé', 'data' => $booking]);
    }
}
