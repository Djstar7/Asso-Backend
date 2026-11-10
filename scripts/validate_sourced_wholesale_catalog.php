<?php

/** Read-only verification of the complete 3,000-sheet sourcing operation. */
use App\Models\Product;
use App\Support\ImportHub;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$archive = storage_path('app/private/catalog-imports/sourcing-3000-2026-10-27');
$selection = json_decode(file_get_contents($archive.'/selection-report.json'), true, 512, JSON_THROW_ON_ERROR);
$entries = [];
$first = json_decode(file_get_contents(database_path('data/wholesale-catalog-2026-10-27.json')), true, 512, JSON_THROW_ON_ERROR);
foreach (array_merge([database_path('data/wholesale-catalog-2026-10-27.json')], $selection['manifests']) as $path) {
    $manifest = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    foreach ($manifest['products'] as $entry) {
        $key = $entry['product']['client_reference'];
        if (isset($entries[$key])) {
            throw new RuntimeException('Référence répétée dans les manifestes : '.$key);
        }
        $entry['batch'] = $manifest['batch'];
        $entries[$key] = $entry;
    }
}
$hub = ImportHub::shop();
$counts = ['CN' => 0, 'TR' => 0, 'AE' => 0];
$imageCount = 0;
$tierCount = 0;
$unknownWeights = 0;
$ids = [];
$sourceIds = [];
foreach ($entries as $reference => $entry) {
    $p = Product::withoutGlobalScopes()->where('user_id', $hub->user_id)->where('client_reference', $reference)->first();
    if (! $p || $p->status !== 'active' || $p->stock !== 0 || $p->shop_id !== $hub->id
        || $p->origin_country !== $entry['product']['origin_country'] || ! $p->is_wholesale) {
        throw new RuntimeException('Fiche absente ou incohérente : '.$reference);
    }
    foreach ($entry['product'] as $key => $expected) {
        if ($key === 'requires_quote') {
            continue; // Ancien statut ignoré depuis sa suppression.
        }
        $actual = $p->getAttribute($key);
        if (is_int($expected) || is_float($expected)) {
            if (! is_numeric($actual) || abs((float) $actual - $expected) > 0.0001) {
                throw new RuntimeException("Valeur incohérente : {$reference}.{$key}");
            }
        } elseif ($actual !== $expected) {
            throw new RuntimeException("Valeur incohérente : {$reference}.{$key}");
        }
    }
    if ($entry['source']['platform'] === 'Alibaba' && ($entry['source']['supplier_country'] ?? null) !== $p->origin_country) {
        throw new RuntimeException('Pays fournisseur incohérent.');
    }
    $sourceKey = $entry['source']['platform'].':'.$entry['source']['id'];
    if (isset($sourceIds[$sourceKey])) {
        throw new RuntimeException('Annonce source dupliquée.');
    }
    $sourceIds[$sourceKey] = true;
    $images = $p->images()->orderBy('order')->get();
    if ($images->count() !== count($entry['images']) || $images->count() < 2 || $images->where('is_primary', true)->count() !== 1) {
        throw new RuntimeException('Galerie incohérente : '.$reference);
    }
    foreach ($entry['images'] as $index => $image) {
        $expectedPath = 'storage/products/sourced/'.$entry['batch'].'/'.$image['file'];
        $actual = $images[$index];
        if ($actual->image_path !== $expectedPath || $actual->order !== $index + 1
            || hash_file('sha256', public_path($expectedPath)) !== $image['sha256']) {
            throw new RuntimeException('Image publiée absente ou altérée : '.$expectedPath);
        }
        $imageCount++;
    }
    $tiers = $p->priceTiers()->orderBy('sort_order')->get();
    if ($tiers->count() !== count($entry['tiers'])) {
        throw new RuntimeException('Paliers incomplets : '.$reference);
    }
    foreach ($entry['tiers'] as $index => $tier) {
        foreach (['unit_price', 'min_quantity', 'pack_size', 'weight_kg', 'currency', 'label', 'is_active'] as $key) {
            $actual = $tiers[$index]->getAttribute($key);
            $expected = $tier[$key];
            if ($key === 'weight_kg' && $expected !== null) {
                // product_price_tiers.weight_kg is NUMERIC(10, 3).
                $expected = round((float) $expected, 3);
            }
            if (is_float($expected) || is_int($expected)) {
                if (! is_numeric($actual) || abs((float) $actual - $expected) > .0001) {
                    throw new RuntimeException('Palier incohérent : '.$reference.'.'.$key);
                }
            } elseif ($actual !== $expected) {
                throw new RuntimeException('Palier incohérent : '.$reference.'.'.$key);
            }
        }
        $tierCount++;
    }
    $counts[$p->origin_country]++;
    $unknownWeights += $p->weight === null ? 1 : 0;
    $ids[] = $p->id;
}
if ($counts !== ['CN' => 1000, 'TR' => 1000, 'AE' => 1000]) {
    throw new RuntimeException('Objectif incomplet : '.json_encode($counts));
}

// First backup of this operation contains all 411 sheets present beforehand,
// including the ten sheets from the initial pilot. Their data must stay intact.
$backups = glob($archive.'/before-*.json');
sort($backups);
$baselinePath = $backups[0] ?? throw new RuntimeException('Sauvegarde initiale absente.');
$baseline = json_decode(file_get_contents($baselinePath), true, 512, JSON_THROW_ON_ERROR);
$pilotReferences = array_column(array_column($first['products'], 'product'), 'client_reference');
$preserved = [];
foreach ($baseline as $table => $rows) {
    $current = DB::table($table)->get()->keyBy('id');
    foreach ($rows as $row) {
        $actual = isset($current[$row['id']]) ? array_intersect_key((array) $current[$row['id']], $row) : null;
        // The user subsequently requested frontend visibility for the ten pilot
        // sheets too. Only their publication status/timestamp may differ.
        if ($table === 'products' && in_array($row['client_reference'] ?? null, $pilotReferences, true) && $actual) {
            unset($row['status'], $row['updated_at'], $actual['status'], $actual['updated_at']);
        }
        if ($actual !== $row) {
            throw new RuntimeException("Ligne préexistante modifiée : {$table} #{$row['id']}");
        }
    }
    $preserved[$table] = count($rows);
}
$report = ['validated_at' => now()->toIso8601String(), 'sourced_total' => count($entries), 'per_country' => $counts,
    'original_user_products_preserved' => count($baseline['products']) - count($pilotReferences),
    'pilot_references_included' => count($pilotReferences),
    'images' => $imageCount, 'price_tiers' => $tierCount, 'all_public_catalogue' => true, 'all_require_quote_before_payment' => true, 'all_stock_zero' => true,
    'unknown_shipping_weights_not_invented' => $unknownWeights, 'preserved_rows' => $preserved,
    'initial_backup' => $baselinePath, 'catalog_products_total' => DB::table('products')->count(), 'product_ids' => $ids];
file_put_contents($archive.'/final-validation.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), LOCK_EX);
unset($report['product_ids']);
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
