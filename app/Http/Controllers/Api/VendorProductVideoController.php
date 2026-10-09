<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProductVideo;
use App\Services\ProductVideoUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Vidéo produit envoyée depuis l'app vendeur.
 *
 * Même parcours que le formulaire admin : la vidéo part par morceaux dès
 * qu'elle est choisie, puis son identifiant (`video_id`) accompagne
 * l'enregistrement du produit. Chaque vendeur ne voit que ses propres envois.
 */
class VendorProductVideoController extends Controller
{
    /**
     * Reçoit un morceau ; au dernier, assemble le fichier et lance le traitement.
     * POST /v1/vendor/product-videos/chunks
     */
    public function chunk(Request $request, ProductVideoUploadService $uploads): JsonResponse
    {
        // La vidéo sera décomptée du forfait : vérifié avant le premier morceau.
        if ((int) $request->input('index') === 0) {
            $uploads->ensureStorageFor($request->user(), (int) $request->input('size'));
        }

        $result = $uploads->receiveChunk($request);

        if (!$result['complete']) {
            return response()->json(['success' => true] + $result);
        }

        return response()->json([
            'success' => true,
            'complete' => true,
            'video' => $this->present($result['video']),
        ], 201);
    }

    /**
     * État du traitement (pending → processing → ready | failed).
     * GET /v1/vendor/product-videos/{video}
     */
    public function show(Request $request, ProductVideo $video): JsonResponse
    {
        $this->authorizeOwner($request, $video);

        return response()->json(['success' => true, 'video' => $this->present($video)]);
    }

    /**
     * Annule un envoi pas encore rattaché (choix abandonné dans le formulaire).
     * Une vidéo déjà rattachée se retire avec `remove_video` à l'enregistrement.
     * DELETE /v1/vendor/product-videos/{video}
     */
    public function destroy(Request $request, ProductVideo $video): JsonResponse
    {
        $this->authorizeOwner($request, $video);
        abort_if($video->product_id !== null, 409, 'Vidéo déjà rattachée à un produit.');

        $video->delete();

        return response()->json(['success' => true]);
    }

    private function authorizeOwner(Request $request, ProductVideo $video): void
    {
        abort_unless((int) $video->uploaded_by === $request->user()->id, 404);
    }

    private function present(ProductVideo $video): array
    {
        return [
            'id' => $video->id,
            'status' => $video->status,
            'error' => $video->error,
            'duration' => $video->duration,
            'width' => $video->width,
            'height' => $video->height,
            'size_bytes' => $video->size_bytes,
        ] + ($video->toApi() ?? []);
    }
}
