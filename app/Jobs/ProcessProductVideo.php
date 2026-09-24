<?php

namespace App\Jobs;

use App\Models\ProductVideo;
use App\Services\VideoProcessingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Prépare une vidéo produit envoyée depuis l'admin :
 *   1. vérifie que le fichier contient bien une vidéo (ffprobe) ;
 *   2. extrait l'affiche ;
 *   3. produit la boucle légère des cartes ;
 *   4. produit la version de la fiche (copie réorganisée si déjà lisible
 *      partout, sinon ré-encodage 720p).
 *
 * Tout échec après l'étape 1 retombe sur le fichier reçu : mieux vaut publier
 * l'original que pas de vidéo du tout.
 */
class ProcessProductVideo implements ShouldQueue
{
    use Queueable;

    /** Une seule tentative : une vidéo qui échoue échouera encore. */
    public int $tries = 1;

    /** Sous le `retry_after` (90 s) de la file database, voir config/services.php. */
    public int $timeout = 85;

    public function __construct(public int $videoId)
    {
    }

    public function handle(VideoProcessingService $media): void
    {
        $video = ProductVideo::find($this->videoId);
        if (!$video || !$video->original_path) {
            return;
        }

        $disk = Storage::disk('public');
        if (!$disk->exists($video->original_path)) {
            $video->update(['status' => ProductVideo::FAILED, 'error' => 'Fichier reçu introuvable.']);
            return;
        }

        $video->update(['status' => ProductVideo::PROCESSING, 'error' => null]);

        // Sans ffmpeg (serveur non équipé) : on publie le fichier tel quel.
        if (!$media->available()) {
            Log::warning("[Video] ffmpeg absent : vidéo {$video->id} publiée sans conversion.");
            $video->update(['status' => ProductVideo::READY]);
            return;
        }

        $input = $disk->path($video->original_path);
        $probe = $media->probe($input);
        if (!$probe['has_video']) {
            $video->update([
                'status' => ProductVideo::FAILED,
                'error' => 'Ce fichier ne contient pas de vidéo lisible.',
            ]);
            return;
        }

        $video->update([
            'width' => $probe['width'],
            'height' => $probe['height'],
            'duration' => $probe['duration'],
        ]);

        $dir = "products/videos/{$video->id}";
        $disk->makeDirectory($dir);

        // Affiche : 1 s après le début (souvent un fondu au noir avant), au milieu d'un clip très court.
        $posterAt = min(1.0, ($probe['duration'] ?? 2) / 2);
        if ($media->poster($input, $disk->path("{$dir}/poster.jpg"), $posterAt)) {
            $video->update(['poster_path' => "{$dir}/poster.jpg"]);
        }

        if ($media->preview($input, $disk->path("{$dir}/preview.mp4"))) {
            $video->update(['preview_path' => "{$dir}/preview.mp4"]);
        }

        $full = "{$dir}/video.mp4";
        $converted = $media->isStreamable($probe)
            ? $media->remux($input, $disk->path($full))
            : $media->transcode($input, $disk->path($full));

        if ($converted) {
            $out = $media->probe($disk->path($full));
            $disk->delete($video->original_path);
            $video->update([
                'path' => $full,
                'original_path' => null,
                'width' => $out['width'] ?? $video->width,
                'height' => $out['height'] ?? $video->height,
                'duration' => $out['duration'] ?? $video->duration,
                'size_bytes' => $disk->size($full),
            ]);
        } else {
            Log::warning("[Video] Conversion de la vidéo {$video->id} impossible : original conservé.");
        }

        $video->update(['status' => ProductVideo::READY]);
    }

    /**
     * Délai dépassé ou erreur imprévue : l'original, s'il est là, reste lisible.
     */
    public function failed(?Throwable $e): void
    {
        $video = ProductVideo::find($this->videoId);
        if (!$video) {
            return;
        }

        Log::error("[Video] Traitement de la vidéo {$video->id} échoué: " . ($e?->getMessage() ?? 'inconnu'));

        $hasOriginal = $video->original_path && Storage::disk('public')->exists($video->original_path);
        $video->update($hasOriginal || $video->path
            ? ['status' => ProductVideo::READY]
            : ['status' => ProductVideo::FAILED, 'error' => 'Traitement de la vidéo impossible.']);
    }
}
