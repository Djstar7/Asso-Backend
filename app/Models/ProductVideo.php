<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * Vidéo de présentation d'un produit grossiste.
 *
 * Trois fichiers sont produits par ProcessProductVideo à partir de l'envoi :
 *  - `path`         : version de la fiche produit (720p max, son conservé) ;
 *  - `preview_path` : boucle muette et légère lue sur les cartes, pour ne pas
 *                     faire télécharger la vidéo complète à chaque défilement ;
 *  - `poster_path`  : image affichée tant que la vidéo n'est pas prête à jouer.
 *
 * Sans ffmpeg sur le serveur, le fichier reçu sert aux deux usages et l'app
 * retombe sur la photo du produit comme affiche.
 */
class ProductVideo extends Model
{
    public const PENDING = 'pending';
    public const PROCESSING = 'processing';
    public const READY = 'ready';
    public const FAILED = 'failed';

    /** Taille maximale acceptée à l'envoi, en Mo. */
    public const MAX_SIZE_MB = 100;

    /** Extensions acceptées ; ffprobe confirme ensuite qu'il s'agit bien d'une vidéo. */
    public const EXTENSIONS = ['mp4', 'mov', 'm4v', 'webm', '3gp', 'mkv'];

    protected $fillable = [
        'product_id',
        'uploaded_by',
        'original_path',
        'path',
        'preview_path',
        'poster_path',
        'original_name',
        'size_bytes',
        'width',
        'height',
        'duration',
        'status',
        'error',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
        'duration' => 'float',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function scopeReady(Builder $query): Builder
    {
        return $query->where('status', self::READY);
    }

    public function isReady(): bool
    {
        return $this->status === self::READY && $this->playablePath() !== null;
    }

    /** Fichier lu sur la fiche : la version convertie, sinon l'original. */
    public function playablePath(): ?string
    {
        return $this->path ?: $this->original_path;
    }

    /** Fichier lu sur les cartes : l'aperçu léger, sinon la version complète. */
    public function previewPlayablePath(): ?string
    {
        return $this->preview_path ?: $this->playablePath();
    }

    /** Largeur / hauteur ; les vidéos de grossistes sont presque toujours verticales. */
    public function aspectRatio(): ?float
    {
        return $this->width && $this->height ? round($this->width / $this->height, 4) : null;
    }

    /**
     * Chemin absolu du fichier demandé (`video`, `preview`, `poster`), ou null.
     */
    public function absolutePath(string $kind): ?string
    {
        $relative = match ($kind) {
            'video' => $this->playablePath(),
            'preview' => $this->previewPlayablePath(),
            'poster' => $this->poster_path,
            default => null,
        };

        if (!$relative || !Storage::disk('public')->exists($relative)) {
            return null;
        }

        return Storage::disk('public')->path($relative);
    }

    /**
     * Forme exposée à l'app. Les fichiers passent par une route API plutôt que
     * par /storage : elle répond aux requêtes partielles (Range), sans lesquelles
     * iOS refuse de lire une vidéo, quel que soit le serveur devant Laravel.
     */
    public function toApi(): ?array
    {
        if (!$this->isReady()) {
            return null;
        }

        return [
            'id' => $this->id,
            'url' => url("/api/v1/import/videos/{$this->id}/video"),
            'preview_url' => url("/api/v1/import/videos/{$this->id}/preview"),
            'poster_url' => $this->poster_path ? url("/api/v1/import/videos/{$this->id}/poster") : null,
            'width' => $this->width,
            'height' => $this->height,
            'aspect_ratio' => $this->aspectRatio(),
            'duration' => $this->duration,
        ];
    }

    /** Supprime les fichiers du disque (la ligne reste à supprimer par l'appelant). */
    public function deleteFiles(): void
    {
        $paths = array_filter([
            $this->original_path,
            $this->path,
            $this->preview_path,
            $this->poster_path,
        ]);

        if ($paths) {
            Storage::disk('public')->delete(array_values(array_unique($paths)));
        }
    }

    protected static function booted(): void
    {
        // Pas de fichiers orphelins : une ligne supprimée emporte ses fichiers.
        static::deleting(fn (ProductVideo $video) => $video->deleteFiles());
    }
}
