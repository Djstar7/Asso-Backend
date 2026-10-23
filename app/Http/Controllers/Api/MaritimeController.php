<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MaritimeBooking;
use App\Models\MaritimeDeparture;
use App\Services\MaritimeBookingException;
use App\Services\MaritimeBookingService;
use App\Services\MaritimeClaimService;
use App\Services\MaritimePlatformConversationService;
use App\Services\MobileMoneyGateway;
use App\Services\PaymentMethodService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Diaspo Maritime — départs des partenaires maritimes et envois des expéditeurs.
 * Voir App\Services\MaritimeBookingService.
 */
class MaritimeController extends Controller
{
    public function __construct(
        private MaritimeBookingService $service,
        private MaritimeClaimService $claims,
        private MaritimePlatformConversationService $platformConversations
    ) {}

    /** GET /v1/maritime/departures — départs à venir (les clos restent visibles, grisés). */
    public function departures(Request $request)
    {
        $q = MaritimeDeparture::with(['company', 'originHub', 'destinationHub'])
            ->upcoming()
            ->whereHas('company', fn ($c) => $c->where('is_active', true))
            ->whereHas('originHub', fn ($h) => $h->where('is_active', true)->when(app()->isProduction(), fn ($q) => $q->where('is_demo', false)))
            ->whereHas('destinationHub', fn ($h) => $h->where('is_active', true)->when(app()->isProduction(), fn ($q) => $q->where('is_demo', false)))
            ->when(app()->isProduction(), fn ($q) => $q->where('is_demo', false))
            ->orderBy('departure_date');

        if ($mode = $request->query('transport_mode')) {
            $request->validate(['transport_mode' => 'in:sea,air']);
            if ($mode === 'sea') {
                $q->where(fn ($m) => $m->whereNull('freight_rate_snapshot->transport_mode')->orWhere('freight_rate_snapshot->transport_mode', 'sea'));
            } else {
                $q->where('freight_rate_snapshot->transport_mode', 'air');
            }
        }

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
                'data' => collect($page->items())->map(fn ($departure) => $departure->toApi($request->user()?->id))->values(),
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
    public function showDeparture(Request $request, $id)
    {
        $departure = MaritimeDeparture::with(['company', 'originHub', 'destinationHub'])
            ->when(app()->isProduction(), fn ($q) => $q->where('is_demo', false)
                ->whereHas('originHub', fn ($h) => $h->where('is_demo', false))
                ->whereHas('destinationHub', fn ($h) => $h->where('is_demo', false)))
            ->findOrFail($id);

        return response()->json(['success' => true, 'data' => $departure->toApi($request->user()?->id)]);
    }

    /** POST /v1/maritime/departures/{id}/book */
    public function book(Request $request, $id)
    {
        $data = $request->validate($this->bookingRules(), $this->bookingMessages(), $this->bookingAttributes());

        if ($data['payment_method'] !== 'wallet' && ! PaymentMethodService::isEnabled($data['payment_method'])) {
            return response()->json([
                'success' => false,
                'message' => __('payments.method_unavailable'),
                'errors' => ['payment_method' => [__('payments.method_unavailable')]],
            ], 422);
        }

        try {
            $result = $this->service->book($request->user(), (int) $id, $data);
        } catch (MaritimeBookingException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                // Si l'exception porte un champ, on l'expose (voir §2)
                'errors' => $e->field() ? [$e->field() => [$e->getMessage()]] : (object) [],
            ], 422);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('[Maritime] Initialisation du paiement', ['departure_id' => $id, 'error' => $e->getMessage()]);

            return response()->json(['success' => false, 'message' => $e->getMessage(), 'errors' => (object) []], 422);
        }

        $booking = $result['booking']->load(['departure.company', 'departure.originHub', 'departure.destinationHub', 'parcelNature']);

