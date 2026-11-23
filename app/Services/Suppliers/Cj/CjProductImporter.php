<?php

namespace App\Services\Suppliers\Cj;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Shop;
use App\Models\Subcategory;
use App\Models\Supplier;
use App\Models\SupplierCategory;
use App\Models\SupplierProduct;
use App\Models\SupplierVariant;
use App\Services\ProductVariantService;
use App\Services\Suppliers\SupplierImageStore;
use App\Services\Suppliers\SupplierImportException;
use App\Services\Suppliers\SupplierPricing;
use App\Support\AssoScope;
use App\Support\DeliveryDelay;
use App\Support\ImportHub;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Crée ou met à jour la fiche ASSO d'un produit CJ.
 *
 * - Vendu par la boutique « ASSO Import Douala » : CJ livre l'entrepôt, la
 *   livraison locale part de là comme pour tout produit ASSO.
 * - Produit de détail « importé » (sans pays d'origine : il reste dans le
 *   catalogue principal, pas dans l'onglet Import en gros).
 * - Prix en FCFA, transport CJ jusqu'à Douala inclus (SupplierPricing).
 * - Une nouvelle fiche arrive inactive : l'admin la relit puis la publie.
 *   Une mise à jour ne change jamais le statut choisi par l'admin.
 */
class CjProductImporter
{
    /** Noms d'options CJ traduits (les valeurs restent celles de CJ). */
    private const OPTION_NAMES = [
        'color' => 'Couleur', 'colour' => 'Couleur', 'size' => 'Taille', 'shoe size' => 'Pointure',
        'style' => 'Style', 'material' => 'Matière', 'specification' => 'Modèle', 'model' => 'Modèle',
        'pattern' => 'Motif', 'capacity' => 'Capacité', 'length' => 'Longueur', 'quantity' => 'Quantité',
    ];

    private SupplierPricing $pricing;

    public function __construct(
        private Supplier $supplier,
        private CjCatalog $catalog,
        private SupplierImageStore $images,
        private ProductVariantService $variants,
    ) {
        $this->pricing = new SupplierPricing($supplier);
    }

    /**
     * Importe (ou réimporte) un produit CJ. Les refus connus (pas de livraison,
     * catégorie non reliée, produit retiré) sont enregistrés sur la ligne
     * renvoyée au lieu de lever une exception.
     */
    public function import(string $pid, ?int $categoryId = null, ?int $subcategoryId = null): SupplierProduct
    {
        $row = SupplierProduct::firstOrNew(['supplier_id' => $this->supplier->id, 'external_id' => $pid]);
        if (! $row->exists) {
            $row->fill(['name' => $pid, 'status' => SupplierProduct::PENDING])->save();
        }

        try {
            $this->importInto($row, $pid, $categoryId, $subcategoryId);
        } catch (SupplierImportException $e) {
            $this->fail($row, $e->status, $e->getMessage());
        } catch (CjApiException $e) {
            if (in_array($e->cjCode, [1602000, 1602001, 1602002, 1602003], true)) {
                $this->fail($row, SupplierProduct::DELISTED, 'Produit retiré ou introuvable chez CJ. '.$e->describe());
            } elseif ($e->isRetryable()) {
                throw $e; // la tâche en file réessaiera plus tard
            } else {
                $this->fail($row, SupplierProduct::ERROR, $e->describe());
            }
        }

        return $row->refresh();
    }

    private function importInto(SupplierProduct $row, string $pid, ?int $categoryId, ?int $subcategoryId): void
    {
        $hub = $this->hub();
        $detail = $this->catalog->product($pid);
        $name = $this->clean((string) ($detail['productNameEn'] ?? ''), 255);
        $row->fill([
            'name' => $name !== '' ? $name : $pid,
            'external_sku' => $detail['productSku'] ?? null,
            'external_category_id' => $detail['categoryId'] ?? null,
            'main_image_url' => $detail['bigImage'] ?? null,
            'payload' => $detail,
            'payload_hash' => hash('sha256', json_encode($detail)),
            'last_synced_at' => now(),
        ]);

        $cjVariants = array_slice(
            array_values(array_filter((array) ($detail['variants'] ?? []), fn ($v) => ! empty($v['vid']) && is_numeric($v['variantSellPrice'] ?? null))),
            0,
            (int) $this->supplier->setting('max_variants', 100)
        );
        if ((string) ($detail['status'] ?? '3') !== '3' || $cjVariants === []) {
            throw new SupplierImportException('Produit non disponible à la vente chez CJ.', SupplierProduct::DELISTED);
        }

        $product = $row->product_id ? Product::withoutGlobalScopes()->find($row->product_id) : null;
        // Réimport sans catégorie imposée : la fiche existante garde la sienne.
        if ($categoryId === null && $product !== null) {
            [$categoryId, $subcategoryId] = [$product->category_id, $product->subcategory_id];
        }
        [$categoryId, $subcategoryId] = $this->category($detail['categoryId'] ?? null, $categoryId, $subcategoryId);

        // Transport calculé sur la déclinaison la plus lourde : le prix couvre toutes les autres.
        $heaviest = collect($cjVariants)->sortByDesc(fn ($v) => (float) ($v['variantWeight'] ?? 0))->first();
        $line = $this->chooseLine($this->catalog->freight((string) $heaviest['vid']));
        if ($line === null) {
            throw new SupplierImportException('Aucun transporteur CJ ne livre le Cameroun pour ce produit.', SupplierProduct::NO_SHIPPING);
        }

        $from = (string) $this->supplier->setting('from_country_code', 'CN');
        $optionNames = $this->optionNames((string) ($detail['productKeyEn'] ?? ''));
        $rows = [];
        foreach ($cjVariants as $variant) {
            $rows[] = [
                'cj' => $variant,
                'attributes' => $this->attributes($optionNames, $variant),
                'cost' => (float) $variant['variantSellPrice'],
                'price' => $this->pricing->sellingPriceXaf((float) $variant['variantSellPrice'], $line['price_usd']),
                'stock' => $this->stock($variant, $from),
            ];
        }
        $basePrice = min(array_column($rows, 'price'));
        [$daysMin, $daysMax] = $this->delay($line['aging']);
        $weightG = (float) ($heaviest['variantWeight'] ?? $detail['productWeight'] ?? 0);

        $texts = $this->texts($detail, $line, $weightG);
        $newImages = $product === null ? $this->downloadImages($pid, $detail) : [];

        DB::transaction(function () use ($row, $product, $hub, $rows, $basePrice, $categoryId, $subcategoryId, $daysMin, $daysMax, $weightG, $heaviest, $texts, $newImages, $line) {
            $attributes = [
                'shop_id' => $hub->id,
                'user_id' => $hub->user_id,
                'category_id' => $categoryId,
                'subcategory_id' => $subcategoryId,
                'name' => $row->name,
                'description' => $texts['fr']['description'],
                'characteristics' => $texts['fr']['characteristics'],
                'commercial_information' => $texts['fr']['commercial_information'],
                'price' => $basePrice,
                'currency' => 'XAF',
                'price_type' => 'fixed',
                'type' => 'article',
                'origin_country' => null,
                'is_local_made' => false,
                'is_wholesale' => false,
                'requires_quote' => false,
                'deposit_enabled' => false,
                'weight' => $weightG > 0 ? round($weightG / 1000, 3) : null,
                'length_cm' => $this->cm($heaviest['variantLength'] ?? null),
                'width_cm' => $this->cm($heaviest['variantWidth'] ?? null),
                'height_cm' => $this->cm($heaviest['variantHeight'] ?? null),
                'delivery_days_min' => $daysMin,
                'delivery_days_max' => $daysMax,
                'stock' => array_sum(array_column($rows, 'stock')),
            ];

            if ($product === null) {
                $product = Product::create($attributes + [
                    'slug' => Product::uniqueSlug($row->name),
                    'status' => 'inactive',
                    'weight_category' => 'X-small',
                    'client_reference' => 'cj:'.$row->external_id,
                ]);
                foreach ($newImages as $i => $path) {
                    $product->images()->create(['image_path' => $path, 'is_primary' => $i === 0, 'order' => $i + 1]);
                }
            } else {
                if ($product->name !== $row->name) {
                    $attributes['slug'] = Product::uniqueSlug($row->name, $product->id);
                }
                $product->update($attributes);
            }
            $product->syncTranslations(['en' => $texts['en']]);

            // Déclinaisons : une seule déclinaison sans option = produit simple.
            $single = count($rows) === 1 && $rows[0]['attributes'] === [];
            $this->variants->sync($product, $single ? [] : array_map(fn ($r) => [
                'attributes' => $r['attributes'] ?: ['Option' => $this->clean((string) ($r['cj']['variantNameEn'] ?? $r['cj']['vid']), 100)],
                'sku' => $r['cj']['variantSku'] ?? null,
                'price_adjustment' => $r['price'] - $basePrice,
                'stock' => $r['stock'],
                'weight' => is_numeric($r['cj']['variantWeight'] ?? null) ? round((float) $r['cj']['variantWeight'] / 1000, 3) : null,
                'is_active' => true,
            ], $rows));

            $local = ProductVariant::where('product_id', $product->id)->get();
            $keptIds = [];
            foreach ($rows as $r) {
                $sku = $r['cj']['variantSku'] ?? null;
                $match = $single ? null : $local->first(fn ($v) => $sku !== null && $v->sku === $sku);
                $variant = SupplierVariant::updateOrCreate(
                    ['supplier_product_id' => $row->id, 'external_id' => (string) $r['cj']['vid']],
                    [
                        'external_sku' => $sku,
                        'product_variant_id' => $match?->id,
                        'attributes' => $r['attributes'],
                        'cost_usd' => $r['cost'],
                        'price_xaf' => $r['price'],
                        'weight_g' => is_numeric($r['cj']['variantWeight'] ?? null) ? (float) $r['cj']['variantWeight'] : null,
                        'length_mm' => $this->int($r['cj']['variantLength'] ?? null),
                        'width_mm' => $this->int($r['cj']['variantWidth'] ?? null),
                        'height_mm' => $this->int($r['cj']['variantHeight'] ?? null),
                        'stock' => $r['stock'],
                        'is_active' => true,
                    ]
                );
                $keptIds[] = $variant->id;
            }
            SupplierVariant::where('supplier_product_id', $row->id)->whereNotIn('id', $keptIds)->update(['is_active' => false]);

            $row->fill([
                'product_id' => $product->id,
                'status' => SupplierProduct::IMPORTED,
                'cost_usd' => min(array_column($rows, 'cost')),
                'freight_usd' => $line['price_usd'],
                'logistic_name' => $line['name'],
                'logistic_aging' => $line['aging'],
                'weight_g' => $weightG > 0 ? (int) round($weightG) : null,
                'last_error' => null,
            ])->save();
        });
    }

    private function hub(): Shop
    {
        $hub = ImportHub::shop();
        if (! $hub || $hub->status !== 'active' || (int) $hub->user_id !== AssoScope::ownerId() || AssoScope::ownerId() === 0) {
            throw new SupplierImportException('Boutique « '.ImportHub::NAME.' » introuvable ou inactive : créez-la dans Pays d’importation.');
        }

        return $hub;
    }

    /** @return array{0: int, 1: ?int} catégorie et sous-catégorie ASSO */
    private function category(?string $cjCategoryId, ?int $categoryId, ?int $subcategoryId): array
    {
        $mapped = $cjCategoryId
            ? SupplierCategory::where('supplier_id', $this->supplier->id)->where('external_id', $cjCategoryId)->whereNotNull('category_id')->first()
            : null;
        if ($mapped) {
            return [$mapped->category_id, $mapped->subcategory_id];
        }
        if ($categoryId) {
            if ($subcategoryId && ! Subcategory::whereKey($subcategoryId)->where('category_id', $categoryId)->exists()) {
                $subcategoryId = null;
            }

            return [$categoryId, $subcategoryId];
        }

        throw new SupplierImportException('Catégorie CJ non reliée à une catégorie ASSO.', SupplierProduct::UNMAPPED);
    }

    private function chooseLine(array $lines): ?array
    {
        $preferred = trim((string) $this->supplier->setting('preferred_logistic', ''));
        if ($preferred !== '') {
            foreach ($lines as $line) {
                if (strcasecmp($line['name'], $preferred) === 0) {
                    return $line;
                }
            }
        }

        return $lines[0] ?? null;
    }

    /** « Color-Size » → ['Couleur', 'Taille'] */
    private function optionNames(string $key): array
    {
        $names = array_values(array_filter(array_map('trim', explode('-', $key)), fn ($n) => $n !== ''));

        return array_map(fn ($n) => self::OPTION_NAMES[mb_strtolower($n)] ?? Str::ucfirst(mb_substr($n, 0, 100)), $names);
    }

    /** « Black-XL » + ['Couleur', 'Taille'] → ['Couleur' => 'Black', 'Taille' => 'XL'] */
    private function attributes(array $names, array $variant): array
    {
        $key = trim((string) ($variant['variantKey'] ?? ''));
        if ($key === '') {
            return [];
        }
        $values = array_map('trim', explode('-', $key));
        if ($names !== [] && count($values) === count($names) && ! in_array('', $values, true)) {
            return array_combine($names, array_map(fn ($v) => mb_substr($v, 0, 100), $values));
        }
        // Découpage ambigu (un tiret dans une valeur) : une seule option avec le libellé complet.
        $label = count($names) === 1 ? $names[0] : 'Option';

        return [$label => mb_substr($key, 0, 100)];
    }

    private function stock(array $variant, string $from): int
    {
        $total = 0;
        foreach ((array) ($variant['inventories'] ?? []) as $inventory) {
            if (strtoupper((string) ($inventory['countryCode'] ?? '')) === strtoupper($from)) {
                $total += (int) ($inventory['totalInventory'] ?? 0);
            }
        }

        return max(0, $total);
    }

    /** Délai CJ (« 12-20 ») + jours de traitement à Douala. */
    private function delay(?string $aging): array
    {
        $handling = (int) $this->supplier->setting('hub_handling_days', 3);
        preg_match_all('/\d+/', (string) $aging, $m);
        $numbers = array_map('intval', $m[0] ?? []);
        if ($numbers === []) {
            return [DeliveryDelay::MIN_DAYS + $handling, null];
        }
        $min = max(DeliveryDelay::MIN_DAYS, min($numbers) + $handling);
        $max = min(DeliveryDelay::MAX_DAYS, max($numbers) + $handling);

        return [$min, $max > $min ? $max : null];
    }

    /** @return array{fr: array, en: array} */
    private function texts(array $detail, array $line, float $weightG): array
    {
        $description = $this->htmlToText((string) ($detail['description'] ?? ''));
        if ($description === '') {
            $description = $this->clean((string) ($detail['productNameEn'] ?? ''), 255);
        }
        $materials = $this->clean(implode(', ', (array) ($detail['materialNameEnSet'] ?? [])), 300);
        $packing = $this->clean(implode(', ', (array) ($detail['packingNameEnSet'] ?? [])), 300);
        $weight = $weightG > 0 ? number_format($weightG, 0, ',', ' ').' g' : null;

        $fr = array_filter([$weight ? "Poids : {$weight}" : null, $materials ? "Matière : {$materials}" : null, $packing ? "Emballage : {$packing}" : null]);
        $en = array_filter([$weight ? "Weight: {$weight}" : null, $materials ? "Material: {$materials}" : null, $packing ? "Packaging: {$packing}" : null]);

        return [
            'fr' => [
                'description' => $description,
                'characteristics' => implode("\n", $fr),
                'commercial_information' => 'Produit importé : expédié par notre fournisseur international jusqu’à l’entrepôt ASSO de Douala, contrôlé, puis livré chez vous ou disponible au retrait.',
            ],
            'en' => [
                'name' => $this->clean((string) ($detail['productNameEn'] ?? ''), 255) ?: null,
                'description' => $description,
                'characteristics' => implode("\n", $en),
                'commercial_information' => 'Imported product: shipped by our international supplier to the ASSO warehouse in Douala, checked, then delivered to you or ready for pickup.',
            ],
        ];
    }

    /** @return list<string> chemins des photos téléchargées */
    private function downloadImages(string $pid, array $detail): array
    {
        $urls = array_values(array_unique(array_filter(array_merge(
            [$detail['bigImage'] ?? null],
            (array) ($detail['productImageSet'] ?? [])
        ), fn ($u) => is_string($u) && $u !== '')));
        $limit = max(1, (int) $this->supplier->setting('images_per_product', 4));
        $directory = 'products/cj/'.Str::slug($pid);
        $paths = [];

        foreach ($urls as $i => $url) {
            if (count($paths) >= $limit) {
                break;
            }
            try {
                $paths[] = $this->images->store($url, $directory, ($i + 1).'-'.substr(sha1($url), 0, 8));
            } catch (SupplierImportException $e) {
                Log::info('[CJ] Photo ignorée', ['pid' => $pid, 'reason' => $e->getMessage()]);
            }
        }
        if ($paths === []) {
            throw new SupplierImportException('Aucune photo exploitable pour ce produit.');
        }

        return $paths;
    }

    private function fail(SupplierProduct $row, string $status, string $message): void
    {
        $row->fill(['status' => $status, 'last_error' => Str::limit($message, 1000), 'last_synced_at' => now()])->save();
        // Un produit déjà publié qui ne peut plus être fourni est retiré de la vente.
        if (in_array($status, [SupplierProduct::DELISTED, SupplierProduct::NO_SHIPPING], true) && $row->product_id) {
            Product::withoutGlobalScopes()->whereKey($row->product_id)->update(['status' => 'inactive']);
        }
    }

    private function htmlToText(string $html): string
    {
        $text = preg_replace('/<\s*(br|\/p|\/li|\/div|\/h\d)\s*\/?>/i', "\n", $html) ?? '';
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t\x{00A0}]+/u", ' ', $text) ?? '';
        $text = preg_replace("/\n\s*\n+/", "\n\n", $text) ?? '';

        return mb_substr(trim($text), 0, 10000);
    }

    private function clean(string $text, int $limit): string
    {
        return mb_substr(trim(preg_replace('/\s+/u', ' ', strip_tags($text)) ?? ''), 0, $limit);
    }

    private function cm(mixed $mm): ?float
    {
        return is_numeric($mm) && (float) $mm > 0 ? round((float) $mm / 10, 2) : null;
    }

    private function int(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
