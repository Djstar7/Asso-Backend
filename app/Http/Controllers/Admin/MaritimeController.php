<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DelivererCompany;
use App\Models\MaritimeBooking;
use App\Models\MaritimeDeparture;
use App\Models\MaritimeHub;
use App\Models\MaritimeIndemnityClaim;
use App\Models\Setting;
use App\Services\MaritimeBookingException;
use App\Services\MaritimeBookingService;
use App\Services\MaritimeClaimService;
use App\Support\CountryCode;
use App\Support\Translation\ContentLocale;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Diaspo Maritime : réglages, QG des partenaires, départs et suivi des envois.
 * Rubrique séparée de la livraison locale : les partenaires maritimes n'ont ni
 * coursiers, ni grilles de ville, ni trajets au poids. Leur fret est crédité sur
 * le Wallet de leur compte interne et versé depuis leur page de paiement.
 */
class MaritimeController extends Controller
{
    public function __construct(
        private MaritimeBookingService $service,
        private MaritimeClaimService $claims
    ) {}

    /** Partenaires maritimes, réglages et départs à venir. */
    public function index()
    {
        $partners = DelivererCompany::where('service_type', DelivererCompany::SERVICE_MARITIME)
            ->with('account')
            ->withCount(['maritimeHubs', 'maritimeDepartures as upcoming_departures_count' => fn ($q) => $q->upcoming()])
            ->orderBy('name')
            ->get();

        $departures = MaritimeDeparture::with(['company', 'originHub', 'destinationHub'])
            ->withCount(['bookings as paid_bookings_count' => fn ($q) => $q->where('payment_status', '!=', 'pending')])
            ->upcoming()
            ->orderBy('departure_date')
            ->limit(50)
            ->get();

        return view('admin.maritime.index', [
            'partners' => $partners,
            'departures' => $departures,
            'settings' => $this->settings(),
            'indemnityCapXaf' => Setting::get('maritime_indemnity_cap_xaf'),
            'pendingDropoffs' => MaritimeBooking::where('status', MaritimeBooking::STATUS_PAID)->count(),
        ]);
    }

