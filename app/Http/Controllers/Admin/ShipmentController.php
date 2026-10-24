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
            ->where('delivery_mode', Order::DELIVERY_CARRIER);

        $scope = $request->input('scope', 'all');
        if ($scope === 'export') {
            $query->where('delivery_breakdown->international_export', true);
        } else {
            $query->where('payment_status', Order::PAYMENT_PAID);
        }
        $statusOptions = [
            'in_progress' => 'En cours', 'awaiting_seller' => 'En attente du vendeur',
            'confirmed' => 'Validées · à expédier', 'preparing' => 'En préparation',
        ] + array_diff_key(OrderTrackingService::STEPS, array_flip(['pending', 'confirmed', 'preparing', 'cancelled', 'delivered'])) + [
            'shipped' => 'Expédiées (toutes étapes)', 'delivered' => 'Livrées', 'cancelled' => 'Annulées', 'all' => 'Toutes',
        ];
        $status = $request->input('status', $scope === 'export' ? 'all' : 'in_progress');
        if (! array_key_exists($status, $statusOptions)) {
            $status = 'in_progress';
        }
        if ($status === 'in_progress') {
            // Dès le paiement : en attente du vendeur, puis préparation, puis chez le transporteur.
            $query->whereIn('status', ['pending', 'confirmed', 'preparing', 'shipped']);
        } elseif ($status === 'awaiting_seller') {
            $query->where('status', 'pending');
        } elseif (array_key_exists($status, OrderTrackingService::STEPS) && ! in_array($status, ['confirmed', 'preparing', 'delivered', 'cancelled'], true)) {
            $query->where('tracking_status', $status);
        } elseif ($status !== 'all') {
            $query->where('status', $status);
        }

        if ($request->filled('search')) {
            $search = '%'.mb_strtolower(addcslashes(trim($request->input('search')), '%_\\')).'%';
            $query->where(fn ($q) => $q->whereRaw('LOWER(order_number) LIKE ?', [$search])
                ->orWhereRaw('LOWER(carrier_tracking_number) LIKE ?', [$search]));
        }

        return view('admin.shipments.index', [
            'orders' => $query->latest()->paginate(20)->withQueryString(),
            'status' => $status, 'statusOptions' => $statusOptions, 'scope' => $scope,
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
            'confirmation_channel' => 'nullable|in:phone,email,whatsapp,document,in_person,platform',
        ]);
        app(\App\Services\AssoShipmentService::class)->advance($order, $validated, $request->user());
        return back()->with('success', "Étape enregistrée. Le suivi client et les missions des livreurs ont été mis à jour.");
    }
}
