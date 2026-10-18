<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MaritimeBooking;
use App\Models\MaritimeDeparture;
use App\Services\MaritimeBookingException;
use App\Services\MaritimeBookingService;
use App\Services\MobileMoneyGateway;
use App\Services\PaymentMethodService;
use App\Support\CountryCode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * Diaspo Maritime — départs des partenaires maritimes et envois des expéditeurs.
 * Voir App\Services\MaritimeBookingService.
 */
class MaritimeController extends Controller
{
    public function __construct(private MaritimeBookingService $service) {}

    /** GET /v1/maritime/departures — départs à venir (les clos restent visibles, grisés). */
    public function departures(Request $request)
    {
        $q = MaritimeDeparture::with(['company', 'originHub', 'destinationHub'])
            ->upcoming()
            ->whereHas('company', fn ($c) => $c->where('is_active', true))
            ->orderBy('departure_date');

        if ($country = $request->query('origin_country')) {
            $q->whereHas('originHub', fn ($h) => $h->where('country', strtoupper($country)));
        }
        if ($country = $request->query('destination_country')) {
            $q->whereHas('destinationHub', fn ($h) => $h->where('country', strtoupper($country)));
        }

        $page = $q->paginate(min(50, (int) $request->query('per_page', 20)));

        return response()->json([
            'success' => true,
            'data' => [
                'data' => collect($page->items())->map->toApi()->values(),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
            ],
            'meta' => [
                'booking_cutoff_days' => MaritimeDeparture::bookingCutoffDays(),
                'dropoff_days_before' => MaritimeDeparture::dropoffDaysBefore(),
            ],
        ]);
    }

    /** GET /v1/maritime/departures/{id} */
    public function showDeparture($id)
    {
        $departure = MaritimeDeparture::with(['company', 'originHub', 'destinationHub'])->findOrFail($id);

        return response()->json(['success' => true, 'data' => $departure->toApi()]);
    }

    /** POST /v1/maritime/departures/{id}/book */
    public function book(Request $request, $id)
    {
        $data = $request->validate([
            'kg_booked' => 'required|numeric|min:0.5',
            'parcels_count' => 'nullable|integer|min:1|max:500',
            'sender_first_name' => 'required|string|max:100',
            'sender_last_name' => 'required|string|max:100',
            'sender_phone' => 'required|string|max:30',
            'emergency_phone' => 'required|string|max:30',
            'recipient_first_name' => 'required|string|max:100',
            'recipient_last_name' => 'required|string|max:100',
            'recipient_country' => ['required', 'string', Rule::in(array_keys(CountryCode::NAMES))],
            'recipient_phone' => 'required|string|max:30',
            'parcel_nature_id' => 'required|integer|exists:parcel_natures,id,is_active,1',
            'declared_value' => 'required|numeric|min:0|max:100000000',
            'payment_method' => 'required|in:kpay,stripe',
            'provider' => 'required_if:payment_method,kpay|string',
            'phone_number' => 'required_if:payment_method,kpay|string',
        ]);

        if (!PaymentMethodService::isEnabled($data['payment_method'])) {
            return response()->json(['success' => false, 'message' => __('payments.method_unavailable')], 422);
        }

        try {
            $result = $this->service->book($request->user(), (int) $id, $data);
        } catch (MaritimeBookingException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('[Maritime] Initialisation du paiement', ['departure_id' => $id, 'error' => $e->getMessage()]);

            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        $booking = $result['booking']->load(['departure.company', 'departure.originHub', 'departure.destinationHub', 'parcelNature']);

        return response()->json([
            'success' => true,
            'message' => $result['message'],
            'data' => $booking->toApi(),
            'payment' => $result['payment'],
            'payment_reference' => $booking->payment_reference,
            'booking_id' => $booking->id,
        ], 201);
    }

    /** GET /v1/maritime/bookings — envois payés de l'expéditeur. */
    public function bookings(Request $request)
    {
        $page = MaritimeBooking::with(['departure.company', 'departure.originHub', 'departure.destinationHub', 'parcelNature'])
            ->where('sender_user_id', $request->user()->id)
            // Un envoi n'apparaît qu'une fois le paiement abouti.
            ->where('payment_status', '!=', 'pending')
            ->latest()
            ->paginate(min(50, (int) $request->query('per_page', 20)));

        return response()->json([
            'success' => true,
            'data' => [
                'data' => collect($page->items())->map->toApi()->values(),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    /** GET /v1/maritime/bookings/{id} */
    public function showBooking(Request $request, $id)
    {
        $booking = $this->ownBooking($request, $id)
            ->load(['departure.company', 'departure.originHub', 'departure.destinationHub', 'parcelNature']);

        return response()->json(['success' => true, 'data' => $booking->toApi()]);
    }

    /** GET /v1/maritime/bookings/{id}/payment-status — re-vérifie le rail et confirme. */
    public function paymentStatus(Request $request, $id)
    {
        $booking = $this->ownBooking($request, $id);
        $this->service->syncPayment($booking);
        $booking->refresh();

        $paymentFailure = $booking->status === MaritimeBooking::STATUS_CANCELLED && $booking->payment_status === 'pending'
            && $booking->payment_method !== 'stripe'
            ? app(MobileMoneyGateway::class)->failureFor($booking->payment_reference)
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

    /** POST /v1/maritime/bookings/{id}/cancel — avant le dépôt du colis seulement. */
    public function cancel(Request $request, $id)
    {
        $booking = $this->ownBooking($request, $id);

        try {
            $this->service->cancelBySender($booking, $request->input('cancel_reason'));
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => __('maritime.booking_cancelled'),
            'data' => $booking->fresh(['departure.company', 'departure.originHub', 'departure.destinationHub', 'parcelNature'])->toApi(),
        ]);
    }

    private function ownBooking(Request $request, $id): MaritimeBooking
    {
        return MaritimeBooking::where('id', $id)
            ->where('sender_user_id', $request->user()->id)
            ->firstOrFail();
    }
}
