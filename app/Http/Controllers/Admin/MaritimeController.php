<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DelivererCompany;
use App\Models\MaritimeBooking;
use App\Models\MaritimeDeparture;
use App\Models\MaritimeHub;
use App\Models\Setting;
use App\Services\MaritimeBookingException;
use App\Services\MaritimeBookingService;
use App\Support\CountryCode;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Diaspo Maritime : réglages, QG des partenaires, départs et suivi des envois.
 * Les partenaires eux-mêmes se créent dans « Partenaires logistiques » (type
 * « Fret maritime ») ; ce qu'ASSO leur doit se suit et se verse depuis leur fiche.
 */
class MaritimeController extends Controller
{
    public function __construct(private MaritimeBookingService $service) {}

    public function index()
    {
        $partners = DelivererCompany::where('service_type', DelivererCompany::SERVICE_MARITIME)
            ->with(['maritimeHubs' => fn ($q) => $q->orderBy('country')->orderBy('city')])
            ->orderBy('name')
            ->get();

        $departures = MaritimeDeparture::with(['company', 'originHub', 'destinationHub'])
            ->withCount(['bookings as paid_bookings_count' => fn ($q) => $q->where('payment_status', '!=', 'pending')])
            ->orderByRaw("case when status in ('open', 'closed') then 0 else 1 end")
            ->orderBy('departure_date')
            ->paginate(20);

        return view('admin.maritime.index', [
            'partners' => $partners,
            'departures' => $departures,
            'countries' => CountryCode::NAMES,
            'settings' => [
                'commission_rate' => MaritimeDeparture::commissionRate(),
                'booking_cutoff_days' => MaritimeDeparture::bookingCutoffDays(),
                'dropoff_days_before' => MaritimeDeparture::dropoffDaysBefore(),
            ],
            'pendingDropoffs' => MaritimeBooking::where('status', MaritimeBooking::STATUS_PAID)->count(),
            'dueByCompany' => \App\Services\PartnerLedgerService::dueByCompany(),
        ]);
    }

    public function updateSettings(Request $request)
    {
        $data = $request->validate([
            'commission_rate' => 'required|numeric|min:0|max:100',
            'booking_cutoff_days' => 'required|integer|min:0|max:60',
            'dropoff_days_before' => 'required|integer|min:0|max:60|lte:booking_cutoff_days',
        ], [
            'dropoff_days_before.lte' => 'La date limite de dépôt doit tomber après la clôture des réservations (nombre de jours inférieur ou égal).',
        ]);

        Setting::set('maritime_commission_rate', $data['commission_rate'], 'string', 'maritime', 'Majoration ASSO sur le fret maritime (%)');
        Setting::set('maritime_booking_cutoff_days', $data['booking_cutoff_days'], 'string', 'maritime', 'Clôture des réservations, en jours avant le départ');
        Setting::set('maritime_dropoff_days_before', $data['dropoff_days_before'], 'string', 'maritime', 'Date limite de dépôt du colis, en jours avant le départ');

        return back()->with('success', 'Réglages maritimes enregistrés.');
    }

    // ------------------------------------------------------------------
    // QG
    // ------------------------------------------------------------------

    public function storeHub(Request $request, DelivererCompany $partner)
    {
        abort_unless($partner->service_type === DelivererCompany::SERVICE_MARITIME, 404);
        $partner->maritimeHubs()->create($this->validateHub($request) + ['is_active' => true]);

        return back()->with('success', 'QG ajouté.');
    }

    public function updateHub(Request $request, MaritimeHub $hub)
    {
        $hub->update($this->validateHub($request) + ['is_active' => $request->boolean('is_active')]);

        return back()->with('success', 'QG mis à jour.');
    }

    private function validateHub(Request $request): array
    {
        return $request->validate([
            'name' => 'required|string|max:255',
            'country' => ['required', Rule::in(array_keys(CountryCode::NAMES))],
            'city' => 'required|string|max:120',
            'address' => 'required|string|max:255',
            'phone' => 'nullable|string|max:30',
            'opening_hours' => 'nullable|string|max:255',
        ]);
    }

    // ------------------------------------------------------------------
    // Départs
    // ------------------------------------------------------------------

