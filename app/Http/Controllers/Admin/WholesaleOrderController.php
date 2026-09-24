<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ImportCountry;
use App\Models\ImportShippingOption;
use App\Models\Order;
use App\Models\ProductPriceTier;
use App\Models\User;
use App\Services\OrderService;
use App\Services\OrderTrackingService;
use App\Support\DeliveryPresenter;
use App\Support\ImportHub;
use App\Support\WholesaleOrderStage;
use Illuminate\Http\Request;

/**
 * Commandes en gros (Chine, Dubaï, Turquie) : ASSO est le vendeur. Elle les valide,
 * les expédie jusqu'à l'entrepôt de Douala, puis SOLEX livre le client.
 */
class WholesaleOrderController extends Controller
{
    public function __construct(private OrderService $orders)
    {
    }

    public function index(Request $request)
    {
        $stage = $request->input('stage', 'all');
        $query = Order::with(['user', 'items.product.primaryImage', 'deliveryCompany'])
            ->withCount('items')
            ->where('is_wholesale', true);
        WholesaleOrderStage::apply($query, $stage);

        if ($request->filled('country')) {
            $query->where('import_country_code', strtoupper($request->input('country')));
        }
        if ($request->filled('search')) {
            $like = '%' . mb_strtolower(addcslashes(trim($request->input('search')), '%_\\')) . '%';
            $query->where(fn ($q) => $q->whereRaw('LOWER(order_number) LIKE ?', [$like])
                ->orWhereRaw('LOWER(carrier_tracking_number) LIKE ?', [$like])
                ->orWhere('customer_phone', 'like', $like)
                ->orWhereHas('user', fn ($u) => $u->whereRaw("LOWER(first_name || ' ' || last_name) LIKE ?", [$like])
                    ->orWhereRaw('LOWER(email) LIKE ?', [$like])
                    ->orWhere('phone', 'like', $like)));
        }

        // Commandes payées et non annulées : ce qu'ASSO encaisse.
        $paid = Order::where('is_wholesale', true)->where('payment_status', Order::PAYMENT_PAID)->where('status', '!=', 'cancelled');
        $importFees = (float) (clone $paid)->sum('import_shipping_fee');

        return view('admin.wholesale_orders.index', [
            'orders' => $query->latest()->paginate(20)->withQueryString(),
            'stage' => $stage,
            'counts' => collect(array_keys(WholesaleOrderStage::STAGES))->mapWithKeys(fn ($s) => [
                $s => WholesaleOrderStage::apply(Order::where('is_wholesale', true), $s)->count(),
            ]),
            'totals' => [
                'orders' => (clone $paid)->count(),
                'products' => (float) (clone $paid)->sum('subtotal'),
                'import' => $importFees,
                'local' => (float) (clone $paid)->sum('delivery_fee') - $importFees,
                'total' => (float) (clone $paid)->sum('total'),
            ],
            'countries' => ImportCountry::orderBy('sort_order')->orderBy('name')->get()->keyBy('code'),
        ]);
    }

    public function show(Order $order)
    {
        abort_unless($order->is_wholesale, 404);
        $order->load([
            'user', 'items.product.primaryImage', 'items.seller', 'deliveryCompany', 'deliveryPerson',
            'deliveryRoute', 'deliveryZone', 'trackingEvents',
        ]);

        return view('admin.wholesale_orders.show', [
            'order' => $order,
            'stage' => WholesaleOrderStage::of($order),
            'delivery' => DeliveryPresenter::forOrder($order),
            'pickup' => DeliveryPresenter::pickupFor($order),
            'shipping' => ImportShippingOption::find($order->shipping_option_id),
            'country' => ImportCountry::where('code', $order->import_country_code)->first(),
            // Poids d'une unité de chaque palier commandé (pack, bidon, pièce).
            'tiers' => ProductPriceTier::whereIn('id', $order->items->pluck('price_tier_id')->filter())->get()->keyBy('id'),
            'steps' => array_intersect_key(OrderTrackingService::STEPS, array_flip(array_merge(
                ['handed_to_carrier'], OrderTrackingService::carrierUpdateSteps($order), ['delivered']
            ))),
        ]);
    }

    /** ASSO valide la commande : réglée comme par le vendeur, acheteur prévenu. */
    public function confirm(Request $request, Order $order)
    {
        abort_unless($order->is_wholesale, 404);
        $seller = User::find($order->items()->value('seller_id')) ?? ImportHub::shop()?->user;

        try {
            $this->orders->confirmBySeller($order, $seller, 'admin', $request->user()?->id);
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Commande validée. Vous pouvez l’expédier depuis le pays d’origine.');
    }

    /** Refus d'une commande encore en attente : acheteur remboursé. */
    public function reject(Request $request, Order $order)
    {
        abort_unless($order->is_wholesale, 404);
        $validated = $request->validate(['reason' => 'required|string|max:500']);

        try {
            $refunded = $this->orders->rejectBySeller($order, $validated['reason'], 'admin', $request->user()?->id);
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $refunded > 0
            ? 'Commande refusée. ' . number_format($refunded, 0, ',', ' ') . ' FCFA rendus au client sur son Wallet ASSO.'
            : 'Commande refusée.');
    }
}
