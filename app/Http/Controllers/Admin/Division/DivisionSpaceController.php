<?php

namespace App\Http\Controllers\Admin\Division;

use App\Http\Controllers\Controller;
use App\Models\Dispute;
use App\Models\Division;
use App\Models\DivisionCommission;
use App\Models\Order;
use App\Models\RealEstate\Listing;
use App\Models\RealEstate\Reservation;
use App\Models\ShopAnalyticsEvent;
use App\Services\DivisionCommissionService;
use App\Support\DivisionScope;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Espace « Ma rubrique » du chef de rubrique : lecture seule, limité à sa
 * rubrique (DivisionScope). Les clients n'y apparaissent que par leur prénom et
 * l'initiale de leur nom ; ni téléphone, ni e-mail, ni adresse.
 */
class DivisionSpaceController extends Controller
{
    private const SETTLED = "orders.settled_at IS NOT NULL AND orders.refunded_at IS NULL AND orders.status <> 'cancelled'";

    private const PERIODS = ['7d' => 7, '30d' => 30, '90d' => 90, '365d' => 365, 'all' => null];

    public function dashboard(Request $request, DivisionCommissionService $commissions)
    {
        $division = DivisionScope::current($request);
        // Mandat terminé : il garde ses gains et son retrait.
        if (! $division) {
            return redirect()->route('admin.division.earnings.index');
        }
        [$from, $periodKey] = $this->period($request);

        $data = $division->isRealEstate()
            ? $this->realEstateKpis($from)
            : $this->catalogKpis($division, $from);

        return view('admin.division_space.dashboard', $data + $this->shared($request, $division) + [
            'periodKey' => $periodKey,
            'periods' => array_keys(self::PERIODS),
            'earnings' => $this->earningsSummary($request, $division, $commissions),
        ]);
    }

    public function orders(Request $request)
    {
        $division = DivisionScope::require($request);

        if ($division->isRealEstate()) {
            $reservations = Reservation::with('listing:id,title,city,type')
                ->whereNotNull('paid_at')
                ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
                ->latest('paid_at')->paginate(25)->withQueryString();

            return view('admin.division_space.reservations', $this->shared($request, $division) + compact('reservations'));
        }

        $ids = DivisionScope::categoryIds($division);
        $orders = DivisionScope::orders($division)
            ->with(['user:id,first_name,last_name', 'items' => fn ($q) => $q->whereIn('category_id', $ids)])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('search'), fn ($q) => $q->where('order_number', 'like', '%'.$request->search.'%'))
            ->latest()->paginate(25)->withQueryString();

