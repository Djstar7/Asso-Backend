<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\InvalidSalesCodeException;
use App\Exceptions\OwnSalesCodeException;
use App\Http\Controllers\Controller;
use App\Services\SalesCommissionService;
use Illuminate\Http\Request;

/**
 * P6 — Vérification d'un code commercial avant la souscription d'un forfait.
 * N'expose que le nom abrégé du commercial (« Prénom N. »). Son propre code n'est pas
 * une erreur : `is_own_code` et `warning` permettent à l'app de demander confirmation.
 */
class SalesCodeController extends Controller
{
    public function __construct(protected SalesCommissionService $salesCommissionService) {}

    /** GET /v1/sales-codes/{code} */
    public function show(Request $request, string $code)
    {
        if (mb_strlen($code) > 32) {
            return response()->json(['success' => false, 'message' => __('packages.sales_code_unknown')], 404);
        }

        try {
            $agent = $this->salesCommissionService->resolveForVendor($code, $request->user());
        } catch (OwnSalesCodeException $e) {
            $agent = $this->salesCommissionService->findActiveByCode($code);

            return response()->json([
                'success' => true,
                'data' => [
                    'code' => $agent->code,
                    'agent_display_name' => $agent->display_name,
                    'is_own_code' => true,
                    'warning' => $e->getMessage(),
                ],
            ]);
        } catch (InvalidSalesCodeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 404);
        }

        if (! $agent) {
            return response()->json(['success' => false, 'message' => __('packages.sales_code_required')], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'code' => $agent->code,
                'agent_display_name' => $agent->display_name,
                'is_own_code' => false,
            ],
        ]);
    }
}
