<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\OrderTrackingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
            'step' => 'required|in:handed_to_carrier,delivered,' . implode(',', OrderTrackingService::carrierUpdateSteps($order)),
            'carrier_tracking_number' => 'nullable|string|max:100',
            'location' => 'nullable|string|max:150',
            'note' => 'nullable|string|max:500',
        ]);

        if (in_array($order->status, ['cancelled', 'delivered', 'pending'], true)) {
            return back()->with('error', "La commande est « {$order->status} » : aucune étape ne peut être ajoutée.");
        }

        DB::transaction(function () use ($order, $validated, $request) {
            $updates = [];
            if (!empty($validated['carrier_tracking_number'])) {
                $updates['carrier_tracking_number'] = trim($validated['carrier_tracking_number']);
            }
            if ($order->status !== 'shipped' && $validated['step'] !== 'delivered') {
                $updates += ['status' => 'shipped', 'shipped_at' => now()];
            }
            if ($validated['step'] === 'delivered') {
                $updates += ['status' => 'delivered', 'delivered_at' => now(), 'confirmation_code' => null];
            }
            if ($updates) {
                $order->update($updates);
            }

            $this->tracking->record(
                $order, $validated['step'], $validated['location'] ?? null, $validated['note'] ?? null,
                'admin', $request->user()?->id, notifyBuyer: true,
            );
        });

        return back()->with('success', 'Étape enregistrée. L\'acheteur a été prévenu.');
    }
}
