<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductBoost;
use App\Services\ProductBoostService;
use Illuminate\Http\Request;

/**
 * Asso Ads — suivi des campagnes de sponsoring côté vendeur.
 *
 * L'achat passe par POST /v1/packages/subscribe (rails wallet/kpay/stripe
 * communs à tous les forfaits) ; ce contrôleur ne sert qu'à consulter et à
 * piloter les campagnes.
 */
class ProductBoostController extends Controller
{
    public function __construct(protected ProductBoostService $boostService)
    {
    }

    /**
     * Campagnes du vendeur, la plus récente d'abord.
     * GET /v1/vendor/boosts
     */
    public function index(Request $request)
    {
        $limit = min(50, max(1, (int) $request->get('limit', 20)));

        return response()->json([
            'success' => true,
            'ads_enabled' => $this->boostService->enabled(),
            'boosts' => $this->boostService->historyFor($request->user(), $limit),
        ]);
    }

    /**
     * Détail d'une campagne : portée délivrée, personnes touchées, interactions.
     * GET /v1/vendor/boosts/{id}
     */
    public function show(Request $request, $id)
    {
        $boost = ProductBoost::with(['product.primaryImage', 'package'])->find($id);

        if (!$boost || (int) $boost->user_id !== (int) $request->user()->id) {
            return response()->json(['success' => false, 'message' => __('packages.boost_campaign_not_found')], 404);
        }

        return response()->json([
            'success' => true,
            'boost' => $this->boostService->campaignSummary($boost),
        ]);
    }

    /**
     * Éligibilité d'un produit au sponsoring, pour griser le bouton côté app.
     * GET /v1/vendor/products/{id}/boost-status
     */
    public function status(Request $request, $id)
    {
        $product = Product::find($id);

        if (!$product || (int) $product->user_id !== (int) $request->user()->id) {
            return response()->json(['success' => false, 'message' => __('products.not_found_short')], 404);
        }

        $active = $this->boostService->activeBoostFor($product);

        return response()->json([
            'success' => true,
            'is_boosted' => (bool) $active,
            'can_boost' => !$active && $product->status === 'active' && $product->shop_id !== null,
            'boost' => $active ? $this->boostService->campaignSummary($active) : null,
        ]);
    }

    /**
     * Arrêt anticipé d'une campagne. Sans remboursement : la portée déjà
     * délivrée a été consommée.
     * POST /v1/vendor/boosts/{id}/cancel
     */
    public function cancel(Request $request, $id)
    {
        $boost = ProductBoost::find($id);

        if (!$boost || (int) $boost->user_id !== (int) $request->user()->id) {
            return response()->json(['success' => false, 'message' => __('packages.boost_campaign_not_found')], 404);
        }

        if ($boost->status !== ProductBoost::ACTIVE) {
            return response()->json(['success' => false, 'message' => __('packages.boost_campaign_not_running')], 422);
        }

        $this->boostService->cancel($boost);

        return response()->json([
            'success' => true,
            'message' => __('packages.boost_campaign_stopped'),
            'boost' => $this->boostService->campaignSummary($boost->fresh()),
        ]);
    }
}
