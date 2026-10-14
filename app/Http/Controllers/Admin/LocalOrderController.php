<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Support\DeliveryPresenter;
use App\Support\LocalOrderStage;
use Illuminate\Http\Request;

/**
 * Commandes locales : commandes contenant au moins un produit fabriqué au Cameroun
 * (Order::has_local_products, figé à la commande). Les vendeurs les traitent comme
 * des commandes classiques ; ce menu sert au suivi par ASSO.
 */
class LocalOrderController extends Controller
{
    public function index(Request $request)
    {
        $stage = $request->input('stage', 'all');
        $query = Order::with(['user', 'items.product.primaryImage', 'items.seller'])
            ->withCount('items')
            ->where('has_local_products', true);
        LocalOrderStage::apply($query, $stage);

        if ($request->filled('search')) {
            $like = '%' . mb_strtolower(addcslashes(trim($request->input('search')), '%_\\')) . '%';
            $query->where(fn ($q) => $q->whereRaw('LOWER(order_number) LIKE ?', [$like])
                ->orWhere('customer_phone', 'like', $like)
                ->orWhereHas('user', fn ($u) => $u->whereRaw("LOWER(first_name || ' ' || last_name) LIKE ?", [$like])
                    ->orWhereRaw('LOWER(email) LIKE ?', [$like])
                    ->orWhere('phone', 'like', $like))
                ->orWhereHas('items.seller', fn ($s) => $s->whereRaw("LOWER(first_name || ' ' || last_name) LIKE ?", [$like])));
        }

        // Commandes payées et non annulées.
        $paid = Order::where('has_local_products', true)->where('payment_status', Order::PAYMENT_PAID)->where('status', '!=', 'cancelled');

        return view('admin.local_orders.index', [
            'orders' => $query->latest()->paginate(20)->withQueryString(),
            'stage' => $stage,
            'counts' => collect(array_keys(LocalOrderStage::STAGES))->mapWithKeys(fn ($s) => [
                $s => LocalOrderStage::apply(Order::where('has_local_products', true), $s)->count(),
            ]),
            'totals' => [
                'orders' => (clone $paid)->count(),
                'products' => (float) (clone $paid)->sum('subtotal'),
                'delivery' => (float) (clone $paid)->sum('delivery_fee'),
                'total' => (float) (clone $paid)->sum('total'),
            ],
        ]);
    }

    public function show(Order $order)
    {
        abort_unless($order->has_local_products, 404);
        $order->load(['user', 'items.product.primaryImage', 'items.seller', 'deliveryCompany', 'deliveryPerson', 'trackingEvents']);

        return view('admin.local_orders.show', [
            'order' => $order,
            'stage' => LocalOrderStage::of($order),
            'delivery' => DeliveryPresenter::forOrder($order),
        ]);
    }
}
