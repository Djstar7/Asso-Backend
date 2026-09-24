<?php

namespace App\Console\Commands;

use App\Models\ProductVideo;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Vidéos produits abandonnées.
 *
 * Une vidéo part dès qu'elle est choisie dans le formulaire admin, avant
 * l'enregistrement du produit. Si le formulaire n'est jamais validé, elle
 * reste sans produit ; de même pour les morceaux d'un envoi interrompu.
 * Passé un jour, les deux sont supprimés.
 */
class PruneProductVideos extends Command
{
    protected $signature = 'product-videos:prune {--hours=24 : Âge minimum avant suppression}';

    protected $description = 'Supprime les vidéos produits jamais rattachées et les envois interrompus';

    public function handle(): int
    {
        $cutoff = now()->subHours((int) $this->option('hours'));

        $orphans = ProductVideo::whereNull('product_id')->where('created_at', '<', $cutoff)->get();
        $orphans->each->delete();

        $local = Storage::disk('local');
        $stale = 0;
        foreach ($local->directories('video-uploads') as $dir) {
            if ($local->lastModified($dir) < $cutoff->getTimestamp()) {
                $local->deleteDirectory($dir);
                $stale++;
            }
        }

        $this->info("{$orphans->count()} vidéo(s) orpheline(s) et {$stale} envoi(s) interrompu(s) supprimés.");

        return self::SUCCESS;
    }
}
