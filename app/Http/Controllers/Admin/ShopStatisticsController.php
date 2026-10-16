<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\Shop;
use App\Services\ShopStatisticsService;
use App\Support\AssoScope;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * P8 — Statistiques boutiques dans l'administration : vue plateforme,
 * classement des boutiques, fiche détaillée, réglages de la collecte.
 */
class ShopStatisticsController extends Controller
{
    private const SORTABLE = [
        'visits' => 'Visites',
        'unique_visitors' => 'Visiteurs uniques',
        'product_views' => 'Produits consultés',
        'contacts' => 'Contacts',
        'orders' => 'Commandes',
        'items_sold' => 'Articles vendus',
        'revenue' => 'Chiffre d\'affaires',
        'conversion_rate' => 'Conversion',
    ];

    public function __construct(private ShopStatisticsService $stats)
    {
    }

    public function index(Request $request)
    {
        // Gestionnaire : totaux des seules boutiques ASSO.
        $summary = AssoScope::active()
            ? $this->stats->platformSummary($request->query('period', '30d'), Shop::pluck('id')->all(), [AssoScope::ownerId()])
            : $this->stats->platformSummary($request->query('period', '30d'));
        $period = $this->stats->resolvePeriod(
            $summary['period']['key'],
            ($first = Shop::min('created_at')) ? \Illuminate\Support\Carbon::parse($first) : null,
        );

        $sort = array_key_exists($request->query('sort'), self::SORTABLE) ? $request->query('sort') : 'revenue';
        $search = trim((string) $request->query('search', ''));

        $rows = $this->stats->perShop($period['from'], $period['to']);
        $shops = Shop::with('user:id,first_name,last_name')
            ->when($search !== '', fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', ['%' . mb_strtolower($search) . '%']))
            ->get(['id', 'name', 'user_id', 'status', 'logo', 'created_at'])
            ->map(function (Shop $shop) use ($rows) {
                $shop->setAttribute('stats', $rows[$shop->id] ?? []);
                return $shop;
            })
            ->sortByDesc(fn (Shop $shop) => [$shop->stats[$sort] ?? 0, $shop->stats['visits'] ?? 0])
            ->values();

        $perPage = 20;
        $page = max(1, (int) $request->query('page', 1));
        $paginator = new LengthAwarePaginator(
            $shops->forPage($page, $perPage)->values(),
            $shops->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );

        return view('admin.statistics.index', [
            'summary' => $summary,
            'shops' => $paginator,
            'sort' => $sort,
            'sortable' => self::SORTABLE,
            'search' => $search,
            'trackingEnabled' => $this->stats->trackingEnabled(),
            'dedupMinutes' => $this->stats->dedupMinutes(),
        ]);
    }

    public function show(Request $request, Shop $shop)
    {
        $shop->load('user:id,first_name,last_name,email,phone');

        return view('admin.statistics.show', [
            'shop' => $shop,
            'summary' => $this->stats->shopSummary($shop, $request->query('period', '30d')),
        ]);
    }

    public function updateSettings(Request $request)
    {
        $validated = $request->validate([
            'analytics_dedup_minutes' => 'required|integer|min:0|max:1440',
            'analytics_tracking_enabled' => 'nullable|boolean',
        ]);

        Setting::set('analytics_tracking_enabled', $request->boolean('analytics_tracking_enabled'), 'boolean', 'analytics',
            'Collecte des statistiques boutiques (visites, produits consultés, contacts)');
        Setting::set('analytics_dedup_minutes', (int) $validated['analytics_dedup_minutes'], 'integer', 'analytics',
            'Fenêtre (minutes) pendant laquelle un même visiteur n\'est compté qu\'une fois');

        return back()->with('success', 'Réglages des statistiques enregistrés.');
    }

    public function resetAudience(Shop $shop)
    {
        $deleted = $this->stats->resetAudience($shop);

        return back()->with('success', "Audience de « " . e($shop->name) . " » remise à zéro ({$deleted} événement(s) supprimé(s)). Les commandes et ventes ne sont pas modifiées.");
    }
}
