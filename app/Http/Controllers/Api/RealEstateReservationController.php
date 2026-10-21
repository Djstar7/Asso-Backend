<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RealEstate\DisputeReason;
use App\Models\RealEstate\Listing;
use App\Models\RealEstate\Reservation;
use App\Services\RealEstate\RealEstateDisputeService;
use App\Services\RealEstate\RealEstateReservationService;
use App\Services\WalletService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Réservation d'une maison ou acompte d'un terrain, paiement et suivi :
 * délai de visite, confirmation de conformité, contrat et facture.
 */
class RealEstateReservationController extends Controller
{
    public function __construct(private RealEstateReservationService $service) {}

    public function store(Request $request, int $id)
    {
        $data = $request->validate([
            'unit_id' => ['nullable', 'integer'],
            'advance_months' => ['nullable', 'integer', 'min:1', 'max:36'],
            'months' => ['nullable', 'integer', 'min:0', 'max:120'],
            'move_in_date' => ['nullable', 'date', 'after_or_equal:today'],
            'occupants' => ['nullable', 'array', 'max:10'],
            'occupants.*.name' => ['required_with:occupants', 'string', 'max:100'],
            'occupants.*.phone' => ['nullable', 'string', 'max:30'],
            'applicant_status' => ['nullable', Rule::in(['student', 'employee', 'self_employed', 'other'])],
            'school_or_employer' => ['nullable', 'string', 'max:150'],
            'message' => ['nullable', 'string', 'max:2000'],
            'contract_accepted' => ['accepted'],
            'expected_total' => ['nullable', 'integer', 'min:0'],
            'payment_mode' => ['required', Rule::in(['wallet', 'kpay_direct', 'stripe_direct'])],
            'provider' => ['required_if:payment_mode,kpay_direct', 'nullable', 'string'],
            'phone_number' => ['required_if:payment_mode,kpay_direct', 'nullable', 'string'],
        ]);
        $tenant = $request->user();
        $listing = Listing::with(['units', 'owner', 'cabinet.user'])->findOrFail($id);
        $unit = isset($data['unit_id']) ? $listing->units->firstWhere('id', $data['unit_id']) : null;
        if (! $unit && $listing->units->count() === 1) {
            $unit = $listing->units->first();
        }

        try {
            $this->service->assertBookable($tenant, $listing, $unit, $data);
        } catch (\DomainException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        $mode = $data['payment_mode'];
        $quoteTotal = app(\App\Services\RealEstate\RealEstatePricingService::class)
            ->quote($listing, $unit, $data['advance_months'] ?? null)['total'];
        if (isset($data['expected_total']) && (int) $data['expected_total'] !== $quoteTotal) {
            return response()->json([
                'success' => false, 'code' => 'quote_changed',
                'message' => __('real_estate.reservation.quote_changed'),
            ], 422);
        }
        if ($mode === 'wallet') {
            $canPay = app(WalletService::class)->canPayWithWallet($tenant, (float) $quoteTotal, 'kpay');
            if (! $canPay['can_pay']) {
                return response()->json([
                    'success' => false,
                    'code' => 'insufficient_balance',
                    'message' => $canPay['message'],
                    'data' => ['required_amount' => $quoteTotal, 'missing_amount' => $canPay['missing_amount']],
                ], 422);
            }
        }

        $reservation = $this->service->create($tenant, $listing, $unit, $data, $mode);

        try {
            if ($mode === 'wallet') {
                $reservation = $this->service->payWithWallet($reservation);

                return response()->json(['success' => true, 'data' => $this->payload($reservation->fresh(), $request)], 201);
            }
            $stripe = $this->service->initiatePayment($reservation, $data['provider'] ?? null, $data['phone_number'] ?? null);
        } catch (\Throwable $e) {
            $this->service->fail($reservation);
            Log::warning('[IMMO] Paiement non initié', ['reservation' => $reservation->id, 'error' => $e->getMessage()]);

            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'data' => $this->payload($reservation->fresh(), $request) + [
                'client_secret' => $stripe['client_secret'] ?? null,
                'payment_intent_id' => $stripe['payment_intent_id'] ?? null,
                'stripe_publishable_key' => $stripe['publishable_key'] ?? null,
            ],
        ], 201);
    }

