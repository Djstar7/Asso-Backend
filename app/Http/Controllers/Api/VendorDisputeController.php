<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Dispute;
use App\Models\DisputeShipment;
use App\Services\DisputeService;
use App\Services\PaymentMethodService;
use Illuminate\Http\Request;

/**
 * Réclamations côté vendeur : répondre avec ses preuves, remplacer le produit (une
 * fois) ou organiser le retour, choisir le partenaire et payer la course (Wallet,
 * Mobile Money / OM, carte). Le vendeur ne peut pas clôturer lui-même un dossier.
 */
class VendorDisputeController extends Controller
{
    public function __construct(private DisputeService $disputes)
    {
    }

    /** GET /api/v1/vendor/disputes?status=open|closed */
    public function index(Request $request)
    {
        $disputes = Dispute::where('seller_id', $request->user()->id)
            ->when($request->get('status') === 'open', fn ($q) => $q->open())
            ->when($request->get('status') === 'closed', fn ($q) => $q->whereNotIn('status', Dispute::OPEN_STATUSES))
            ->latest()
            ->get()
            ->map(fn (Dispute $d) => $this->disputes->toApi($d, 'vendor'));

        return response()->json(['success' => true, 'disputes' => $disputes]);
    }

    /** GET /api/v1/vendor/disputes/{id} */
    public function show(Request $request, $id)
    {
        return $this->respond($this->find($request, $id));
    }

    /** POST /api/v1/vendor/disputes/{id}/evidence (multipart : note, files[]) */
    public function evidence(Request $request, $id)
    {
        $validated = $request->validate([
            'note' => 'required_without:files|nullable|string|max:2000',
            'files' => 'nullable|array|max:6',
            'files.*' => 'image|max:8192',
        ]);
        $dispute = $this->find($request, $id);

        return $this->attempt(fn () => $this->disputes->addEvidence(
            $dispute, 'vendor', $request->user(), $validated['note'] ?? null, $request->file('files', [])
        ), $dispute, __('disputes.evidence_sent'));
    }

    /** POST /api/v1/vendor/disputes/{id}/replace */
    public function replace(Request $request, $id)
    {
        $dispute = $this->find($request, $id);

        return $this->attempt(fn () => $this->disputes->startReplacement($dispute, 'vendor', $request->user()->id), $dispute);
    }

    /** POST /api/v1/vendor/disputes/{id}/return */
    public function organizeReturn(Request $request, $id)
    {
        $dispute = $this->find($request, $id);

        return $this->attempt(fn () => $this->disputes->startReturn($dispute, 'vendor', $request->user()->id), $dispute);
    }

    /** GET /api/v1/vendor/disputes/shipments/{id}/partners */
    public function partners(Request $request, $shipmentId)
    {
        $shipment = $this->findShipment($request, $shipmentId);

        return response()->json([
            'success' => true,
            'shipment' => $shipment->toApi(),
            'quotes' => $this->disputes->partnerQuotes($shipment),
        ]);
    }

    /** POST /api/v1/vendor/disputes/shipments/{id}/partner */
    public function choosePartner(Request $request, $shipmentId)
    {
        $choice = $request->validate([
            'company_id' => 'required|integer',
            'zone_id' => 'nullable|integer',
            'route_id' => 'nullable|integer',
            'grid_id' => 'nullable|integer',
            'vehicle' => 'nullable|string|max:30',
        ]);
        $shipment = $this->findShipment($request, $shipmentId);

        try {
            $shipment = $this->disputes->choosePartner($shipment, $choice);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'shipment' => $shipment->toApi()]);
    }

    /** POST /api/v1/vendor/disputes/shipments/{id}/pay */
    public function pay(Request $request, $shipmentId)
    {
        $request->validate([
            'payment_mode' => 'required|in:wallet,kpay_direct,stripe_direct',
            'provider' => 'required_if:payment_mode,kpay_direct|string',
            'phone_number' => 'required_if:payment_mode,kpay_direct|string',
        ]);
        $mode = $request->input('payment_mode');
        if ($mode === 'stripe_direct' && !PaymentMethodService::isEnabled('stripe')) {
            return response()->json(['success' => false, 'message' => __('payments.stripe_unavailable_choose_other')], 422);
        }
        $shipment = $this->findShipment($request, $shipmentId);

        try {
            $shipment = $this->disputes->initiatePayment($shipment, $request->user(), $mode, $request->input('provider'), $request->input('phone_number'));
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => match ($mode) {
                'kpay_direct' => __('disputes.confirm_on_phone'),
                'stripe_direct' => __('orders.created_complete_card'),
                default => __('disputes.shipment_paid'),
            },
            'shipment' => $shipment->fresh()->toApi(),
            'payment_reference' => $shipment->payment_reference,
            'client_secret' => $mode === 'stripe_direct' ? ($shipment->client_secret ?? null) : null,
            'payment_intent_id' => $mode === 'stripe_direct' ? ($shipment->payment_intent_id ?? null) : null,
            'publishable_key' => $mode === 'stripe_direct' ? ($shipment->stripe_publishable_key ?? null) : null,
        ]);
    }

    /** GET /api/v1/vendor/disputes/shipments/{id}/payment-status (polling) */
    public function paymentStatus(Request $request, $shipmentId)
    {
        $shipment = $this->findShipment($request, $shipmentId);
        $this->disputes->syncPayment($shipment);

        return response()->json(['success' => true, 'shipment' => $shipment->fresh()->toApi()]);
    }

    /**
     * Le vendeur remet le remplacement au livreur, ou confirme la réception du retour.
     *
     * POST /api/v1/vendor/disputes/shipments/{id}/step
     */
    public function step(Request $request, $shipmentId)
    {
        $validated = $request->validate([
            'step' => 'required|in:shipped,delivered_to_vendor',
            'note' => 'nullable|string|max:500',
            'carrier_tracking_number' => 'nullable|string|max:100',
        ]);
        $shipment = $this->findShipment($request, $shipmentId);

        try {
            $this->disputes->recordStep($shipment, $validated['step'], 'vendor', $request->user()->id, $validated['note'] ?? null, $validated['carrier_tracking_number'] ?? null);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return $this->respond($shipment->dispute->fresh());
    }

    private function attempt(callable $action, Dispute $dispute, ?string $message = null)
    {
        try {
            $action();
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return $this->respond($dispute->fresh(), $message);
    }

    private function respond(Dispute $dispute, ?string $message = null)
    {
        return response()->json(array_filter([
            'success' => true,
            'message' => $message,
            'dispute' => $this->disputes->toApi($dispute, 'vendor'),
        ], fn ($v) => $v !== null));
    }

    private function find(Request $request, $id): Dispute
    {
        return Dispute::where('seller_id', $request->user()->id)->findOrFail($id);
    }

    private function findShipment(Request $request, $id): DisputeShipment
    {
        return DisputeShipment::whereHas('dispute', fn ($q) => $q->where('seller_id', $request->user()->id))
            ->with('dispute.order')
            ->findOrFail($id);
    }
}