    public function storeDeparture(Request $request)
    {
        $data = $request->validate([
            'deliverer_company_id' => ['required', Rule::exists('deliverer_companies', 'id')->where('service_type', DelivererCompany::SERVICE_MARITIME)],
            'origin_hub_id' => 'required|exists:maritime_hubs,id',
            'destination_hub_id' => 'required|exists:maritime_hubs,id|different:origin_hub_id',
            'departure_date' => 'required|date|after:today',
            'estimated_arrival_date' => 'nullable|date|after:departure_date',
            'capacity_kg' => 'required|numeric|min:1',
            'price_per_kg' => 'required|numeric|min:0.01',
            'currency' => 'required|string|size:3',
            'notes' => 'nullable|string|max:2000',
        ]);

        $partner = DelivererCompany::findOrFail($data['deliverer_company_id']);
        $hubIds = $partner->maritimeHubs()->pluck('id');
        if (!$hubIds->contains($data['origin_hub_id']) || !$hubIds->contains($data['destination_hub_id'])) {
            return back()->withInput()->with('error', 'Les deux QG doivent appartenir au partenaire choisi.');
        }

        MaritimeDeparture::create($data + [
            'currency' => strtoupper($data['currency']),
            'remaining_kg' => $data['capacity_kg'],
            'status' => MaritimeDeparture::STATUS_OPEN,
        ]);

        return back()->with('success', 'Départ créé et ouvert aux réservations.');
    }

    public function updateDeparture(Request $request, MaritimeDeparture $departure)
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(array_keys(MaritimeDeparture::STATUSES))],
            'capacity_kg' => 'required|numeric|min:0',
            'estimated_arrival_date' => 'nullable|date',
        ]);

        // La capacité ne descend pas sous les kilos déjà réservés.
        $booked = (float) $departure->capacity_kg - (float) $departure->remaining_kg;
        if ((float) $data['capacity_kg'] < $booked) {
            return back()->with('error', "Capacité inférieure aux {$booked} kg déjà réservés.");
        }

        $departure->update([
            'status' => $data['status'],
            'estimated_arrival_date' => $data['estimated_arrival_date'] ?? $departure->estimated_arrival_date,
            'capacity_kg' => $data['capacity_kg'],
            'remaining_kg' => (float) $data['capacity_kg'] - $booked,
        ]);

        return back()->with('success', 'Départ mis à jour.');
    }

    // ------------------------------------------------------------------
    // Envois
    // ------------------------------------------------------------------

    public function bookings(Request $request)
    {
        $q = MaritimeBooking::with(['departure.originHub', 'departure.destinationHub', 'departure.company'])
            ->where('payment_status', '!=', 'pending')
            ->latest();

        if ($status = $request->query('status')) {
            $q->where('status', $status);
        }
        if ($departureId = $request->query('departure')) {
            $q->where('maritime_departure_id', $departureId);
        }

        return view('admin.maritime.bookings', [
            'bookings' => $q->paginate(30)->withQueryString(),
            'statuses' => MaritimeBooking::STATUSES,
        ]);
    }

    public function showBooking(MaritimeBooking $booking)
    {
        $booking->load(['departure.originHub', 'departure.destinationHub', 'departure.company', 'sender', 'parcelNature']);

        return view('admin.maritime.booking', ['booking' => $booking, 'countries' => CountryCode::NAMES]);
    }

    public function advanceBooking(MaritimeBooking $booking)
    {
        try {
            $b = $this->service->advance($booking);
        } catch (MaritimeBookingException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Étape enregistrée : ' . MaritimeBooking::STATUSES[$b->status] . '. L\'expéditeur est prévenu.');
    }

    public function completeBooking(Request $request, MaritimeBooking $booking)
    {
        $data = $request->validate(['confirmation_code' => 'required|string|size:6']);

        try {
            $this->service->complete($booking, $data['confirmation_code']);
        } catch (MaritimeBookingException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Colis remis : fret ajouté au dû du partenaire, commission et assurance à ASSO.');
    }

    public function updateNotes(Request $request, MaritimeBooking $booking)
    {
        $booking->update($request->validate(['admin_notes' => 'nullable|string|max:5000']));

        return back()->with('success', 'Notes enregistrées.');
    }
}
