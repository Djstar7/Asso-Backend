<?php

namespace App\Services;

use App\Jobs\ProcessProductVideo;
use App\Models\Product;
use App\Models\ProductVideo;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Envoi par morceaux et rattachement des vidéos produits, communs au
 * formulaire admin et à l'app vendeur.
 *
 * La vidéo part dès qu'elle est choisie, par morceaux de quelques Mo : pas de
 * limite `upload_max_filesize` à relever, une barre de progression réelle, et
 * le formulaire reste utilisable pendant l'envoi. Le produit n'existe pas
 * encore : la vidéo lui est rattachée à l'enregistrement (champ `video_id`).
 */
class ProductVideoUploadService
{
    /** Taille maximale d'un morceau, en Ko (les clients envoient des morceaux de 4 Mo). */
    public const MAX_CHUNK_KB = 8192;

    /**
     * Reçoit un morceau ; au dernier, assemble le fichier et lance le traitement.
     *
     * Renvoie `['complete' => false, 'received' => n, 'total' => n]` tant qu'il
     * manque des morceaux, puis `['complete' => true, 'video' => ProductVideo]`.
     * Les erreurs remontent en réponse JSON 409/422.
     */
    public function receiveChunk(Request $request): array
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
            $this->fail('Format non pris en charge (' . implode(', ', ProductVideo::EXTENSIONS) . ').');
        }

        if ($data['index'] >= $data['total']) {
            $this->fail('Morceau hors limites.');
        }

        // Morceaux rangés à part, hors du disque public, un fichier par index :
        // renvoyer un morceau après une coupure réseau l'écrase sans doublon.
        $local = Storage::disk('local');
        $dir = "video-uploads/{$data['upload_id']}";
        $local->putFileAs($dir, $request->file('chunk'), sprintf('%05d', $data['index']));

        $received = count($local->files($dir));
        if ($received < $data['total']) {
            return ['complete' => false, 'received' => $received, 'total' => $data['total']];
        }

        $userId = $request->user()?->id;

        // Dernier morceau : une seule requête assemble, même si deux arrivent ensemble.
        $video = Cache::lock("product-video-upload:{$data['upload_id']}", 60)->block(10, function () use ($local, $dir, $data, $extension, $userId) {
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
                $this->fail('Envoi incomplet, veuillez réessayer.');
            }

            return ProductVideo::create([
                'uploaded_by' => $userId,
                'original_path' => $target,
                'original_name' => Str::limit($data['name'], 250, ''),
                'size_bytes' => $size,
                'status' => ProductVideo::PENDING,
            ]);
        });

        if (!$video) {
            $this->fail('Envoi déjà traité.', 409);
        }

        ProcessProductVideo::dispatch($video->id);

        return ['complete' => true, 'video' => $video->fresh()];
    }

    /** Taille d'une vidéo telle qu'envoyée, en Mo (base du décompte du forfait). */
    public static function sizeMb(int $bytes): float
    {
        return $bytes / 1048576;
    }

    /**
     * Espace du forfait que coûtera le rattachement de la vidéo `$videoId`,
     * envoyée par `$ownerId` et pas encore décomptée. 0 si rien à décompter.
     */
    public function pendingChargeMb(?int $videoId, int $ownerId): float
    {
        if (!$videoId) {
            return 0.0;
        }

        $video = ProductVideo::whereKey($videoId)->where('uploaded_by', $ownerId)->first();
        if (!$video || $video->storage_charged_mb > 0) {
            return 0.0;
        }

        return self::sizeMb((int) $video->size_bytes);
    }

    /**
     * Rattache la vidéo envoyée au produit, ou retire l'ancienne.
     *
     * Une nouvelle vidéo remplace l'ancienne ; `$remove` retire l'ancienne sans
     * la remplacer. Avec `$ownerId`, seule une vidéo envoyée par ce compte peut
     * être rattachée (app vendeur) et sa taille est décomptée du forfait du
     * vendeur, comme les photos ; l'espace est rendu quand la vidéo est
     * retirée (voir ProductVideo::refundStorage). L'admin peut rattacher toute
     * vidéo libre, sans décompte.
     */
    public function syncForProduct(Product $product, ?int $newId, bool $remove, ?int $ownerId = null): void
    {
        $current = ProductVideo::where('product_id', $product->id)->get();

        if ($remove || ($newId && !$current->contains('id', $newId))) {
            $current->reject(fn (ProductVideo $v) => $v->id === $newId)->each->delete();
        }

        if (!$newId) {
            return;
        }

        // Seule une vidéo encore libre (ou déjà la sienne) peut être rattachée.
        $video = ProductVideo::whereKey($newId)
            ->where(fn ($q) => $q->whereNull('product_id')->orWhere('product_id', $product->id))
            ->when($ownerId !== null, fn ($q) => $q->where('uploaded_by', $ownerId))
            ->first();

        if (!$video) {
            return;
        }

        $attributes = ['product_id' => $product->id];

        if ($ownerId !== null && $video->storage_charged_mb <= 0) {
            $package = User::find($ownerId)?->activeVendorPackage;
            if ($package) {
                $charge = self::sizeMb((int) $video->size_bytes);
                $package->deductStorage($charge);
                $attributes['storage_charged_mb'] = $charge;
            }
        }

        $video->update($attributes);
    }

    /**
     * Refuse un envoi vendeur dès le premier morceau quand le forfait ne peut
     * pas l'accueillir : inutile de faire partir 100 Mo pour rien.
     */
    public function ensureStorageFor(User $user, int $bytes): void
    {
        $package = $user->activeVendorPackage;
        if (!$package) {
            abort(response()->json([
                'success' => false,
                'message' => __('products.storage_package_required'),
                'error_code' => 'NO_ACTIVE_PACKAGE',
            ], 403));
        }

        $neededMb = self::sizeMb($bytes);
        if (!$package->hasEnoughStorage($neededMb)) {
            abort(response()->json([
                'success' => false,
                'message' => __('products.storage_insufficient_subscribe'),
                'error_code' => 'INSUFFICIENT_STORAGE',
                'required_mb' => round($neededMb, 2),
                'available_mb' => round($package->storage_remaining_mb, 2),
            ], 403));
        }
    }

    private function fail(string $message, int $status = 422): never
    {
        abort(response()->json(['success' => false, 'message' => $message], $status));
    }
}
