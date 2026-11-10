<?php

/**
 * Import additionnel, sans mise à jour ni suppression des fiches existantes.
 * Prévisualisation : php scripts/import_sourced_wholesale_catalog.php
 * Application : même commande avec --apply.
 * Les originaux vérifiés doivent être présents dans l'archive privée du lot.
 */

use App\Models\Product;
use App\Support\AssoScope;
use App\Support\ImportHub;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
set_exception_handler(static function (Throwable $exception): void {
    fwrite(STDERR, get_class($exception).': '.$exception->getMessage()."\n");
    exit(1);
});

$apply = in_array('--apply', $argv, true);
$manifestPath = database_path('data/wholesale-catalog-2026-10-27.json');
foreach ($argv as $argument) {
    if (str_starts_with($argument, '--manifest=')) {
        $manifestPath = substr($argument, strlen('--manifest='));
    }
}
$manifest = json_decode(file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
$batch = $manifest['batch'];
if (! preg_match('/\A[a-z0-9-]+\z/', $batch)) {
    throw new RuntimeException('Identifiant de lot invalide.');
}
$archive = storage_path('app/private/catalog-imports/'.$batch);
$hub = ImportHub::shop();
if (! $hub || $hub->status !== 'active' || (int) $hub->user_id !== AssoScope::ownerId()) {
    throw new RuntimeException('Entrepôt ASSO actif introuvable. Aucun ajout.');
}

$references = [];
$plan = [];
foreach ($manifest['products'] as $entry) {
    $p = $entry['product'];
    Validator::make($p, [
        'name' => 'required|string|max:255',
        'client_reference' => 'required|string|max:64',
        'category_id' => 'required|integer|exists:categories,id',
        'subcategory_id' => 'nullable|integer|exists:subcategories,id',
        'origin_country' => 'required|in:CN,TR,AE',
        'description' => 'required|string',
        'characteristics' => 'required|string',
        'commercial_information' => 'required|string',
        'price' => 'required|numeric|gt:0|max:99999999.99',
        'currency' => 'required|in:XAF',
        'weight' => 'nullable|numeric|gt:0',
        'stock' => 'required|integer|in:0',
        'status' => 'required|in:active,inactive',
        'is_wholesale' => 'required|accepted',
    ])->validate();
    if (isset($references[$p['client_reference']])) {
        throw new RuntimeException('Référence répétée dans le lot.');
    }
    $references[$p['client_reference']] = true;
    if (($p['subcategory_id'] !== null && ! DB::table('subcategories')->where('id', $p['subcategory_id'])->where('category_id', $p['category_id'])->exists())
        || ! DB::table('import_countries')->where('code', $p['origin_country'])->where('is_active', true)->exists()) {
        throw new RuntimeException('Catégorie ou pays incohérent : '.$p['name']);
    }
    if (count($entry['images']) < 2 || count($entry['images']) > 15 || ! count($entry['tiers'])) {
        throw new RuntimeException('Galerie ou paliers incomplets.');
    }
    $lastQuantity = 0;
    $lastPrice = INF;
    foreach ($entry['tiers'] as $tier) {
        if ($tier['min_quantity'] <= $lastQuantity || $tier['unit_price'] > $lastPrice
            || $tier['unit_price'] <= 0 || ! in_array($tier['pack_size'], [1, null], true) || $tier['currency'] !== 'XAF') {
            throw new RuntimeException('Paliers incohérents : '.$p['name']);
        }
        $expectedPrice = (int) (ceil($tier['source_price'] * $entry['source']['exchange_rate_xaf'] / 50) * 50);
        if ($expectedPrice !== $tier['unit_price']) {
            throw new RuntimeException('Conversion incohérente : '.$p['name']);
        }
        $lastQuantity = $tier['min_quantity'];
        $lastPrice = $tier['unit_price'];
    }
    if ($p['price'] !== $entry['tiers'][0]['unit_price'] || $p['min_order_quantity'] !== $entry['tiers'][0]['min_quantity']) {
        throw new RuntimeException('Prix de départ incohérent.');
    }
    $hashes = [];
    foreach ($entry['images'] as $img) {
        if (basename($img['file']) !== $img['file']) {
            throw new RuntimeException('Nom de fichier invalide.');
        }
        $path = $archive.'/images/'.$img['file'];
        $size = is_file($path) ? getimagesize($path) : false;
        $minimumSide = $manifest['image_policy']['minimum_short_side'] ?? 550;
        $minimumLongSide = $manifest['image_policy']['minimum_long_side'] ?? 550;
        if (! $size || $size[2] !== IMAGETYPE_JPEG || min($size[0], $size[1]) < $minimumSide
            || max($size[0], $size[1]) < $minimumLongSide
            || hash_file('sha256', $path) !== $img['sha256'] || isset($hashes[$img['sha256']])) {
            throw new RuntimeException('Image absente, altérée, petite ou répétée : '.$img['file']);
        }
        $hashes[$img['sha256']] = true;
    }
    $existing = Product::withoutGlobalScopes()->where('user_id', $hub->user_id)->where('client_reference', $p['client_reference'])->first();
    if (! $existing && Product::withoutGlobalScopes()->whereRaw('LOWER(name) = ?', [mb_strtolower($p['name'])])->exists()) {
        throw new RuntimeException('Nom déjà présent sans référence de source : '.$p['name']);
    }
    $plan[] = ['entry' => $entry, 'existing_id' => $existing?->id];
    if (! in_array('--quiet', $argv, true)) {
        echo ($existing ? 'CONSERVÉ #'.$existing->id : 'AJOUT')." | {$p['origin_country']} | {$p['name']} | ".count($entry['images'])." images\n";
    }
}
if (! $apply) {
    echo "Prévisualisation validée. Aucune écriture en base.\n";
    exit(0);
}

$newIds = [];
$run = now()->format('Ymd-His-u');
$snapshotPath = $archive.'/before-'.$run.'.json';
$tables = ['products', 'product_images', 'product_price_tiers', 'product_variants', 'product_videos', 'translations', 'shops', 'categories', 'subcategories', 'import_countries'];
$preserved = [];
DB::transaction(function () use ($plan, $hub, $archive, $batch, $tables, $snapshotPath, &$newIds, &$preserved) {
    if (DB::getDriverName() === 'pgsql') {
        DB::select('SELECT pg_advisory_xact_lock(?)', [2026102701]);
    }
    $before = [];
    foreach ($tables as $table) {
        $before[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
    }
    $snapshot = json_encode($before, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    if (file_put_contents($snapshotPath, $snapshot, LOCK_EX) !== strlen($snapshot) || ! chmod($snapshotPath, 0600)) {
        throw new RuntimeException('Sauvegarde privée impossible. Aucun ajout.');
    }
    foreach ($plan as $item) {
        $entry = $item['entry'];
        // Revérifier après prise du verrou : une relance concurrente ne duplique rien.
        if (Product::withoutGlobalScopes()->where('user_id', $hub->user_id)->where('client_reference', $entry['product']['client_reference'])->exists()) {
            continue;
        }
        $entry['product']['requires_quote'] = false;
        $product = Product::create($entry['product'] + ['user_id' => $hub->user_id, 'shop_id' => $hub->id]);
        foreach ($entry['tiers'] as $tier) {
            unset($tier['source_price']);
            $product->priceTiers()->create($tier);
        }
        foreach ($entry['images'] as $i => $img) {
            $relative = 'products/sourced/'.$batch.'/'.$img['file'];
            $destination = storage_path('app/public/'.$relative);
            if (! is_dir(dirname($destination)) && ! mkdir(dirname($destination), 0755, true)) {
                throw new RuntimeException('Dossier image impossible.');
            }
            if (is_file($destination)) {
                if (hash_file('sha256', $destination) !== $img['sha256']) {
                    throw new RuntimeException('Collision image : aucun écrasement autorisé.');
                }
            } else {
                // Mode exclusif : ne jamais écraser un fichier existant.
                $output = fopen($destination, 'xb');
                if (! $output) {
                    throw new RuntimeException('Création image impossible.');
                }
                $input = fopen($archive.'/images/'.$img['file'], 'rb');
                $written = stream_copy_to_stream($input, $output);
                fclose($input);
                fclose($output);
                chmod($destination, 0644);
                if ($written === false || hash_file('sha256', $destination) !== $img['sha256']) {
                    throw new RuntimeException('Copie image incomplète.');
                }
            }
            $product->images()->create(['image_path' => 'storage/'.$relative, 'is_primary' => $i === 0, 'order' => $i + 1]);
        }
        $newIds[] = $product->id;
    }
    // Contrôle intégral de chaque ligne préexistante avant validation atomique.
    foreach ($before as $table => $rows) {
        $after = DB::table($table)->orderBy('id')->get()->keyBy('id');
        foreach ($rows as $row) {
            if (! isset($after[$row['id']]) || (array) $after[$row['id']] !== $row) {
                throw new RuntimeException("Une ligne préexistante a changé : {$table} #{$row['id']}. Ajouts annulés.");
            }
        }
        $preserved[$table] = count($rows);
    }
});
$report = ['batch' => $batch, 'applied_at' => now()->toIso8601String(), 'created_ids' => $newIds, 'preserved_rows' => $preserved, 'backup' => $snapshotPath];
file_put_contents($archive.'/result-'.$run.'.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), LOCK_EX);
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
