<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\GeminiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class AnalyzeProductController extends Controller
{
    protected GeminiService $geminiService;

    public function __construct(GeminiService $geminiService)
    {
        $this->geminiService = $geminiService;
    }

    /**
     * Analyze product image using Gemini Vision AI
     *
     * POST /api/v1/products/analyze
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function analyze(Request $request): JsonResponse
    {
        // Validate request
        $validator = Validator::make($request->all(), [
            'image' => 'required|file|image|mimes:jpeg,png,jpg,gif,webp|max:10240', // Max 10MB
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            // Get uploaded image
            $image = $request->file('image');

            // Store temporarily for analysis
            $tempPath = $image->getRealPath();

            Log::info('Starting product image analysis', [
                'filename' => $image->getClientOriginalName(),
                'size' => $image->getSize(),
                'mime' => $image->getMimeType(),
            ]);

            // Analyze image using Gemini
            $analysis = $this->geminiService->analyzeProductImage($tempPath);

            if (!$analysis['success']) {
                return response()->json([
                    'success' => false,
                    'message' => $analysis['error'] ?? 'Failed to analyze image',
                ], 500);
            }

            // Get available categories for client reference
            $categories = $this->geminiService->getAvailableCategories();

            return response()->json([
                'success' => true,
                'message' => 'Image analyzed successfully',
                'data' => [
                    'suggested_data' => $analysis['suggested_data'],
                    'confidence' => $analysis['confidence'],
                    'available_categories' => $categories,
                ],
                'meta' => [
                    'analyzed_at' => now()->toIso8601String(),
                    'original_filename' => $image->getClientOriginalName(),
                ],
            ], 200);

        } catch (\Exception $e) {
            Log::error('Product analysis error:', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while analyzing the image',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Get available categories and subcategories
     *
     * GET /api/v1/products/categories
     *
     * @return JsonResponse
     */
    public function categories(): JsonResponse
    {
        try {
            $categories = $this->geminiService->getAvailableCategories();

            return response()->json([
                'success' => true,
                'data' => $categories,
            ], 200);

        } catch (\Exception $e) {
            Log::error('Failed to fetch categories:', [
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch categories',
            ], 500);
        }
    }

    /**
     * Health check endpoint for Gemini service
     *
     * GET /api/v1/products/analyze/health
     *
     * @return JsonResponse
     */
    public function health(): JsonResponse
    {
        $geminiConfigured = !empty(config('gemini.api_key'));

        return response()->json([
            'success' => true,
            'service' => 'Gemini Vision AI',
            'status' => $geminiConfigured ? 'configured' : 'not_configured',
            'ready' => $geminiConfigured,
        ], 200);
    }
}
