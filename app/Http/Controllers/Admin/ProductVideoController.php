<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessProductVideo;
use App\Models\ProductVideo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Envoi asynchrone des vidéos produits depuis le formulaire admin.
 *
 * La vidéo part dès qu'elle est choisie, par morceaux de quelques Mo : pas de
 * limite `upload_max_filesize` à relever, une barre de progression réelle, et
 * le formulaire reste utilisable pendant l'envoi. Le produit n'existe pas
 * encore : la vidéo lui est rattachée à l'enregistrement (champ `video_id`).
 */
class ProductVideoController extends Controller
{
    /** Taille maximale d'un morceau, en Ko (le navigateur envoie des morceaux de 4 Mo). */
    private const MAX_CHUNK_KB = 8192;

    /**
     * Reçoit un morceau ; au dernier, assemble le fichier et lance le traitement.
     * POST /admin/product-videos/chunks
     */
    public function chunk(Request $request): JsonResponse
    {
        $data = $request->validate([
            'upload_id' => 'required|uuid',
            'index' => 'required|integer|min:0',
            'total' => 'required|integer|min:1|max:500',
            'size' => 'required|integer|min:1|max:' . (ProductVideo::MAX_SIZE_MB * 1024 * 1024),
            'name' => 'required|string|max:255',
            'chunk' => 'required|file|max:' . self::MAX_CHUNK_KB,
        ], [
            'size.max' => 'La vidéo dépasse ' . ProductVideo::MAX_SIZE_MB . ' Mo.',
        ]);

        $extension = strtolower(pathinfo($data['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, ProductVideo::EXTENSIONS, true)) {
            return response()->json([
                'success' => false,
                'message' => 'Format non pris en charge (' . implode(', ', ProductVideo::EXTENSIONS) . ').',
            ], 422);
        }

        if ($data['index'] >= $data['total']) {
            return response()->json(['success' => false, 'message' => 'Morceau hors limites.'], 422);
        }

        // Morceaux rangés à part, hors du disque public, un fichier par index :
        // renvoyer un morceau après une coupure réseau l'écrase sans doublon.
        $local = Storage::disk('local');
        $dir = "video-uploads/{$data['upload_id']}";
        $local->putFileAs($dir, $request->file('chunk'), sprintf('%05d', $data['index']));

        $received = count($local->files($dir));
        if ($received < $data['total']) {
            return response()->json([
                'success' => true,
                'complete' => false,
                'received' => $received,
                'total' => $data['total'],
            ]);
        }

        // Dernier morceau : une seule requête assemble, même si deux arrivent ensemble.
        $video = Cache::lock("product-video-upload:{$data['upload_id']}", 60)->block(10, function () use ($local, $dir, $data, $extension, $request) {
            if (!$local->exists($dir)) {
                return null; // déjà assemblé par la requête concurrente
            }

            $target = 'products/videos/uploads/' . Str::uuid() . '.' . $extension;
            $public = Storage::disk('public');
            $public->makeDirectory(dirname($target));

            $out = fopen($public->path($target), 'wb');
            for ($i = 0; $i < $data['total']; $i++) {
                $in = fopen($local->path($dir . '/' . sprintf('%05d', $i)), 'rb');
                stream_copy_to_stream($in, $out);
                fclose($in);
            }
            fclose($out);
            $local->deleteDirectory($dir);

            $size = $public->size($target);
            if ($size !== (int) $data['size']) {
                $public->delete($target);
                abort(response()->json([
                    'success' => false,
                    'message' => 'Envoi incomplet, veuillez réessayer.',
                ], 422));
            }

            return ProductVideo::create([
                'uploaded_by' => $request->user()?->id,
                'original_path' => $target,
                'original_name' => Str::limit($data['name'], 250, ''),
                'size_bytes' => $size,
                'status' => ProductVideo::PENDING,
            ]);
        });

        if (!$video) {
            return response()->json(['success' => false, 'message' => 'Envoi déjà traité.'], 409);
        }

        ProcessProductVideo::dispatch($video->id);

        return response()->json([
            'success' => true,
            'complete' => true,
            'video' => $this->present($video->fresh()),
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
