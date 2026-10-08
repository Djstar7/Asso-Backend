<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Shop;
use App\Models\ShopAnalyticsEvent;
use App\Services\ShopStatisticsService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * P8 — Statistiques boutiques côté API :
 *  - POST /v1/analytics/track          : collecte (public, invités compris)
 *  - GET  /v1/vendor/statistics        : tableau de bord du vendeur
 *  - GET  /v1/vendor/statistics/export : rapport CSV ou PDF de la période
 */
class ShopStatisticsController extends Controller
{
    public function __construct(private ShopStatisticsService $stats)
    {
    }

    /** POST /v1/analytics/track {event, product_id?, shop_id?} */
    public function track(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'event' => 'required|in:' . implode(',', ShopAnalyticsEvent::TYPES),
            'product_id' => 'nullable|integer|required_if:event,' . ShopAnalyticsEvent::PRODUCT_VIEW,
            'shop_id' => 'nullable|integer',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
            ], 422);
        }

        $product = $request->filled('product_id') ? Product::find($request->integer('product_id')) : null;
        $shop = $product?->shop_id
            ? Shop::find($product->shop_id)
            : ($request->filled('shop_id') ? Shop::find($request->integer('shop_id')) : null);

        if (!$shop) {
            return response()->json(['success' => false, 'message' => __('shops.not_found_short')], 404);
        }

        // Route publique : l'utilisateur connecté est identifié s'il envoie son jeton.
        $user = $request->user() ?? auth('sanctum')->user();
        $recorded = $this->stats->record($request->input('event'), $shop, $product, $user, $request);

        return response()->json(['success' => true, 'recorded' => $recorded]);
    }

    /** GET /v1/vendor/statistics?period=7d|30d|90d|365d|all */
    public function vendor(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user->hasAnyRole(['vendeur', 'vendor'])) {
            return response()->json(['success' => false, 'message' => __('shops.not_a_vendor')], 403);
        }

        $shop = $user->primaryShop;
        if (!$shop) {
            return response()->json(['success' => false, 'message' => __('shops.no_shop_found')], 404);
        }

        return response()->json([
            'success' => true,
            'data' => array_merge(
                ['shop' => ['id' => $shop->id, 'name' => $shop->name]],
                $this->stats->shopSummary($shop, $request->query('period', '30d')),
            ),
        ]);
    }

    /**
     * GET /v1/vendor/statistics/export?period=30d&format=csv|pdf
     *
     * Reprend exactement le résumé affiché à l'écran : le vendeur retrouve
     * dans son rapport les chiffres qu'il vient de consulter.
     */
    public function export(Request $request): StreamedResponse|JsonResponse
    {
        $shop = $this->vendorShop($request);
        if ($shop instanceof JsonResponse) {
            return $shop;
        }

        $format = strtolower((string) $request->query('format', 'csv'));
        if (!in_array($format, ['csv', 'pdf'], true)) {
            return response()->json([
                'success' => false,
                'message' => __('shops.statistics.unsupported_format'),
            ], 422);
        }

        $summary = $this->stats->shopSummary($shop, $request->query('period', '30d'));
        $slug = \Illuminate\Support\Str::slug($shop->name) ?: 'boutique';
        $stamp = now()->format('Y-m-d');

        return $format === 'pdf'
            ? $this->exportPdf($shop, $summary, "statistiques-{$slug}-{$stamp}.pdf")
            : $this->exportCsv($shop, $summary, "statistiques-{$slug}-{$stamp}.csv");
    }

    /** Boutique du vendeur authentifié, ou la réponse d'erreur à renvoyer. */
    private function vendorShop(Request $request): Shop|JsonResponse
    {
        $user = $request->user();
        if (!$user->hasAnyRole(['vendeur', 'vendor'])) {
            return response()->json(['success' => false, 'message' => __('shops.not_a_vendor')], 403);
        }

        $shop = $user->primaryShop;
        if (!$shop) {
            return response()->json(['success' => false, 'message' => __('shops.no_shop_found')], 404);
        }

        return $shop;
    }

    /**
     * Rapport CSV.
     *
     * Séparateur « ; » et BOM UTF-8 : sans eux, Excel en configuration
     * française ouvre le fichier sur une seule colonne et casse les accents.
     */
    private function exportCsv(Shop $shop, array $summary, string $filename): StreamedResponse
    {
        return response()->streamDownload(function () use ($shop, $summary) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");

            $line = fn (array $cells) => fputcsv($out, $cells, ';');

            $line([__('shops.statistics.export.shop'), $shop->name]);
            $line([__('shops.statistics.export.period'), $summary['period']['label'] ?? '']);
            $line([__('shops.statistics.export.from'), $this->humanDate($summary['period']['from'] ?? null)]);
            $line([__('shops.statistics.export.to'), $this->humanDate($summary['period']['to'] ?? null)]);
            $line([__('shops.statistics.export.generated_at'), now()->format('d/m/Y H:i')]);
            $line([]);

            $totals = $summary['totals'] ?? [];
            $line([__('shops.statistics.export.indicator'), __('shops.statistics.export.value')]);
            foreach ($this->totalLabels() as $key => $label) {
                $line([$label, $this->number($totals[$key] ?? 0)]);
            }
            $line([]);

            $line([(($summary['period']['granularity'] ?? 'day') === 'month')
                ? __('shops.statistics.export.detail_by_month')
                : __('shops.statistics.export.detail_by_day')]);
            $line([
                __('shops.statistics.export.date'),
                __('shops.statistics.export.visits'),
                __('shops.statistics.export.product_views'),
                __('shops.statistics.export.contacts'),
                __('shops.statistics.export.orders'),
                __('shops.statistics.export.revenue'),
            ]);
            foreach ($summary['series'] ?? [] as $point) {
                $line([
                    $point['date'] ?? '',
                    $point['visits'] ?? 0,
                    $point['product_views'] ?? 0,
                    $point['contacts'] ?? 0,
                    $point['orders'] ?? 0,
                    $this->number($point['revenue'] ?? 0),
                ]);
            }

            if (!empty($summary['top_products'])) {
                $line([]);
                $line([__('shops.statistics.export.top_products')]);
                $line([
                    __('shops.statistics.export.product'),
                    __('shops.statistics.export.views'),
                    __('shops.statistics.export.sold'),
                    __('shops.statistics.export.revenue'),
                    __('shops.statistics.labels.conversion_rate'),
                ]);
                foreach ($summary['top_products'] as $product) {
                    $line([
                        $product['name'] ?? '',
                        $product['views'] ?? 0,
                        $product['items_sold'] ?? 0,
                        $this->number($product['revenue'] ?? 0),
                        $this->number($product['conversion_rate'] ?? 0),
                    ]);
                }
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /** Rapport PDF, rendu depuis une vue Blade dédiée. */
    private function exportPdf(Shop $shop, array $summary, string $filename): StreamedResponse
    {
        $pdf = Pdf::loadView('reports.shop-statistics', [
            'shop' => $shop,
            'summary' => $summary,
            'labels' => $this->totalLabels(),
            'generatedAt' => now(),
        ])->setPaper('a4', 'portrait');

        return response()->streamDownload(
            fn () => print($pdf->output()),
            $filename,
            ['Content-Type' => 'application/pdf'],
        );
    }

    /** Libellés des indicateurs, partagés par le CSV et le PDF. */
    private function totalLabels(): array
    {
        return [
            'visits' => __('shops.statistics.labels.visits'),
            'unique_visitors' => __('shops.statistics.labels.unique_visitors'),
            'product_views' => __('shops.statistics.labels.product_views'),
            'contacts' => __('shops.statistics.labels.contacts'),
            'orders' => __('shops.statistics.labels.orders'),
            'validated_orders' => __('shops.statistics.labels.validated_orders'),
            'pending_orders' => __('shops.statistics.labels.pending_orders'),
            'cancelled_orders' => __('shops.statistics.labels.cancelled_orders'),
            'items_sold' => __('shops.statistics.labels.items_sold'),
            'revenue' => __('shops.statistics.labels.revenue'),
            'gross_sales' => __('shops.statistics.labels.gross_sales'),
            'commission' => __('shops.statistics.labels.commission'),
            'average_basket' => __('shops.statistics.labels.average_basket'),
            'conversion_rate' => __('shops.statistics.labels.conversion_rate'),
        ];
    }

    private function number(mixed $value): string
    {
        return number_format((float) $value, 2, ',', ' ');
    }

    private function humanDate(?string $iso): string
    {
        return $iso ? \Illuminate\Support\Carbon::parse($iso)->format('d/m/Y') : '—';
    }
}
