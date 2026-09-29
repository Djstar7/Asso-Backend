<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ProductLookupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Fonctionnalité 3 — fiche produit pré-remplie à partir d'un scan.
 *
 * Le mobile n'envoie que ce que ML Kit a extrait sur le téléphone (pas
 * d'image) ; voir ProductLookupService.
 */
class ProductScanController extends Controller
{
    public function __construct(private ProductLookupService $lookup)
    {
    }

    /**
     * POST /api/v1/products/scan-lookup
     */
    public function lookup(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'barcode' => 'nullable|string|max:32|required_without_all:ocr_text,labels',
            'barcode_format' => 'nullable|string|max:20',
            'ocr_text' => 'nullable|string|max:5000',
            'ocr_lines' => 'nullable|array|max:200',
            'ocr_lines.*' => 'string|max:300',
            'labels' => 'nullable|array|max:20',
            'labels.*.text' => 'required|string|max:80',
            'labels.*.confidence' => 'required|numeric|between:0,1',
        ]);

        $result = $this->lookup->lookup($validated);

        return response()->json([
            'success' => true,
            'data' => $result,
        ]);
    }
}
