<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * Appels à ffmpeg / ffprobe pour les vidéos produits.
 *
 * Les commandes sont passées en tableau (jamais par un shell) : un nom de
 * fichier ne peut rien injecter. Chaque étape renvoie un booléen au lieu de
 * lever : le job décide du repli (publier l'original plutôt que rien).
 */
class VideoProcessingService
{
    /** Plus grand côté de la version « fiche » : 1280 → 720 × 1280 en vertical. */
    private const FULL_LONG_SIDE = 1280;

    /** Plus grand côté de l'aperçu des cartes : 640 → 360 × 640 en vertical. */
    private const PREVIEW_LONG_SIDE = 640;

    /** Durée de la boucle lue sur les cartes. */
    public const PREVIEW_SECONDS = 8;

    private ?bool $available = null;

    public function available(): bool
    {
        if ($this->available !== null) {
            return $this->available;
        }

        return $this->available = $this->run([$this->ffprobe(), '-version'], 10)
            && $this->run([$this->ffmpeg(), '-version'], 10);
    }

    /**
     * Dimensions AFFICHÉES (rotation du téléphone appliquée), durée et pistes.
     *
     * @return array{has_video: bool, has_audio: bool, width: ?int, height: ?int, duration: ?float,
     *                video_codec: ?string, audio_codec: ?string, pix_fmt: ?string}
     */
    public function probe(string $path): array
    {
        $empty = [
            'has_video' => false, 'has_audio' => false, 'width' => null, 'height' => null, 'duration' => null,
            'video_codec' => null, 'audio_codec' => null, 'pix_fmt' => null,
        ];

        $process = new Process([
            $this->ffprobe(), '-v', 'error', '-print_format', 'json',
            '-show_format', '-show_streams', $path,
        ]);
        $process->setTimeout(30);

        try {
            $process->run();
        } catch (\Throwable $e) {
            Log::warning('[Video] ffprobe impossible: ' . $e->getMessage());
            return $empty;
        }

        $data = json_decode($process->getOutput(), true);
        if (!$process->isSuccessful() || !is_array($data)) {
            return $empty;
        }

        $streams = collect($data['streams'] ?? []);
        $video = $streams->first(fn ($s) => ($s['codec_type'] ?? null) === 'video'
            // Une pochette d'album est un flux vidéo d'une seule image : on l'ignore.
            && empty($s['disposition']['attached_pic']));

        if (!$video) {
            return $empty;
        }

        $width = isset($video['width']) ? (int) $video['width'] : null;
        $height = isset($video['height']) ? (int) $video['height'] : null;

        // Vidéo tournée par le téléphone : largeur et hauteur sont inversées à l'affichage.
        $rotation = (int) ($video['tags']['rotate'] ?? 0);
        foreach ($video['side_data_list'] ?? [] as $side) {
            if (isset($side['rotation'])) {
                $rotation = (int) $side['rotation'];
            }
        }
        if (abs($rotation) % 180 === 90) {
            [$width, $height] = [$height, $width];
        }

        $duration = $data['format']['duration'] ?? $video['duration'] ?? null;
        $audio = $streams->first(fn ($s) => ($s['codec_type'] ?? null) === 'audio');

        return [
            'has_video' => true,
            'has_audio' => $audio !== null,
            'width' => $width,
            'height' => $height,
            'duration' => is_numeric($duration) ? round((float) $duration, 2) : null,
            'video_codec' => $video['codec_name'] ?? null,
            'audio_codec' => $audio['codec_name'] ?? null,
            'pix_fmt' => $video['pix_fmt'] ?? null,
        ];
    }

    /**
     * Déjà lisible partout (H.264 8 bits, AAC ou muet) et pas plus grand que la
     * version fiche : ré-encoder ne ferait que grossir le fichier ou le dégrader.
     * Les vidéos passées par WhatsApp, très courantes chez les fournisseurs,
     * sont dans ce cas.
     */
    public function isStreamable(array $probe): bool
    {
        $longSide = max((int) $probe['width'], (int) $probe['height']);

        return $probe['has_video']
            && $probe['video_codec'] === 'h264'
            && $probe['pix_fmt'] === 'yuv420p'
            && in_array($probe['audio_codec'], [null, 'aac'], true)
            && $longSide > 0 && $longSide <= self::FULL_LONG_SIDE;
    }

    /**
     * Même contenu, sans ré-encodage : seul l'index (moov) passe en tête du
     * fichier, pour que la lecture démarre pendant le téléchargement.
     */
    public function remux(string $input, string $output): bool
    {
        return $this->run([
            $this->ffmpeg(), '-y', '-v', 'error',
            '-i', $input,
            '-map', '0:v:0', '-map', '0:a:0?',
            '-c', 'copy',
            '-movflags', '+faststart',
            $output,
        ], $this->timeout());
    }

    /** Affiche JPEG prise à [$atSeconds]. */
    public function poster(string $input, string $output, float $atSeconds = 1.0): bool
    {
        return $this->run([
            $this->ffmpeg(), '-y', '-v', 'error',
            '-ss', number_format(max(0, $atSeconds), 2, '.', ''),
            '-i', $input,
            '-frames:v', '1',
            '-vf', $this->scaleFilter(self::FULL_LONG_SIDE),
            '-q:v', '3',
            $output,
        ], 30);
    }

    /**
     * Boucle des cartes : quelques secondes, sans son, en basse définition.
     * Quelques centaines de Ko, lus en boucle pendant le défilement.
     */
    public function preview(string $input, string $output): bool
    {
        return $this->run([
            $this->ffmpeg(), '-y', '-v', 'error',
            '-i', $input,
            '-t', (string) self::PREVIEW_SECONDS,
            '-an',
            '-vf', $this->scaleFilter(self::PREVIEW_LONG_SIDE),
            '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '30',
            '-profile:v', 'main', '-pix_fmt', 'yuv420p',
            '-fpsmax', '24',
            // moov en tête : la lecture démarre avant la fin du téléchargement.
            '-movflags', '+faststart',
            $output,
        ], $this->timeout());
    }

    /** Version de la fiche : H.264 + AAC, lisible partout, 720p au plus. */
    public function transcode(string $input, string $output): bool
    {
        return $this->run([
            $this->ffmpeg(), '-y', '-v', 'error',
            '-i', $input,
            '-map', '0:v:0', '-map', '0:a:0?',
            '-vf', $this->scaleFilter(self::FULL_LONG_SIDE),
            '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '26',
            '-profile:v', 'high', '-pix_fmt', 'yuv420p',
            '-fpsmax', '30',
            '-c:a', 'aac', '-b:a', '96k', '-ac', '2',
            '-movflags', '+faststart',
            $output,
        ], $this->timeout());
    }

    /**
     * Réduit le plus grand côté à [$longSide] sans agrandir, dimensions paires
     * (exigées par H.264 en yuv420p).
     */
    private function scaleFilter(int $longSide): string
    {
        return "scale=w='if(gte(iw,ih),min({$longSide},iw),-2)':h='if(gte(iw,ih),-2,min({$longSide},ih))',"
            . 'scale=trunc(iw/2)*2:trunc(ih/2)*2';
    }

    private function run(array $command, int $timeout): bool
    {
        $process = new Process($command);
        $process->setTimeout($timeout);

        try {
            $process->run();
        } catch (\Throwable $e) {
            Log::warning('[Video] ' . basename($command[0]) . ' interrompu: ' . $e->getMessage());
            return false;
        }

        if (!$process->isSuccessful()) {
            Log::warning('[Video] ' . basename($command[0]) . ' en échec: ' . trim($process->getErrorOutput()));
            return false;
        }

        return true;
    }

    private function ffmpeg(): string
    {
        return (string) config('services.ffmpeg.ffmpeg', 'ffmpeg');
    }

    private function ffprobe(): string
    {
        return (string) config('services.ffmpeg.ffprobe', 'ffprobe');
    }

    private function timeout(): int
    {
        return max(10, (int) config('services.ffmpeg.timeout', 75));
    }
}
