<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SalesCommissionService;
use Illuminate\Http\Request;

/**
 * « Devenir un commercial ASSO » : modalités du programme et inscription depuis
 * l'application. Le code obtenu est saisi par les vendeurs à leur premier forfait.
 */
class SalesProgramController extends Controller
{
    public function __construct(protected SalesCommissionService $salesCommissionService) {}

    /** GET /v1/sales-program */
    public function show(Request $request)
    {
        $agent = $this->salesCommissionService->agentFor($request->user());

        return response()->json([
            'success' => true,
            'data' => [
                'terms' => $this->salesCommissionService->programTerms(),
                'commission_rate' => $agent
                    ? $this->salesCommissionService->rateFor($agent)
                    : $this->salesCommissionService->defaultRate(),
                'is_agent' => $agent !== null,
                'is_active' => (bool) $agent?->is_active,
                'code' => $agent?->code,
            ],
        ]);
    }

    /** POST /v1/sales-program/enroll */
    public function enroll(Request $request)
    {
        $request->validate(['accepted' => 'required|accepted']);

        $agent = $this->salesCommissionService->enroll($request->user());

        if (! $agent->is_active) {
            return response()->json([
                'success' => false,
                'code' => 'sales_agent_inactive',
                'message' => __('sales_program.inactive'),
            ], 403);
        }

        return response()->json([
            'success' => true,
            'message' => __('sales_program.enrolled'),
            'data' => [
                'code' => $agent->code,
                'commission_rate' => $this->salesCommissionService->rateFor($agent),
            ],
        ]);
    }
}