    public function index(Request $request)
    {
        $reservations = Reservation::where('tenant_id', $request->user()->id)
            ->whereNotIn('status', ['cancelled'])
            ->with(['listing.media', 'listing.units', 'unit'])
            ->latest()->get();

        return response()->json(['success' => true, 'data' => $reservations->map(fn ($r) => $this->payload($r, $request))->values()]);
    }

    /** Réservations reçues par le bailleur ou le cabinet. */
    public function received(Request $request)
    {
        $reservations = Reservation::where('payee_id', $request->user()->id)
            ->whereIn('status', Reservation::PAID_STATUSES)
            ->with(['listing.media', 'listing.units', 'unit', 'tenant'])
            ->latest()->get();

        return response()->json(['success' => true, 'data' => $reservations->map(fn ($r) => $this->payload($r, $request))->values()]);
    }

    private function find(Request $request, int $id, bool $payeeAllowed = false): Reservation
    {
        $user = $request->user();
        $r = Reservation::with(['listing.media', 'listing.units', 'listing.owner', 'listing.cabinet', 'unit'])->findOrFail($id);
        abort_unless($r->tenant_id === $user->id || ($payeeAllowed && $r->payee_id === $user->id), 404);

        return $r;
    }

    public function show(Request $request, int $id)
    {
        return response()->json(['success' => true, 'data' => $this->payload($this->find($request, $id, true), $request)]);
    }

    public function paymentStatus(Request $request, int $id)
    {
        $r = $this->find($request, $id);
        $this->service->checkAndConfirm($r);

        return response()->json(['success' => true, 'data' => $this->payload($r->fresh(), $request)]);
    }

    public function confirm(Request $request, int $id)
    {
        $r = $this->find($request, $id);
        if ($r->status !== 'paid') {
            return response()->json(['success' => false, 'message' => __('real_estate.reservation.cannot_confirm')], 422);
        }
        $this->service->confirmConformity($r);

        return response()->json(['success' => true, 'data' => $this->payload($r->fresh(), $request)]);
    }

    public function document(Request $request, int $id, string $kind)
    {
        $r = $this->find($request, $id, true);
        abort_unless($r->canAccessDocuments(), 404);
        $path = $kind === 'invoice' ? $r->invoice_path : $r->contract_path;
        $expectedFilename = $kind === 'invoice'
            ? RealEstateReservationService::INVOICE_FILENAME : RealEstateReservationService::CONTRACT_FILENAME;
        $outdatedDocument = $path && basename($path) !== $expectedFilename;
        if (! $path || $outdatedDocument || ! Storage::disk(RealEstateReservationService::DOCS_DISK)->exists($path)) {
            $this->service->generateDocuments($r);
            $r->refresh();
            $path = $kind === 'invoice' ? $r->invoice_path : $r->contract_path;
        }
        abort_unless($path && Storage::disk(RealEstateReservationService::DOCS_DISK)->exists($path), 404);

        return Storage::disk(RealEstateReservationService::DOCS_DISK)->download(
            $path, "{$r->reference}-{$kind}.pdf", ['Cache-Control' => 'private, no-store']
        );
    }

    // ---------------------------------------------------------------- litiges

    public function disputeReasons(Request $request)
    {
        $kind = $request->query('kind') === 'land' ? 'land' : 'rent';

        return response()->json(['success' => true, 'data' => DisputeReason::for($kind)->get()
            ->map(fn ($r) => ['id' => $r->id, 'label' => $r->label])->values()]);
    }