        return view('admin.division_space.orders', $this->shared($request, $division) + compact('orders'));
    }

    public function order(Request $request, Order $order)
    {
        $division = DivisionScope::require($request);
        abort_if($division->isRealEstate() || ! DivisionScope::coversOrder($division, $order), 404);

        $ids = DivisionScope::categoryIds($division);
        $items = $order->items()->whereIn('category_id', $ids)->with('product:id,name')->get();
        $lineCommissions = DivisionCommission::where('source_type', DivisionCommission::SOURCE_ORDER_ITEM)
            ->whereIn('source_id', $items->pluck('id'))->get()->keyBy('source_id');
        $otherLines = $order->items()->whereNotIn('category_id', $ids)->count()
            + $order->items()->whereNull('category_id')->count();

        return view('admin.division_space.order', $this->shared($request, $division) + compact('order', 'items', 'lineCommissions', 'otherLines'));
    }

    public function products(Request $request)
    {
        $division = DivisionScope::require($request);

        if ($division->isRealEstate()) {
            $listings = Listing::query()
                ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
                ->when($request->filled('search'), fn ($q) => $q->where('title', 'like', '%'.$request->search.'%'))
                ->latest()->paginate(25)->withQueryString();

            return view('admin.division_space.listings', $this->shared($request, $division) + compact('listings'));
        }

        $settled = self::SETTLED;
        $products = DivisionScope::products($division)
            ->with(['shop:id,name', 'category:id,name', 'primaryImage'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', '%'.$request->search.'%'))
            ->addSelect(['sold' => DB::table('order_items')->join('orders', 'orders.id', '=', 'order_items.order_id')
                ->whereColumn('order_items.product_id', 'products.id')->whereRaw($settled)
                ->selectRaw('COALESCE(SUM(order_items.quantity), 0)')])
            ->addSelect(['views' => ShopAnalyticsEvent::query()->whereColumn('product_id', 'products.id')
                ->where('event_type', ShopAnalyticsEvent::PRODUCT_VIEW)->selectRaw('COUNT(*)')])
            ->latest()->paginate(25)->withQueryString();

        return view('admin.division_space.products', $this->shared($request, $division) + compact('products'));
    }

    public function traffic(Request $request)
    {
        $division = DivisionScope::require($request);
        [$from, $periodKey] = $this->period($request);

        if ($division->isRealEstate()) {
            $top = Listing::orderByDesc('views_count')->limit(20)->get(['id', 'title', 'city', 'views_count', 'status']);

            return view('admin.division_space.traffic', $this->shared($request, $division) + [
                'periodKey' => $periodKey, 'periods' => array_keys(self::PERIODS),
                'realEstateTop' => $top, 'daily' => [], 'sources' => collect(), 'top' => collect(), 'totals' => null,
            ]);
        }

        $views = $this->viewsQuery($division, $from);
        $daily = (clone $views)->selectRaw('DATE(shop_analytics_events.created_at) as day, COUNT(*) as views, COUNT(DISTINCT visitor_hash) as visitors')
            ->groupBy('day')->orderBy('day')->get();
        $sources = (clone $views)->selectRaw("COALESCE(source, 'direct') as source, COUNT(*) as views")
            ->groupBy('source')->orderByDesc('views')->get();
        $topIds = (clone $views)->selectRaw('product_id, COUNT(*) as views')->groupBy('product_id')
            ->orderByDesc('views')->limit(20)->pluck('views', 'product_id');
        $sold = $this->itemsQuery($division, $from)->whereIn('order_items.product_id', $topIds->keys())
            ->selectRaw('order_items.product_id, SUM(order_items.quantity) as qty')->groupBy('order_items.product_id')->pluck('qty', 'product_id');
        $names = DivisionScope::products($division)->withTrashed()->whereIn('id', $topIds->keys())->pluck('name', 'id');
        $top = $topIds->map(fn ($v, $id) => [
            'name' => $names[$id] ?? 'Produit retiré',
            'views' => (int) $v,
            'sold' => (int) ($sold[$id] ?? 0),
            'conversion' => $v > 0 ? round(min(100, ($sold[$id] ?? 0) / $v * 100), 1) : 0,
        ])->values();

        return view('admin.division_space.traffic', $this->shared($request, $division) + [
            'periodKey' => $periodKey, 'periods' => array_keys(self::PERIODS),
            'daily' => $daily, 'sources' => $sources, 'top' => $top, 'realEstateTop' => null,
            'totals' => ['views' => (int) $daily->sum('views'), 'visitors' => (int) (clone $views)->distinct()->count('visitor_hash')],
        ]);
    }

    public function disputes(Request $request)
    {
        $division = DivisionScope::require($request);
        abort_if($division->isRealEstate(), 404);

        $disputes = Dispute::withoutGlobalScopes()
            ->whereIn('order_item_id', DivisionScope::items($division)->select('order_items.id'))
            ->with(['item:id,product_name,quantity,total_price', 'order:id,order_number'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->latest()->paginate(25)->withQueryString();

        return view('admin.division_space.disputes', $this->shared($request, $division) + compact('disputes'));
    }

    public function earnings(Request $request, DivisionCommissionService $service)
    {
        $user = $request->user();
        $division = DivisionScope::current($request);
        $query = $user->isAdmin()
            ? DivisionCommission::query()->when($division, fn ($q) => $q->where('division_id', $division->id))
            : DivisionCommission::where('user_id', $user->id);

        $commissions = $query->with('user:id,first_name,last_name')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->latest('sold_at')->paginate(30)->withQueryString();

        return view('admin.division_space.earnings', $this->shared($request, $division) + [
            'commissions' => $commissions,
            'earnings' => $this->earningsSummary($request, $division, $service),
            'balance' => $user->isAdmin() ? null : $user->kpayAvailableFor('XAF'),
        ]);
    }

    // ------------------------------------------------------------------

    private function shared(Request $request, ?Division $division): array
    {
        return [
            'division' => $division,
            'divisions' => DivisionScope::divisionsFor($request->user()),
            'query' => $division ? ['division' => $division->id] : [],
        ];
    }

    private function period(Request $request): array
    {
        $key = array_key_exists($request->input('period'), self::PERIODS) ? $request->input('period') : '30d';
        $days = self::PERIODS[$key];

        return [$days ? now()->subDays($days)->startOfDay() : null, $key];
    }

    private function itemsQuery(Division $division, ?Carbon $from)
    {
        return DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereIn('order_items.category_id', DivisionScope::categoryIds($division))
            ->whereRaw(self::SETTLED)
            ->when($from, fn ($q) => $q->where('orders.created_at', '>=', $from));
    }

    private function viewsQuery(Division $division, ?Carbon $from)
    {
        return ShopAnalyticsEvent::query()
            ->where('shop_analytics_events.event_type', ShopAnalyticsEvent::PRODUCT_VIEW)
            ->whereIn('shop_analytics_events.product_id', DB::table('products')->whereIn('category_id', DivisionScope::categoryIds($division))->select('id'))
            ->when($from, fn ($q) => $q->where('shop_analytics_events.created_at', '>=', $from));
    }

    private function catalogKpis(Division $division, ?Carbon $from): array
    {
        $sales = $this->itemsQuery($division, $from)->selectRaw('
            COALESCE(SUM(order_items.total_price), 0) as revenue,
            COALESCE(SUM(order_items.total_price - COALESCE(order_items.seller_total_price, order_items.total_price)), 0) as asso_commission,
            COALESCE(SUM(order_items.quantity), 0) as quantity,
            COUNT(DISTINCT order_items.order_id) as orders')->first();

        // Commandes payées pas encore réglées (en cours de validation vendeur).
        $inProgress = DB::table('order_items')->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereIn('order_items.category_id', DivisionScope::categoryIds($division))
            ->whereNull('orders.settled_at')->where('orders.payment_status', Order::PAYMENT_PAID)
            ->whereNotIn('orders.status', ['cancelled'])
            ->distinct()->count('order_items.order_id');

        $views = $this->viewsQuery($division, $from)->count();
        $topProducts = $this->itemsQuery($division, $from)
            ->selectRaw('order_items.product_id, MAX(order_items.product_name) as name, SUM(order_items.quantity) as qty, SUM(order_items.total_price) as revenue')
            ->groupBy('order_items.product_id')->orderByDesc('revenue')->limit(5)->get();

        return [
            'kpis' => [
                'revenue' => (float) $sales->revenue,
                'asso_commission' => (float) $sales->asso_commission,
                'orders' => (int) $sales->orders,
                'quantity' => (int) $sales->quantity,
                'in_progress' => $inProgress,
                'views' => $views,
                'conversion' => $views > 0 ? round(min(100, $sales->quantity / $views * 100), 1) : 0.0,
                'products' => DivisionScope::products($division)->where('status', 'active')->count(),
            ],
            'topProducts' => $topProducts,
        ];
    }

    private function realEstateKpis(?Carbon $from): array
    {
        $base = Reservation::whereIn('status', Reservation::PAID_STATUSES)
            ->when($from, fn ($q) => $q->where('paid_at', '>=', $from));

        return [
            'kpis' => [
                'revenue' => (float) (clone $base)->sum('total_amount'),
                'asso_commission' => (float) (clone $base)->sum('platform_amount'),
                'orders' => (clone $base)->count(),
                'quantity' => (clone $base)->where('status', 'released')->count(),
                'in_progress' => Reservation::whereIn('status', ['paid', 'disputed'])->count(),
                'views' => (int) Listing::sum('views_count'),
                'conversion' => 0.0,
                'products' => Listing::where('status', Listing::STATUS_PUBLISHED)->count(),
            ],
            'topProducts' => collect(),
        ];
    }

    private function earningsSummary(Request $request, ?Division $division, DivisionCommissionService $service): array
    {
        $user = $request->user();
        if (! $user->isAdmin()) {
            return $service->summaryFor($user);
        }
        $base = DivisionCommission::when($division, fn ($q) => $q->where('division_id', $division->id));

        return [
            'pending' => round((float) (clone $base)->where('status', DivisionCommission::STATUS_PENDING)->sum('amount'), 2),
            'available' => round((float) (clone $base)->where('status', DivisionCommission::STATUS_AVAILABLE)->sum('amount'), 2),
            'clawback_due' => round((float) (clone $base)->sum('clawback_due'), 2),
            'count' => (clone $base)->count(),
        ];
    }
}
