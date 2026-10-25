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
            'size' => 'required|integer|min:1|max:'.(ProductVideo::MAX_SIZE_MB * 1024 * 1024),
            'name' => 'required|string|max:255',
            'chunk' => 'required|file|max:'.self::MAX_CHUNK_KB,
        ], [
            'size.max' => 'La vidéo dépasse '.ProductVideo::MAX_SIZE_MB.' Mo.',
        ]);

        $extension = strtolower(pathinfo($data['name'], PATHINFO_EXTENSION));
        if (! in_array($extension, ProductVideo::EXTENSIONS, true)) {
            $this->fail('Format non pris en charge ('.implode(', ', ProductVideo::EXTENSIONS).').');
        }

        if ($data['index'] >= $data['total']) {
            $this->fail('Morceau hors limites.');
        }
        if ($request->file('chunk')->getSize() < 1) {
            $this->fail('Le morceau est vide.');
        }

        $user = $request->user();
        abort_unless($user, 401);
        if (! $user->isBackofficeStaff()) {
            $this->ensureStorageFor($user, (int) $data['size']);
        }
        $created = false;
        try {
            $result = Cache::lock("product-video-upload:{$data['upload_id']}", 120)->block(10, function () use ($request, $data, $extension, $user, &$created) {
                // Persistent completion marker makes a final-chunk retry idempotent.
                $existing = ProductVideo::where('upload_id', $data['upload_id'])->first();
                if ($existing) {
                    abort_unless((int) $existing->uploaded_by === $user->id, 404);
                    if ((int) $existing->size_bytes !== (int) $data['size'] || $existing->original_name !== Str::limit($data['name'], 250, '')) {
                        $this->fail('Les paramètres de cet envoi ont changé.', 409);
                    }

                    return ['complete' => true, 'video' => $existing];
                }
                $local = Storage::disk('local');
                $dir = "video-uploads/{$data['upload_id']}";
                $manifest = $dir.'/session.json';
                $expected = ['owner' => $user->id, 'total' => (int) $data['total'], 'size' => (int) $data['size'], 'name' => $data['name']];
                if ($local->exists($manifest)) {
                    $session = json_decode($local->get($manifest), true);
                    abort_unless(($session['owner'] ?? null) === $user->id, 404);
                    if ($session !== $expected) {
                        $this->fail('Les paramètres de cet envoi ont changé.', 409);
                    }
                } else {
                    if ((int) $data['index'] !== 0) {
                        $this->fail('Commencez l’envoi par le premier morceau.', 409);
                    }
                    if (! $local->put($manifest, json_encode($expected, JSON_THROW_ON_ERROR))) {
                        throw new \RuntimeException('Impossible de préparer l’envoi.');
                    }
                }
                $parts = array_values(array_filter($local->files($dir), fn ($path) => preg_match('/^[0-9]{5}$/', basename($path))));
                $chunkPath = $dir.'/'.sprintf('%05d', $data['index']);
                $bytes = array_sum(array_map(fn ($path) => $local->size($path), $parts));
                $bytes -= $local->exists($chunkPath) ? $local->size($chunkPath) : 0;
                $bytes += $request->file('chunk')->getSize();
                if ($bytes > (int) $data['size'] || $bytes > ProductVideo::MAX_SIZE_MB * 1048576) {
                    $this->fail('La taille cumulée des morceaux dépasse la taille autorisée.');
                }
                if (! $local->putFileAs($dir, $request->file('chunk'), basename($chunkPath))) {
                    throw new \RuntimeException('Impossible d’enregistrer le morceau.');
                }
                $received = count($parts) + (in_array($chunkPath, $parts, true) ? 0 : 1);
                if ($received < (int) $data['total']) {
                    return ['complete' => false, 'received' => $received, 'total' => (int) $data['total']];
                }
                if ($bytes !== (int) $data['size']) {
                    $this->fail('Envoi incomplet, veuillez réessayer.');
                }
                $target = 'products/videos/uploads/'.Str::uuid().'.'.$extension;
                $public = Storage::disk('public');
                if (! $public->makeDirectory(dirname($target))) {
                    throw new \RuntimeException('Impossible de préparer le fichier.');
                }
                $out = null;
                try {
                    $out = fopen($public->path($target), 'xb');
                    if (! is_resource($out)) {
                        throw new \RuntimeException('Impossible de créer la vidéo.');
                    }
                    for ($i = 0; $i < (int) $data['total']; $i++) {
                        $part = $dir.'/'.sprintf('%05d', $i);
                        if (! $local->exists($part)) {
                            $this->fail('Un morceau est manquant.', 409);
                        }
                        $in = $local->readStream($part);
                        if (! is_resource($in)) {
                            throw new \RuntimeException('Impossible de lire le morceau.');
                        }
                        try {
                            if (stream_copy_to_stream($in, $out) !== $local->size($part)) {
                                throw new \RuntimeException('Écriture incomplète de la vidéo.');
                            }
                        } finally {
                            fclose($in);
                        }
                    }
                    fclose($out);
                    $out = null;
                    if ($public->size($target) !== $bytes) {
                        throw new \RuntimeException('Écriture incomplète de la vidéo.');
                    }
                    $video = ProductVideo::create([
                        'upload_id' => $data['upload_id'], 'uploaded_by' => $user->id,
                        'original_path' => $target, 'original_name' => Str::limit($data['name'], 250, ''),
                        'size_bytes' => $bytes, 'status' => ProductVideo::PENDING,
                    ]);
                } catch (\Throwable $e) {
                    if (is_resource($out)) {
                        fclose($out);
                    }
                    SafeUpload::delete($target);
                    throw $e;
                }
                $created = true;
                $local->deleteDirectory($dir);

                return ['complete' => true, 'video' => $video];
            });
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException $e) {
            $this->fail('Un morceau est encore en cours d’enregistrement. Réessayez.', 409);
        }
        if ($created) {
            try {
                ProcessProductVideo::dispatch($result['video']->id);
            } catch (\Throwable $exception) {
                report($exception);
                $result['video']->update(['status' => ProductVideo::FAILED, 'error' => 'Le traitement de la vidéo n’a pas pu démarrer. Réessayez plus tard.']);
            }
        }
        if ($result['complete']) {
            $result['video'] = $result['video']->fresh();
        }

        return $result;
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
        if (! $videoId) {
            return 0.0;
        }

        $video = ProductVideo::whereKey($videoId)->where('uploaded_by', $ownerId)->first();
        if (! $video || $video->storage_charged_mb > 0) {
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
        $video = null;
        if ($newId) {
            $video = ProductVideo::whereKey($newId)
                ->where(fn ($q) => $q->whereNull('product_id')->orWhere('product_id', $product->id))
                ->when($ownerId !== null, fn ($q) => $q->where('uploaded_by', $ownerId))
                ->first();
            if ($video && \App\Support\AssoScope::restricts(auth()->user()) && $video->product_id === null && (int) $video->uploaded_by !== auth()->id()) {
                $video = null;
            }
            if (! $video && $ownerId !== null) {
                return;
            }
            if (! $video) {
                throw \Illuminate\Validation\ValidationException::withMessages(['video_id' => 'Cette vidéo n’est pas disponible pour ce produit.']);
            }
        }
        $current = ProductVideo::where('product_id', $product->id)->get();
        if ($remove || ($newId && ! $current->contains('id', $newId))) {
            $current->reject(fn (ProductVideo $v) => $v->id === $newId)->each->delete();
        }
        if (! $video) {
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
        if (! $package) {
            abort(response()->json([
                'success' => false,
                'message' => __('products.storage_package_required'),
                'error_code' => 'NO_ACTIVE_PACKAGE',
            ], 403));
        }

        $neededMb = self::sizeMb($bytes);
        if (! $package->hasEnoughStorage($neededMb)) {
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
