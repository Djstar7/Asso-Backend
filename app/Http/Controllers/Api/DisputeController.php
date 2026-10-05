<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Dispute;
use App\Models\Order;
use App\Services\DisputeService;
use Illuminate\Http\Request;

/**
 * Réclamations côté client : signaler un problème sur un article pendant les 48 h,
 * suivre le dossier, valider ou refuser le remplacement, puis voir les produits
 * similaires (livraison offerte par leur vendeur) après remboursement.
 */
class DisputeController extends Controller
{
    public function __construct(private DisputeService $disputes)
    {
    }

    /** GET /api/v1/disputes */
    public function index(Request $request)
    {
        $disputes = Dispute::where('client_id', $request->user()->id)
            ->latest()
            ->get()
            ->map(fn (Dispute $d) => $this->disputes->toApi($d, 'client'));

        return response()->json(['success' => true, 'disputes' => $disputes]);
    }

    /** GET /api/v1/disputes/{id} */
    public function show(Request $request, $id)
    {
        $dispute = $this->find($request, $id);

        return response()->json(['success' => true, 'dispute' => $this->disputes->toApi($dispute, 'client')]);
    }

    /** POST /api/v1/orders/{id}/disputes (multipart : photos[]) */
    public function store(Request $request, $orderId)
    {
        $validated = $request->validate([
            'order_item_id' => 'required|integer',
            'reason' => 'required|in:' . implode(',', Dispute::REASONS),
            'description' => 'required|string|min:10|max:2000',
            'photos' => 'nullable|array|max:6',
            'photos.*' => 'image|max:8192',
        ]);

        $order = Order::where('user_id', $request->user()->id)->findOrFail($orderId);

        try {
            $dispute = $this->disputes->open(
                $order,
                (int) $validated['order_item_id'],
                $request->user(),
                $validated['reason'],
                $validated['description'],
                $request->file('photos', []),
            );
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => __('disputes.opened'),
            'dispute' => $this->disputes->toApi($dispute->fresh(), 'client'),
        ], 201);
    }

    /** POST /api/v1/disputes/{id}/confirm-replacement */
    public function confirmReplacement(Request $request, $id)
    {
        $dispute = $this->find($request, $id);
        try {
            $dispute = $this->disputes->confirmReplacement($dispute, $request->user());
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => __('disputes.conformity_thanks'),
            'dispute' => $this->disputes->toApi($dispute, 'client'),
        ]);
    }

    /** POST /api/v1/disputes/{id}/report-replacement (multipart : photos[]) */
    public function reportReplacement(Request $request, $id)
    {
        $validated = $request->validate([
            'description' => 'required|string|min:10|max:2000',
            'photos' => 'nullable|array|max:6',
            'photos.*' => 'image|max:8192',
        ]);
        $dispute = $this->find($request, $id);

        try {
            $this->disputes->reportReplacement($dispute, $request->user(), $validated['description'], $request->file('photos', []));
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => __('disputes.return_validated'),
            'dispute' => $this->disputes->toApi($dispute->fresh(), 'client'),
        ]);
    }

    /**
     * Après remboursement : produits similaires dont le vendeur offre la livraison.
     *
     * GET /api/v1/disputes/{id}/similar-products
     */
    public function similarProducts(Request $request, $id)
    {
        $dispute = $this->find($request, $id);
        if ($dispute->status !== Dispute::STATUS_REFUNDED) {
            return response()->json(['success' => false, 'message' => __('disputes.action_unavailable')], 422);
        }

        $products = $this->disputes->similarProducts($dispute);

        return response()->json([
            'success' => true,
            'products' => app(ProductController::class)->cards($products, $request->user()),
        ]);
    }

    private function find(Request $request, $id): Dispute
    {
        return Dispute::where('client_id', $request->user()->id)->findOrFail($id);
    }
}
