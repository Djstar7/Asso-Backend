<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Shop;
use App\Models\User;
use App\Services\OrderService;
use App\Support\AssoScope;
use App\Support\DeliveryPresenter;
use App\Support\LocalOrderStage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Rubrique « Boutiques ASSO » : commandes passées dans les boutiques ASSO (ASSO
 * Fashion, ASSO Tech…). ASSO y joue le rôle du vendeur : elle confirme ou refuse
 * la commande payée, comme un vendeur depuis l'app. À la confirmation, l'acheteur
 * reçoit son code de livraison et la demande de course part vers les livreurs du
 * partenaire choisi. Les commandes en gros ont leur propre menu.
 */
class AssoOrderController extends Controller
{
    public function __construct(private OrderService $orders)
    {
    }

    public function index(Request $request)
    {
        $stage = $request->input('stage', 'to_confirm');
        $shopId = $request->integer('shop') ?: null;

        $query = $this->base($shopId)
            ->with(['user', 'items.product.primaryImage', 'items.product.shop'])
            ->withCount('items');
        LocalOrderStage::apply($query, $stage);

        if ($request->filled('search')) {
            $like = '%' . mb_strtolower(addcslashes(trim($request->input('search')), '%_\\')) . '%';
            $query->where(fn ($q) => $q->whereRaw('LOWER(order_number) LIKE ?', [$like])
                ->orWhere('customer_phone', 'like', $like)
                ->orWhereHas('user', fn ($u) => $u->whereRaw("LOWER(first_name || ' ' || last_name) LIKE ?", [$like])
                    ->orWhere('phone', 'like', $like)));
        }

        $paid = $this->base($shopId)->where('payment_status', Order::PAYMENT_PAID)->where('status', '!=', 'cancelled');

        return view('admin.asso_orders.index', [
            'orders' => $query->latest()->paginate(20)->withQueryString(),
            'stage' => $stage,
            'shopId' => $shopId,
            'shops' => Shop::where('user_id', AssoScope::ownerId())->orderBy('name')->get(['id', 'name']),
            'counts' => collect(array_keys(LocalOrderStage::STAGES))->mapWithKeys(fn ($s) => [
                $s => LocalOrderStage::apply($this->base($shopId), $s)->count(),
            ]),
            'totals' => [
                'orders' => (clone $paid)->count(),
                'products' => (float) (clone $paid)->sum('subtotal'),
                'total' => (float) (clone $paid)->sum('total'),
            ],
        ]);
    }

    public function show(Order $order)
    {
        $this->ensureAssoOrder($order);
        $order->load(['user', 'items.product.primaryImage', 'items.product.shop', 'deliveryCompany', 'deliveryPerson', 'trackingEvents']);

        return view('admin.asso_orders.show', [
            'order' => $order,
            'stage' => LocalOrderStage::of($order),
            'delivery' => DeliveryPresenter::forOrder($order),
        ]);
    }

    /** ASSO confirme la commande payée, comme le vendeur depuis l'app. */
    public function confirm(Request $request, Order $order)
    {
        $this->ensureAssoOrder($order);
        $vendor = User::find(AssoScope::ownerId());
        abort_unless($vendor, 404);

        try {
            $this->orders->confirmBySeller($order, $vendor, 'admin', $request->user()->id);
            $this->orders->requestDelivery($order);
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $order->isCarrierDelivery()
            ? 'Commande confirmée : le client est prévenu. Remettez le colis au transporteur (menu Expéditions).'
            : 'Commande confirmée : le client a reçu son code de livraison et la demande de course est partie vers les livreurs.');
    }

    /** Refus d'une commande encore à confirmer : le client est remboursé. */
    public function reject(Request $request, Order $order)
    {
        $this->ensureAssoOrder($order);
        $validated = $request->validate(['reason' => 'required|string|max:500']);

        try {
            $refunded = $this->orders->rejectBySeller($order, $validated['reason'], 'admin', $request->user()->id);
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $refunded > 0
            ? 'Commande refusée. ' . number_format($refunded, 0, ',', ' ') . ' FCFA rendus au client sur son Wallet ASSO.'
            : 'Commande refusée.');
    }

    // ------------------------------------------------------------------

    private function base(?int $shopId = null): Builder
    {
        return AssoScope::shopOrders()
            ->when($shopId, fn ($q) => $q->whereHas('items.product', fn ($p) => $p->where('shop_id', $shopId)));
    }

    private function ensureAssoOrder(Order $order): void
    {
        abort_unless($this->base()->whereKey($order->id)->exists(), 404);
    }
}
