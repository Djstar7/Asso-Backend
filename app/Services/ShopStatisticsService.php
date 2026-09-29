<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Setting;
use App\Models\Shop;
use App\Models\ShopAnalyticsEvent;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * P8 — Statistiques boutiques, source unique pour l'app vendeur et l'admin.
 *
 * - Audience (visites, produits consultés, contacts) : table shop_analytics_events,
 *   alimentée par ShopStatisticsService::record() avec dédoublonnage par visiteur.
 * - Commandes, ventes, chiffre d'affaires : order_items (seller_id) + orders.
 *   Le CA vendeur utilise le prix vendeur figé (seller_total_price), le prix
 *   client (total_price) donne le volume d'affaires ; l'écart = commission ASSO.
 */
class ShopStatisticsService
{
    /** Statuts comptés comme ventes (commande validée, non annulée). */
    public const SALE_STATUSES = ['confirmed', 'preparing', 'shipped', 'delivered', 'completed'];

    public const PERIODS = [
        '7d' => ['days' => 7, 'label' => '7 derniers jours'],
        '30d' => ['days' => 30, 'label' => '30 derniers jours'],
        '90d' => ['days' => 90, 'label' => '90 derniers jours'],
        '365d' => ['days' => 365, 'label' => '12 derniers mois'],
        'all' => ['days' => null, 'label' => 'Depuis le début'],
    ];

    public const DEFAULT_DEDUP_MINUTES = 30;

    // ------------------------------------------------------------------
    // Réglages (admin → Statistiques boutiques)
    // ------------------------------------------------------------------

    public function trackingEnabled(): bool
    {
        return (bool) Setting::get('analytics_tracking_enabled', true);
    }

    public function dedupMinutes(): int
    {
        return max(0, min(1440, (int) Setting::get('analytics_dedup_minutes', self::DEFAULT_DEDUP_MINUTES)));
    }

    // ------------------------------------------------------------------
    // Collecte
    // ------------------------------------------------------------------

    /**
     * Enregistre une interaction. Retourne false si elle est ignorée : suivi
     * désactivé, visite du propriétaire de la boutique, ou doublon du même
     * visiteur dans la fenêtre de dédoublonnage.
     */
    public function record(
        string $type,
        Shop $shop,
        ?Product $product,
        ?User $user,
        ?Request $request = null,
        string $source = 'app',
    ): bool {
        if (!in_array($type, ShopAnalyticsEvent::TYPES, true) || !$this->trackingEnabled()) {
            return false;
        }
        if ($user && (int) $user->id === (int) $shop->user_id) {
            return false;
        }

        $visitor = $this->visitorHash($user, $request);
        $window = $this->dedupMinutes();

        if ($window > 0) {
            $duplicate = ShopAnalyticsEvent::where('visitor_hash', $visitor)
                ->where('event_type', $type)
                ->where('shop_id', $shop->id)
                ->when($product, fn ($q) => $q->where('product_id', $product->id), fn ($q) => $q->whereNull('product_id'))
                ->where('created_at', '>=', now()->subMinutes($window))
                ->exists();
            if ($duplicate) {
                return false;
            }
        }

        ShopAnalyticsEvent::create([
            'shop_id' => $shop->id,
            'product_id' => $product?->id,
            'user_id' => $user?->id,
            'event_type' => $type,
            'visitor_hash' => $visitor,
            'source' => $source,
            'created_at' => now(),
        ]);

        return true;
    }

    private function visitorHash(?User $user, ?Request $request): string
    {
        if ($user) {
            return hash('sha256', 'user:' . $user->id);
        }

        // Invité : empreinte non réversible (IP + agent + identifiant d'appareil éventuel).
        $parts = [
            $request?->ip() ?? 'cli',
            $request?->userAgent() ?? '',
            $request?->header('X-Device-Id') ?? '',
        ];

        return hash('sha256', 'guest:' . implode('|', $parts) . '|' . config('app.key'));
    }

    // ------------------------------------------------------------------
    // Périodes
    // ------------------------------------------------------------------