        return response()->json([
            'success' => true,
            'message' => $result['message'],
            'data' => $booking->toApi($request->user()),
            'payment' => $result['payment'],
            'payment_reference' => $booking->payment_reference,
            'booking_id' => $booking->id,
        ], 201);
    }

    private function bookingRules(): array
    {
        return [
            'freight_inquiry_id' => 'nullable|integer|min:1',
            'kg_booked' => 'required|numeric|min:0.5',
            'parcels_count' => 'nullable|integer|min:1|max:500',
            'sender_first_name' => 'required|string|max:100',
            'sender_last_name' => 'required|string|max:100',
            'sender_phone' => ['required', 'string', 'max:30', 'regex:/^\+?[0-9 ]{8,20}$/'],
            'emergency_phone' => ['required', 'string', 'max:30', 'regex:/^\+?[0-9 ]{8,20}$/'],
            'recipient_first_name' => 'required|string|max:100',
            'recipient_last_name' => 'required|string|max:100',
            'recipient_country' => 'required|string|size:2|alpha',
            'recipient_phone' => ['required', 'string', 'max:30', 'regex:/^\+?[0-9 ]{8,20}$/'],
            'parcel_nature_id' => 'required|integer|exists:parcel_natures,id,is_active,1',
            'declared_value' => 'required|numeric|gt:0|max:100000000',
            'insurance_opt_in' => 'sometimes|boolean',
            'declared_value_currency' => 'nullable|string|size:3',
            'payment_method' => 'required|in:kpay,stripe,wallet',
            'provider' => 'required_if:payment_method,kpay|string',
            'phone_number' => 'required_if:payment_method,kpay|string',
        ];
    }

    private function bookingMessages(): array
    {
        return [
            'required' => 'Le champ :attribute est obligatoire.',
            'required_if' => 'Le champ :attribute est obligatoire pour ce mode de paiement.',
            'max' => ':attribute est trop long (:max caractères maximum).',
            'regex' => ':attribute n\'est pas un numéro valide (ex. +237 6XX XX XX XX).',
            'kg_booked.min' => 'Le poids minimum est de :min kg.',
            'kg_booked.numeric' => 'Le poids doit être un nombre.',
            'parcels_count.min' => 'Il faut au moins :min colis.',
            'parcels_count.max' => 'Maximum :max colis par envoi.',
            'recipient_country.size' => 'Sélectionnez un pays de destination valide.',
            'recipient_country.alpha' => 'Sélectionnez un pays de destination valide.',
            'parcel_nature_id.exists' => 'Cette nature de colis n\'est plus disponible.',
            'declared_value.numeric' => 'La valeur déclarée doit être un nombre.',
            'declared_value.gt' => 'La valeur déclarée doit être strictement supérieure à zéro.',
            'declared_value.max' => 'La valeur déclarée est trop élevée.',
            'payment_method.in' => 'Mode de paiement non supporté.',
        ];
    }

    private function bookingAttributes(): array
    {
        return [
            'kg_booked' => 'poids',
            'parcels_count' => 'nombre de colis',
            'sender_first_name' => 'prénom de l\'expéditeur',
            'sender_last_name' => 'nom de l\'expéditeur',
            'sender_phone' => 'téléphone de l\'expéditeur',
            'emergency_phone' => 'téléphone d\'urgence',
            'recipient_first_name' => 'prénom du destinataire',
            'recipient_last_name' => 'nom du destinataire',
            'recipient_country' => 'pays de destination',
            'recipient_phone' => 'téléphone du destinataire',
            'parcel_nature_id' => 'nature du colis',
            'declared_value' => 'valeur déclarée',
            'provider' => 'opérateur Mobile Money',
            'phone_number' => 'numéro Mobile Money',
        ];
    }

    /** GET /v1/maritime/bookings — envois payés de l'expéditeur. */
    public function bookings(Request $request)
    {
        $page = MaritimeBooking::with(['departure.company', 'departure.originHub', 'departure.destinationHub', 'parcelNature', 'insuranceHistory', 'indemnityClaim.histories'])
            ->where('sender_user_id', $request->user()->id)
            // Un envoi n'apparaît qu'une fois le paiement abouti.
            ->where('payment_status', '!=', 'pending')
            ->latest()
            ->paginate(min(50, (int) $request->query('per_page', 20)));

        return response()->json([
            'success' => true,
            'data' => [
                'data' => collect($page->items())->map(fn ($booking) => $booking->toApi($request->user()))->values(),
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
            ->load(['departure.company', 'departure.originHub', 'departure.destinationHub', 'parcelNature', 'insuranceHistory', 'indemnityClaim.histories']);

        return response()->json(['success' => true, 'data' => $booking->toApi($request->user())]);
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
            'data' => $booking->fresh(['departure.company', 'departure.originHub', 'departure.destinationHub', 'parcelNature', 'insuranceHistory', 'indemnityClaim.histories'])->toApi($request->user()),
        ]);
    }

    /** POST /v1/maritime/bookings/{id}/loss-reports */
    public function reportLoss(Request $request, $id)
    {
        $data = $request->validate(['reason' => 'required|string|min:5|max:5000']);

        try {
            $claim = $this->claims->reportLoss($this->ownBooking($request, $id), $request->user(), $data['reason']);
        } catch (MaritimeBookingException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage(), 'errors' => (object) []], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Loss report submitted for review.',
            'data' => [
                'claim_summary' => [
                    'id' => $claim->id,
                    'status' => $claim->status,
                    'reason' => $claim->customer_reason,
                    'history' => $claim->histories->map(fn ($history) => [
                        'action' => $history->action,
                        'status' => $history->to_status,
                        'reason' => $history->reason,
                        'created_at' => $history->created_at?->toIso8601String(),
                    ])->values(),
                ],
            ],
        ], 201);
    }

    /** POST /v1/maritime/bookings/{id}/contact-platform */
    public function contactPlatform(Request $request, $id)
    {
        try {
            $conversation = $this->platformConversations->contactPlatform(
                $this->ownBooking($request, $id),
                $request->user()
            );
        } catch (MaritimeBookingException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage(), 'errors' => (object) []], 422);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'conversation' => [
                    'id' => $conversation->id,
                    'user1' => ['id' => $conversation->user1_id, 'name' => $conversation->user1?->name],
                    'user2' => ['id' => $conversation->user2_id, 'name' => $conversation->user2?->name],
                    'maritime_booking_ids' => $conversation->maritimeBookings()->pluck('maritime_bookings.id')->values(),
                    'other_user' => $conversation->getOtherUser($request->user()->id) ? [
                        'id' => $conversation->getOtherUser($request->user()->id)->id,
                        'name' => $conversation->getOtherUser($request->user()->id)->name,
                        'avatar' => $conversation->getOtherUser($request->user()->id)->avatar,
                        'phone' => $conversation->getOtherUser($request->user()->id)->phone,
                    ] : null,
                ],
                'prefill_message' => "Bonjour, je souhaite obtenir des informations concernant mon colis maritime #{$id}, actuellement en transit.",
            ],
        ]);
    }

    private function ownBooking(Request $request, $id): MaritimeBooking
    {
        return MaritimeBooking::where('id', $id)
            ->where('sender_user_id', $request->user()->id)
            ->firstOrFail();
    }
}
