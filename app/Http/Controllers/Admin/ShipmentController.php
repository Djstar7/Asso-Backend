<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\OrderTrackingService;
use Illuminate\Http\Request;

/**
 * P4 — Suivi des expéditions transporteur (SOLEX, DHL, FedEx, import) jusqu'à la
 * réception : numéro de suivi, étapes datées, clôture si l'acheteur ne confirme pas.
 */
class ShipmentController extends Controller
{
    public function __construct(private OrderTrackingService $tracking)
    {
    }

    public function index(Request $request)
    {
        $query = Order::with(['user', 'deliveryCompany'])
            ->where('delivery_mode', Order::DELIVERY_CARRIER)
            ->where('payment_status', Order::PAYMENT_PAID);

        $status = $request->input('status', 'in_progress');
        if ($status === 'in_progress') {
            // Dès le paiement : en attente du vendeur, puis préparation, puis chez le transporteur.
            $query->whereIn('status', ['pending', 'confirmed', 'preparing', 'shipped']);
        } elseif ($status === 'awaiting_seller') {
            $query->where('status', 'pending');
        } elseif (in_array($status, ['arrived_hub', 'pickup_requested', 'pickup_accepted', 'collected_by_carrier', 'partner_in_transit', 'ready_for_pickup'], true)) {
            $query->where('status', 'shipped')->where('tracking_status', $status);
        } elseif ($status !== 'all') {
            $query->where('status', $status);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(fn ($q) => $q->where('order_number', 'like', "%{$search}%")
                ->orWhere('carrier_tracking_number', 'like', "%{$search}%"));
        }

        return view('admin.shipments.index', [
            'orders' => $query->latest()->paginate(20)->withQueryString(),
            'status' => $status,
        ]);
    }

    public function show(Order $order)
    {
        abort_unless($order->isCarrierDelivery(), 404);
        $order->load(['user', 'items.product', 'items.seller', 'deliveryCompany', 'trackingEvents']);

        return view('admin.shipments.show', [
            'order' => $order,
            'steps' => array_intersect_key(OrderTrackingService::STEPS, array_flip(array_merge(
                ['handed_to_carrier'], OrderTrackingService::carrierUpdateSteps($order), ['delivered']
            ))),
        ]);
    }

    public function addStep(Request $request, Order $order)
    {
        abort_unless($order->isCarrierDelivery(), 404);

        $validated = $request->validate([
            'step' => 'required|string',
            'carrier_tracking_number' => 'nullable|string|max:100',
            'location' => 'nullable|string|max:150',
            'note' => 'nullable|string|max:500',
            'confirmation_contact' => 'nullable|string|max:150',
            'confirmation_reference' => 'nullable|string|max:150',
            'confirmation_channel' => 'nullable|in:phone,email,whatsapp,document,in_person',
        ]);
        app(\App\Services\AssoShipmentService::class)->advance($order, $validated, $request->user());
        return back()->with('success', "Étape enregistrée. L'acheteur a été prévenu.");
    }
}