    public function updateIndemnityCap(Request $request)
    {
        $this->authorizeAdmin($request);
        $data = $request->validate(['indemnity_cap_xaf' => 'required|integer|min:1|max:1000000000000']);
        Setting::set(
            'maritime_indemnity_cap_xaf',
            $data['indemnity_cap_xaf'],
            'integer',
            'maritime',
            'Plafond global d’indemnisation maritime (XAF)'
        );

        return back()->with('success', 'Plafond global d’indemnisation enregistré.');
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

    private function settings(): array
    {
        return [
            'commission_rate' => MaritimeDeparture::commissionRate(),
            'booking_cutoff_days' => MaritimeDeparture::bookingCutoffDays(),
            'dropoff_days_before' => MaritimeDeparture::dropoffDaysBefore(),
        ];
    }

    // ------------------------------------------------------------------
    // Partenaires maritimes
    // ------------------------------------------------------------------

    public function storePartner(Request $request)
    {
        $partner = DelivererCompany::create($this->validatePartner($request) + [
            'service_type' => DelivererCompany::SERVICE_MARITIME,
            'service_mode' => DelivererCompany::MODE_AGENCY,
            'is_active' => true,
        ]);
        $partner->syncTranslations($request->input('translations'));

        return redirect()->route('admin.maritime.partners.show', $partner)
            ->with('success', "Partenaire « {$partner->name} » créé. Ajoutez ses QG puis ses départs.");
    }

    public function showPartner(DelivererCompany $partner)
    {
        $this->ensureMaritime($partner);
        $partner->load(['maritimeHubs' => fn ($q) => $q->orderBy('country')->orderBy('city'), 'account']);

        return view('admin.maritime.partner', [
            'partner' => $partner,
            'departures' => $partner->maritimeDepartures()
                ->with(['originHub', 'destinationHub'])
                ->withCount(['bookings as paid_bookings_count' => fn ($q) => $q->where('payment_status', '!=', 'pending')])
                ->withCount('bookings')
                ->orderByRaw("case when status in ('open', 'closed') then 0 else 1 end")
                ->orderByDesc('departure_date')
                ->paginate(20),
            'usedHubIds' => $partner->maritimeDepartures()
                ->get(['origin_hub_id', 'destination_hub_id'])
                ->flatMap(fn ($departure) => [$departure->origin_hub_id, $departure->destination_hub_id])
                ->unique()
                ->all(),
            'countries' => CountryCode::NAMES,
            'settings' => $this->settings(),
            'walletBalance' => $partner->account?->kpayAvailableFor('XAF') ?? 0.0,
        ]);
    }

    public function updatePartner(Request $request, DelivererCompany $partner)
    {
        $this->ensureMaritime($partner);
        $partner->update($this->validatePartner($request, $partner) + ['is_active' => $request->boolean('is_active')]);
        $partner->syncTranslations($request->input('translations'));

        return back()->with('success', 'Partenaire mis à jour.');
    }

    private function validatePartner(Request $request, ?DelivererCompany $partner = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('deliverer_companies', 'name')->ignore($partner?->id)],
            'phone' => 'nullable|string|max:30',
            'email' => 'nullable|email|max:255',
            'description' => 'nullable|string|max:1000',
            'conditions' => 'nullable|string|max:3000',
        ] + ContentLocale::rules(['description' => 'string|max:1000', 'conditions' => 'string|max:3000']));
        unset($data['translations']);

        return $data;
    }

    private function ensureMaritime(DelivererCompany $partner): void
    {
        abort_unless($partner->service_type === DelivererCompany::SERVICE_MARITIME, 404);
    }

    // ------------------------------------------------------------------
    // QG
    // ------------------------------------------------------------------

    public function storeHub(Request $request, DelivererCompany $partner)
    {
        $this->ensureMaritime($partner);
        $partner->maritimeHubs()->create($this->validateHub($request) + ['is_active' => true]);

        return back()->with('success', 'QG ajouté.');
    }

    public function updateHub(Request $request, MaritimeHub $hub)
    {
        $this->ensureMaritime($hub->company);
        $hub->update($this->validateHub($request) + ['is_active' => $request->boolean('is_active')]);

        return back()->with('success', 'QG mis à jour.');
    }

    public function destroyHub(MaritimeHub $hub)
    {
        $this->ensureMaritime($hub->company);
        $isUsed = MaritimeDeparture::where('origin_hub_id', $hub->id)
            ->orWhere('destination_hub_id', $hub->id)
            ->exists();

        if ($isUsed) {
            return back()->with('error', 'Ce QG est utilisé par un départ et ne peut pas être supprimé.');
        }

        $hub->delete();

        return back()->with('success', 'QG supprimé.');
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

    /**
     * Départ du partenaire. Tarif au kg, ou au m³ comme le facture le
     * transporteur : l'expéditeur saisit des kilos, convertis en m³
     * (1 m³ = 1 000 kg) pour calculer le prix.
     */
    public function storeDeparture(Request $request, DelivererCompany $partner)
    {
        $this->ensureMaritime($partner);
        $hubIds = $partner->maritimeHubs()->pluck('id')->all();

        $data = $request->validate([
            'origin_hub_id' => ['required', Rule::in($hubIds)],
            'destination_hub_id' => ['required', Rule::in($hubIds), 'different:origin_hub_id'],
            'departure_date' => 'required|date|after:today',
            'estimated_arrival_date' => 'nullable|date|after:departure_date',
            'capacity_kg' => 'required|numeric|min:1',
            'pricing_unit' => 'required|in:cbm,kg',
            'price' => 'required|numeric|min:0.01',
            'currency' => 'required|string|size:3',
            'notes' => 'nullable|string|max:2000',
        ], [
            'origin_hub_id.in' => 'Choisissez un QG de ce partenaire.',
            'destination_hub_id.in' => 'Choisissez un QG de ce partenaire.',
        ]);

        $perCbm = $data['pricing_unit'] === 'cbm';
        MaritimeDeparture::create([
            'deliverer_company_id' => $partner->id,
            'origin_hub_id' => $data['origin_hub_id'],
            'destination_hub_id' => $data['destination_hub_id'],
            'departure_date' => $data['departure_date'],
            'estimated_arrival_date' => $data['estimated_arrival_date'] ?? null,
            'capacity_kg' => $data['capacity_kg'],
            'remaining_kg' => $data['capacity_kg'],
            'price_per_cbm' => $perCbm ? $data['price'] : null,
            'kg_per_cbm' => $perCbm ? MaritimeDeparture::KG_PER_CBM : null,
            // Prix au kilo équivalent, gardé pour l'affichage et l'historique.
            'price_per_kg' => $perCbm ? round($data['price'] / MaritimeDeparture::KG_PER_CBM, 2) : $data['price'],
            'currency' => strtoupper($data['currency']),
            'status' => MaritimeDeparture::STATUS_OPEN,
            'notes' => $data['notes'] ?? null,
        ]);

        return back()->with('success', 'Départ créé et ouvert aux réservations.');
    }

    public function updateDeparture(Request $request, MaritimeDeparture $departure)
    {
        $this->ensureMaritime($departure->company);
        $hasBookings = $departure->bookings()->exists();
        $data = $request->validate([
            'status' => ['required', Rule::in(array_keys(MaritimeDeparture::STATUSES))],
            'capacity_kg' => 'required|numeric|min:1',
            'estimated_arrival_date' => [
                'nullable',
                'date',
                $hasBookings
                    ? 'after:'.$departure->departure_date->toDateString()
                    : 'after:departure_date',
            ],
            'notes' => 'nullable|string|max:2000',
            ...($hasBookings ? [] : [
                'origin_hub_id' => ['required', Rule::in($departure->company->maritimeHubs()->pluck('id')->all())],
                'destination_hub_id' => ['required', Rule::in($departure->company->maritimeHubs()->pluck('id')->all()), 'different:origin_hub_id'],
                'departure_date' => 'required|date',
                'pricing_unit' => 'required|in:cbm,kg',
                'price' => 'required|numeric|min:0.01',
                'currency' => 'required|string|size:3',
            ]),
        ], [
            'origin_hub_id.in' => 'Choisissez un QG de ce partenaire.',
            'destination_hub_id.in' => 'Choisissez un QG de ce partenaire.',
        ]);

        // La capacité ne descend pas sous les kilos déjà réservés.
        $booked = (float) $departure->capacity_kg - (float) $departure->remaining_kg;
        if ((float) $data['capacity_kg'] < $booked) {
            return back()->with('error', "Capacité inférieure aux {$booked} kg déjà réservés.");
        }

        $updates = [
            'status' => $data['status'],
            'estimated_arrival_date' => $data['estimated_arrival_date'],
            'capacity_kg' => $data['capacity_kg'],
            'remaining_kg' => (float) $data['capacity_kg'] - $booked,
            'notes' => $data['notes'] ?? null,
        ];

        if (! $hasBookings) {
            $perCbm = $data['pricing_unit'] === 'cbm';
            $updates += [
                'origin_hub_id' => $data['origin_hub_id'],
                'destination_hub_id' => $data['destination_hub_id'],
                'departure_date' => $data['departure_date'],
                'price_per_cbm' => $perCbm ? $data['price'] : null,
                'kg_per_cbm' => $perCbm ? MaritimeDeparture::KG_PER_CBM : null,
                'price_per_kg' => $perCbm
                    ? round($data['price'] / MaritimeDeparture::KG_PER_CBM, 2)
                    : $data['price'],
                'currency' => strtoupper($data['currency']),
            ];
        }

        $departure->update($updates);

        return back()->with('success', 'Départ mis à jour.');
    }

    public function destroyDeparture(MaritimeDeparture $departure)
    {
        $this->ensureMaritime($departure->company);
        if ($departure->bookings()->exists()) {
            return back()->with('error', 'Ce départ possède des envois et ne peut pas être supprimé.');
        }

        $departure->delete();

        return back()->with('success', 'Départ supprimé.');
    }

    // ------------------------------------------------------------------
    // Envois
    // ------------------------------------------------------------------

    public function bookings(Request $request)
    {
        $q = MaritimeBooking::with(['departure.originHub', 'departure.destinationHub', 'departure.company', 'indemnityClaim'])
            ->where('payment_status', '!=', 'pending')
            ->latest();

        if ($status = $request->query('status')) {
            $q->where('status', $status);
        }
        if ($departureId = $request->query('departure')) {
            $q->where('maritime_departure_id', $departureId);
        }
        if ($claimStatus = $request->query('claim_status')) {
            $q->whereHas('indemnityClaim', fn ($claim) => $claim->where('status', $claimStatus));
        }

        return view('admin.maritime.bookings', [
            'bookings' => $q->paginate(30)->withQueryString(),
            'statuses' => MaritimeBooking::STATUSES,
            'claimStatuses' => [
                MaritimeIndemnityClaim::REPORTED => 'Signalé',
                MaritimeIndemnityClaim::LOSS_CONFIRMED => 'Perte confirmée',
                MaritimeIndemnityClaim::APPROVED => 'Approuvé',
                MaritimeIndemnityClaim::REFUSED => 'Refusé',
                MaritimeIndemnityClaim::PAID => 'Indemnisé',
            ],
        ]);
    }

    public function showBooking(MaritimeBooking $booking)
    {
        $booking->load([
            'departure.originHub', 'departure.destinationHub', 'departure.company', 'sender', 'parcelNature',
            'insuranceHistory.actor', 'indemnityClaim.histories.actor',
        ]);

        return view('admin.maritime.booking', [
            'booking' => $booking,
            'countries' => CountryCode::NAMES,
            'indemnityCapXaf' => Setting::get('maritime_indemnity_cap_xaf'),
        ]);
    }

    public function confirmLoss(Request $request, MaritimeIndemnityClaim $claim)
    {
        $this->authorizeAdmin($request);
        $data = $request->validate(['reason' => 'required|string|min:5|max:5000']);
        try {
            $this->claims->confirmLoss($claim, $request->user(), $data['reason']);
        } catch (MaritimeBookingException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Perte confirmée. La demande peut maintenant être approuvée ou refusée.');
    }

    public function reviewClaim(Request $request, MaritimeIndemnityClaim $claim)
    {
        $this->authorizeAdmin($request);
        $data = $request->validate([
            'decision' => 'required|in:approve,refuse',
            'reason' => 'required|string|min:5|max:5000',
        ]);
        try {
            $this->claims->review($claim, $request->user(), $data['decision'], $data['reason']);
        } catch (MaritimeBookingException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $data['decision'] === 'approve' ? 'Demande approuvée.' : 'Demande refusée.');
    }

    public function payClaim(Request $request, MaritimeIndemnityClaim $claim)
    {
        $this->authorizeAdmin($request);
        $data = $request->validate([
            'reason' => 'required|string|min:5|max:5000',
            'confirmed_proposal_xaf' => 'required|numeric|gt:0',
        ]);
        try {
            $paid = $this->claims->pay($claim, $request->user(), $data['reason'], (float) $data['confirmed_proposal_xaf']);
        } catch (MaritimeBookingException $e) {
            return back()->with('error', $e->getMessage());
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Indemnisation versée : '.number_format((float) $paid->payout_amount_xaf, 0, ',', ' ').' XAF.');
    }

    public function payoutProposal(Request $request, MaritimeIndemnityClaim $claim)
    {
        $this->authorizeAdmin($request);
        try {
            return response()->json([
                'success' => true,
                'data' => ['proposal' => $this->claims->payoutProposal($claim)],
            ]);
        } catch (MaritimeBookingException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function advanceBooking(MaritimeBooking $booking)
    {
        try {
            $b = $this->service->advance($booking);
        } catch (MaritimeBookingException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Étape enregistrée : '.MaritimeBooking::STATUSES[$b->status].'. L\'expéditeur est prévenu.');
    }

    public function completeBooking(Request $request, MaritimeBooking $booking)
    {
        $data = $request->validate(['confirmation_code' => 'required|string|size:6']);

        try {
            $this->service->complete($booking, $data['confirmation_code']);
        } catch (MaritimeBookingException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Colis remis : fret crédité au Wallet du partenaire, commission et assurance à ASSO.');
    }

    public function updateNotes(Request $request, MaritimeBooking $booking)
    {
        $booking->update($request->validate(['admin_notes' => 'nullable|string|max:5000']));

        return back()->with('success', 'Notes enregistrées.');
    }

    private function authorizeAdmin(Request $request): void
    {
        abort_unless($request->user()?->isAdmin(), 403);
    }
}