    /**
     * @return array{key:string,label:string,from:?Carbon,to:Carbon,previous_from:?Carbon,previous_to:?Carbon,granularity:string}
     */
    public function resolvePeriod(?string $key, ?Carbon $allFrom = null): array
    {
        $key = array_key_exists((string) $key, self::PERIODS) ? $key : '30d';
        $days = self::PERIODS[$key]['days'];
        $to = now();

        if ($days === null) {
            $from = $allFrom?->copy()->startOfDay();
            $previousFrom = $previousTo = null;
            $spanDays = $from ? $from->diffInDays($to) + 1 : 0;
        } else {
            $from = $to->copy()->subDays($days - 1)->startOfDay();
            $previousTo = $from->copy()->subSecond();
            $previousFrom = $from->copy()->subDays($days);
            $spanDays = $days;
        }

        return [
            'key' => $key,
            'label' => __("shops.statistics.periods.{$key}"),
            'from' => $from,
            'to' => $to,
            'previous_from' => $previousFrom,
            'previous_to' => $previousTo,
            'granularity' => $spanDays > 90 ? 'month' : 'day',
        ];
    }

    // ------------------------------------------------------------------
    // Statistiques d'une boutique
    // ------------------------------------------------------------------

    public function shopSummary(Shop $shop, ?string $periodKey = '30d'): array
    {
        $period = $this->resolvePeriod($periodKey, $shop->created_at ? Carbon::parse($shop->created_at) : null);
        $sellerId = (int) $shop->user_id;

        $totals = $this->totals([$shop->id], [$sellerId], $period['from'], $period['to']);
        $previous = $period['previous_from']
            ? $this->totals([$shop->id], [$sellerId], $period['previous_from'], $period['previous_to'])
            : null;

        return [
            'period' => $this->periodPayload($period),
            'totals' => $totals,
            'previous' => $previous,
            'trends' => $previous ? $this->trends($totals, $previous) : null,
            'series' => $this->series([$shop->id], [$sellerId], $period),
            'top_products' => $this->topProducts($shop, $period['from'], $period['to']),
            'all_time' => $this->quickCounters($shop),
        ];
    }

    /** Compteurs cumulés affichés en permanence (tableau de bord vendeur). */
    public function quickCounters(Shop $shop): array
    {
        $sellerId = (int) $shop->user_id;
        $events = $this->eventCounts([$shop->id], null, null);
        $sales = $this->salesTotals([$sellerId], null, null);
        $weekFrom = now()->subDays(6)->startOfDay();

        return [
            'visits' => $events['visits'],
            'unique_visitors' => $events['unique_visitors'],
            'product_views' => $events['product_views'],
            'contacts' => $events['contacts'],
            'visits_last_7_days' => ShopAnalyticsEvent::where('shop_id', $shop->id)
                ->where('event_type', ShopAnalyticsEvent::SHOP_VIEW)
                ->where('created_at', '>=', $weekFrom)
                ->count(),
            'orders' => $sales['orders'],
            'pending_orders' => $sales['pending_orders'],
            'sales_count' => $sales['validated_orders'],
            'items_sold' => $sales['items_sold'],
            'revenue' => $sales['revenue'],
        ];
    }

    // ------------------------------------------------------------------
    // Vue plateforme (admin)
    // ------------------------------------------------------------------

    public function platformSummary(?string $periodKey = '30d'): array
    {
        $firstShop = Shop::min('created_at');
        $period = $this->resolvePeriod($periodKey, $firstShop ? Carbon::parse($firstShop) : null);

        $totals = $this->totals(null, null, $period['from'], $period['to']);
        $previous = $period['previous_from']
            ? $this->totals(null, null, $period['previous_from'], $period['previous_to'])
            : null;

        return [
            'period' => $this->periodPayload($period),
            'totals' => $totals,
            'previous' => $previous,
            'trends' => $previous ? $this->trends($totals, $previous) : null,
            'series' => $this->series(null, null, $period),
        ];
    }

    /**
     * Une ligne de statistiques par boutique sur la période (tri côté appelant).
     *
     * @return array<int, array> indexé par shop_id
     */
    public function perShop(?Carbon $from, ?Carbon $to): array
    {
        $events = ShopAnalyticsEvent::query()
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('created_at', '<=', $to))
            ->selectRaw('shop_id, event_type, COUNT(*) as c')
            ->groupBy('shop_id', 'event_type')
            ->get();

