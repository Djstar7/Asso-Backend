<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ProductVideo;
use App\Services\ProductVideoUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Envoi asynchrone des vidéos produits depuis le formulaire admin
 * (voir ProductVideoUploadService, partagé avec l'app vendeur).
 */
class ProductVideoController extends Controller
{
    /**
     * Reçoit un morceau ; au dernier, assemble le fichier et lance le traitement.
     * POST /admin/product-videos/chunks
     */
    public function chunk(Request $request, ProductVideoUploadService $uploads): JsonResponse
    {
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
     * État du traitement, interrogé par le formulaire jusqu'à « prête ».
     * GET /admin/product-videos/{video}
     */
    public function show(ProductVideo $video): JsonResponse
    {
        return response()->json(['success' => true, 'video' => $this->present($video)]);
    }

    /**
     * Retire une vidéo (annulation depuis le formulaire, ou remplacement).
     * DELETE /admin/product-videos/{video}
     */
    public function destroy(ProductVideo $video): JsonResponse
    {
        $video->delete();

        return response()->json(['success' => true]);
    }

    /**
     * Fichiers de la vidéo pour l'aperçu admin, avant même que le produit existe.
     * GET /admin/product-videos/{video}/media/{kind}
     */
    public function media(ProductVideo $video, string $kind)
    {
        $path = $video->absolutePath($kind);
        abort_unless($path, 404);

        return response()->file($path, ['Cache-Control' => 'private, max-age=300']);
    }

    private function present(ProductVideo $video): array
    {
        $ready = $video->isReady();

        return [
            'id' => $video->id,
            'status' => $video->status,
            'error' => $video->error,
            'duration' => $video->duration,
            'width' => $video->width,
            'height' => $video->height,
            'size_bytes' => $video->size_bytes,
            'poster_url' => $video->poster_path
                ? route('admin.product-videos.media', [$video, 'poster'])
                : null,
            'video_url' => $ready ? route('admin.product-videos.media', [$video, 'video']) : null,
        ];
    }
}