    public function openDispute(Request $request, int $id, RealEstateDisputeService $disputes)
    {
        $data = $request->validate([
            'reason_id' => ['required', 'integer'],
            'description' => ['required', 'string', 'min:10', 'max:3000'],
            'photos' => ['nullable', 'array', 'max:8'],
            'photos.*' => ['image', 'max:10240'],
        ]);
        $r = $this->find($request, $id);
        try {
            $disputes->open($r, $request->user(), (int) $data['reason_id'], $data['description'], $request->file('photos', []));
        } catch (\DomainException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'data' => $this->payload($r->fresh(), $request)], 201);
    }

    public function addDisputeEvidence(Request $request, int $id, RealEstateDisputeService $disputes)
    {
        $data = $request->validate([
            'message' => ['nullable', 'string', 'max:3000'],
            'photos' => ['nullable', 'array', 'max:8'],
            'photos.*' => ['image', 'max:10240'],
        ]);
        $r = $this->find($request, $id, true);
        abort_unless($r->dispute, 404);
        try {
            $disputes->addEvidence($r->dispute, $request->user(), $data['message'] ?? null, $request->file('photos', []));
        } catch (\DomainException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'data' => $this->payload($r->fresh(), $request)]);
    }

    private function payload(Reservation $r, Request $request): array
    {
        $listing = $r->listing;
        $isPayee = $r->payee_id === $request->user()->id && $r->tenant_id !== $request->user()->id;

        return [
            'id' => $r->id,
            'reference' => $r->reference,
            'kind' => $r->kind,
            'status' => $r->status,
            'listing' => $listing ? $listing->toConversationApi() : null,
            'unit_label' => $r->unit?->label,
            'move_in_date' => $r->move_in_date?->toDateString(),
            'months' => $r->months,
            'advance_months' => $r->advance_months,
            'unit_price' => $r->displayed_unit_price,
            'base_amount' => $r->base_amount,
            'commission_amount' => $r->commission_amount,
            'partner_commission_amount' => $r->partner_commission_amount,
            'platform_amount' => $r->platform_amount,
            'total_amount' => $r->total_amount,
            'net_amount' => $r->owner_amount,
            'net_payee_kind' => $r->net_payee_kind,
            'owner_amount' => $r->payee_id === $request->user()->id ? $r->owner_amount : null,
            'commission_cabinet' => $r->partnerCabinet ? [
                'id' => $r->partnerCabinet->id,
                'name' => $r->partner_cabinet_name ?? $r->partnerCabinet->name,
            ] : null,
            'payment_method' => $r->payment_method,
            'paid_at' => $r->paid_at?->toIso8601String(),
            'visit_deadline_at' => $r->visit_deadline_at?->toIso8601String(),
            'released_at' => $r->released_at?->toIso8601String(),
            // Le téléchargement régénère les PDF manquants à la demande.
            'has_documents' => $r->canAccessDocuments(),
            'dispute' => $r->dispute?->load('attachments')->toApi(),
            'is_payee' => $isPayee,
            // Le bailleur (ou le cabinet) voit qui a payé et le dossier du demandeur.
            'tenant' => $isPayee ? [
                'name' => trim($r->tenant?->first_name.' '.$r->tenant?->last_name),
                'phone' => $r->tenant?->phone,
            ] : null,
            'occupants' => $isPayee ? $r->occupants : null,
            'applicant_status' => $isPayee ? $r->applicant_status : null,
            'school_or_employer' => $isPayee ? $r->school_or_employer : null,
            'message' => $isPayee ? $r->message : null,
            'can_respond' => $isPayee && $r->status === 'disputed' && $r->dispute?->status === 'open',
            'can_confirm' => $r->status === 'paid' && $r->tenant_id === $request->user()->id,
            'can_dispute' => $r->status === 'paid' && $r->tenant_id === $request->user()->id
                && (! $r->visit_deadline_at || $r->visit_deadline_at->isFuture()),
            // Coordonnées débloquées par le paiement.
            'contact' => ! $isPayee && in_array($r->status, Reservation::PAID_STATUSES, true) && $listing ? (($r->net_payee_kind === 'cabinet' || $r->kind === 'land')
                ? [
                    'name' => $r->partner_cabinet_name ?? $r->payee?->name,
                    'phone' => $r->payee?->phone ?? $r->partnerCabinet?->phone ?? $listing->cabinet?->phone,
                    'whatsapp' => $r->partnerCabinet?->whatsapp ?? ($r->kind === 'land' ? $listing->cabinet?->whatsapp : null),
                    'email' => $r->partnerCabinet?->email,
                    'address' => $listing->address,
                    'latitude' => $listing->latitude,
                    'longitude' => $listing->longitude,
                ]
                : [
                    'name' => trim(($r->payee?->first_name ?? '').' '.($r->payee?->last_name ?? '')),
                    'phone' => $r->payee?->phone,
                    'address' => $listing->address,
                    'latitude' => $listing->latitude,
                    'longitude' => $listing->longitude,
                ]) : null,
        ];
    }
}