        $visitors = ShopAnalyticsEvent::query()
            ->whereIn('event_type', [ShopAnalyticsEvent::SHOP_VIEW, ShopAnalyticsEvent::PRODUCT_VIEW])
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('created_at', '<=', $to))
            ->selectRaw('shop_id, COUNT(DISTINCT visitor_hash) as c')
            ->groupBy('shop_id')
            ->pluck('c', 'shop_id');

        $sales = $this->salesQuery(null, $from, $to)
            ->selectRaw($this->salesSelect() . ', order_items.seller_id')
            ->groupBy('order_items.seller_id')
            ->get()
            ->keyBy('seller_id');

        $rows = [];
        foreach (Shop::select('id', 'user_id')->get() as $shop) {
            $shopEvents = $events->where('shop_id', $shop->id)->pluck('c', 'event_type');
            $sale = $sales->get($shop->user_id);
            $rows[$shop->id] = $this->composeTotals(
                [
                    'visits' => (int) ($shopEvents[ShopAnalyticsEvent::SHOP_VIEW] ?? 0),
                    'product_views' => (int) ($shopEvents[ShopAnalyticsEvent::PRODUCT_VIEW] ?? 0),
                    'contacts' => (int) ($shopEvents[ShopAnalyticsEvent::CONTACT] ?? 0),
                    'unique_visitors' => (int) ($visitors[$shop->id] ?? 0),
                ],
                $this->normaliseSales($sale),
            );
        }

        return $rows;
    }

    /** Supprime l'audience enregistrée d'une boutique (les ventes ne sont pas touchées). */
    public function resetAudience(Shop $shop): int
    {
        return ShopAnalyticsEvent::where('shop_id', $shop->id)->delete();
    }

    // ------------------------------------------------------------------
    // Calculs
    // ------------------------------------------------------------------

    private function periodPayload(array $period): array
    {
        return [
            'key' => $period['key'],
            'label' => $period['label'],
            'from' => $period['from']?->toIso8601String(),
            'to' => $period['to']->toIso8601String(),
            'granularity' => $period['granularity'],
            'available' => collect(self::PERIODS)->map(fn ($p, $k) => ['key' => $k, 'label' => __("shops.statistics.periods.{$k}")])->values()->all(),
        ];
    }

    /**
     * @param array<int>|null $shopIds   null = toute la plateforme
     * @param array<int>|null $sellerIds null = toute la plateforme
     */
    private function totals(?array $shopIds, ?array $sellerIds, ?Carbon $from, ?Carbon $to): array
    {
        return $this->composeTotals(
            $this->eventCounts($shopIds, $from, $to),
            $this->salesTotals($sellerIds, $from, $to),
        );
    }

    private function composeTotals(array $events, array $sales): array
    {
        $visitors = $events['unique_visitors'];
        $validated = $sales['validated_orders'];

        return array_merge($events, $sales, [
            'average_basket' => $validated > 0 ? round($sales['revenue'] / $validated, 2) : 0.0,
            'conversion_rate' => $visitors > 0 ? round(min(100, $validated / $visitors * 100), 1) : 0.0,
        ]);
    }

    private function eventCounts(?array $shopIds, ?Carbon $from, ?Carbon $to): array
    {
        $base = ShopAnalyticsEvent::query()
            ->when($shopIds !== null, fn ($q) => $q->whereIn('shop_id', $shopIds))
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('created_at', '<=', $to));

        $byType = (clone $base)
            ->selectRaw('event_type, COUNT(*) as c')
            ->groupBy('event_type')
            ->pluck('c', 'event_type');

        $uniqueVisitors = (clone $base)
            ->whereIn('event_type', [ShopAnalyticsEvent::SHOP_VIEW, ShopAnalyticsEvent::PRODUCT_VIEW])
            ->distinct()
            ->count('visitor_hash');

        return [
            'visits' => (int) ($byType[ShopAnalyticsEvent::SHOP_VIEW] ?? 0),
            'unique_visitors' => (int) $uniqueVisitors,
            'product_views' => (int) ($byType[ShopAnalyticsEvent::PRODUCT_VIEW] ?? 0),
            'contacts' => (int) ($byType[ShopAnalyticsEvent::CONTACT] ?? 0),
        ];
    }

    private function salesQuery(?array $sellerIds, ?Carbon $from, ?Carbon $to)
    {
        return DB::table('order_items')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->when($sellerIds !== null, fn ($q) => $q->whereIn('order_items.seller_id', $sellerIds))
            ->when($from, fn ($q) => $q->where('orders.created_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('orders.created_at', '<=', $to));
    }

    private function salesSelect(): string
    {
        $sale = "'" . implode("','", self::SALE_STATUSES) . "'";

        return implode(', ', [
            "COUNT(DISTINCT CASE WHEN orders.status <> 'cancelled' THEN orders.id END) as orders",
            "COUNT(DISTINCT CASE WHEN orders.status = 'pending' THEN orders.id END) as pending_orders",
            "COUNT(DISTINCT CASE WHEN orders.status = 'cancelled' THEN orders.id END) as cancelled_orders",
            "COUNT(DISTINCT CASE WHEN orders.status IN ($sale) THEN orders.id END) as validated_orders",
            "COALESCE(SUM(CASE WHEN orders.status IN ($sale) THEN order_items.quantity ELSE 0 END), 0) as items_sold",
            "COALESCE(SUM(CASE WHEN orders.status IN ($sale) THEN COALESCE(order_items.seller_total_price, order_items.total_price) ELSE 0 END), 0) as revenue",
            "COALESCE(SUM(CASE WHEN orders.status IN ($sale) THEN order_items.total_price ELSE 0 END), 0) as gross_sales",
        ]);
    }

    private function salesTotals(?array $sellerIds, ?Carbon $from, ?Carbon $to): array
    {
        return $this->normaliseSales(
            $this->salesQuery($sellerIds, $from, $to)->selectRaw($this->salesSelect())->first()
        );
    }

    private function normaliseSales(?object $row): array
    {
        $revenue = round((float) ($row->revenue ?? 0), 2);
        $gross = round((float) ($row->gross_sales ?? 0), 2);

        return [
            'orders' => (int) ($row->orders ?? 0),
            'pending_orders' => (int) ($row->pending_orders ?? 0),
            'cancelled_orders' => (int) ($row->cancelled_orders ?? 0),
            'validated_orders' => (int) ($row->validated_orders ?? 0),
            'items_sold' => (int) ($row->items_sold ?? 0),
            'revenue' => $revenue,
            'gross_sales' => $gross,
            'commission' => round(max(0, $gross - $revenue), 2),
        ];
    }

    private function trends(array $current, array $previous): array
    {
        $keys = ['visits', 'unique_visitors', 'product_views', 'contacts', 'orders', 'validated_orders', 'items_sold', 'revenue'];
        $trends = [];
        foreach ($keys as $key) {
            $before = (float) ($previous[$key] ?? 0);
            $now = (float) ($current[$key] ?? 0);
            $trends[$key] = $before > 0 ? round(($now - $before) / $before * 100, 1) : ($now > 0 ? null : 0.0);
        }

        return $trends;
    }

    /** Série chronologique (jour ou mois) : visites, consultations, commandes, CA. */
    private function series(?array $shopIds, ?array $sellerIds, array $period): array
    {
        $from = $period['from'];
        $to = $period['to'];

        $events = ShopAnalyticsEvent::query()
            ->when($shopIds !== null, fn ($q) => $q->whereIn('shop_id', $shopIds))
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
            ->where('created_at', '<=', $to)
            ->selectRaw('DATE(created_at) as d, event_type, COUNT(*) as c')
            ->groupBy(DB::raw('DATE(created_at)'), 'event_type')
            ->get();

        $sale = "'" . implode("','", self::SALE_STATUSES) . "'";
        $sales = $this->salesQuery($sellerIds, $from, $to)
            ->selectRaw("DATE(orders.created_at) as d,
                COUNT(DISTINCT CASE WHEN orders.status <> 'cancelled' THEN orders.id END) as orders,
                COALESCE(SUM(CASE WHEN orders.status IN ($sale) THEN COALESCE(order_items.seller_total_price, order_items.total_price) ELSE 0 END), 0) as revenue")
            ->groupBy(DB::raw('DATE(orders.created_at)'))
            ->get();

        $monthly = $period['granularity'] === 'month';
        $bucket = fn (string $date) => $monthly ? substr($date, 0, 7) : substr($date, 0, 10);

        // Squelette continu (jours ou mois sans activité = 0).
        $start = ($from ?? $to->copy()->subDays(29))->copy();
        $cursor = $monthly ? $start->copy()->startOfMonth() : $start->copy()->startOfDay();
        $points = [];
        while ($cursor->lte($to)) {
            $key = $monthly ? $cursor->format('Y-m') : $cursor->format('Y-m-d');
            $points[$key] = ['date' => $key, 'visits' => 0, 'product_views' => 0, 'contacts' => 0, 'orders' => 0, 'revenue' => 0.0];
            $monthly ? $cursor->addMonth() : $cursor->addDay();
        }

        $fields = [
            ShopAnalyticsEvent::SHOP_VIEW => 'visits',
            ShopAnalyticsEvent::PRODUCT_VIEW => 'product_views',
            ShopAnalyticsEvent::CONTACT => 'contacts',
        ];
        foreach ($events as $row) {
            $key = $bucket((string) $row->d);
            if (isset($points[$key], $fields[$row->event_type])) {
                $points[$key][$fields[$row->event_type]] += (int) $row->c;
            }
        }
        foreach ($sales as $row) {
            $key = $bucket((string) $row->d);
            if (isset($points[$key])) {
                $points[$key]['orders'] += (int) $row->orders;
                $points[$key]['revenue'] = round($points[$key]['revenue'] + (float) $row->revenue, 2);
            }
        }

        return array_values($points);
    }

    /** Produits les plus consultés / vendus de la boutique sur la période. */
    private function topProducts(Shop $shop, ?Carbon $from, Carbon $to, int $limit = 5): array
    {
        $views = ShopAnalyticsEvent::where('shop_id', $shop->id)
            ->where('event_type', ShopAnalyticsEvent::PRODUCT_VIEW)
            ->whereNotNull('product_id')
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
            ->where('created_at', '<=', $to)
            ->selectRaw('product_id, COUNT(*) as c')
            ->groupBy('product_id')
            ->pluck('c', 'product_id');

        $sale = "'" . implode("','", self::SALE_STATUSES) . "'";
        $sales = $this->salesQuery([(int) $shop->user_id], $from, $to)
            ->whereIn('order_items.product_id', $shop->products()->select('id'))
            ->selectRaw("order_items.product_id,
                COALESCE(SUM(CASE WHEN orders.status IN ($sale) THEN order_items.quantity ELSE 0 END), 0) as qty,
                COALESCE(SUM(CASE WHEN orders.status IN ($sale) THEN COALESCE(order_items.seller_total_price, order_items.total_price) ELSE 0 END), 0) as revenue")
            ->groupBy('order_items.product_id')
            ->get()
            ->keyBy('product_id');

        $ids = collect($views->keys())->merge($sales->keys())->unique()->values();
        if ($ids->isEmpty()) {
            return [];
        }

        $products = Product::whereIn('id', $ids)->get(['id', 'name', 'price'])->keyBy('id');

        return $ids
            ->filter(fn ($id) => $products->has($id))
            ->map(function ($id) use ($views, $sales, $products) {
                $viewCount = (int) ($views[$id] ?? 0);
                $sold = (int) ($sales->get($id)->qty ?? 0);

                return [
                    'product_id' => (int) $id,
                    'name' => $products[$id]->name,
                    'price' => (float) $products[$id]->price,
                    'views' => $viewCount,
                    'items_sold' => $sold,
                    'revenue' => round((float) ($sales->get($id)->revenue ?? 0), 2),
                    'conversion_rate' => $viewCount > 0 ? round(min(100, $sold / $viewCount * 100), 1) : 0.0,
                ];
            })
            ->sortByDesc(fn ($p) => [$p['views'], $p['items_sold']])
            ->take($limit)
            ->values()
            ->all();
    }
}
