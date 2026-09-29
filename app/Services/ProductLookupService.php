<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Product;
use App\Models\Subcategory;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Fonctionnalité 3 — pré-remplissage de la fiche produit sans LLM.
 *
 * Le mobile extrait avec ML Kit le code-barres, le texte de l'étiquette et
 * des labels d'image ; ce service cherche la fiche correspondante :
 *  1. catalogue ASSO : un produit déjà publié avec le même code-barres ;
 *  2. micro-service Asso-Lookup (Python) : bases ouvertes (Open Food /
 *     Beauty / Products Facts, UPCitemdb) puis règles (regex + gabarits).
 *     Les réponses venant d'une base sont gardées en cache 30 jours par
 *     code-barres ; une déduction par règles dépend de la photo, jamais.
 *
 * Service indisponible → réponse vide (source « none ») : le vendeur remplit
 * sa fiche à la main, sans erreur bloquante.
 */
class ProductLookupService
{
    private const CACHE_DAYS = 30;

    /** Sources dont la réponse ne dépend que du code-barres (cachables). */
    private const DATABASE_SOURCES = ['openfoodfacts', 'openbeautyfacts', 'openproductsfacts', 'upcitemdb'];

    /**
     * @param  array{barcode?: ?string, barcode_format?: ?string, ocr_text?: ?string, ocr_lines?: array, labels?: array}  $input
     * @return array{source: string, suggested_data: array, confidence: array}
     */
    public function lookup(array $input): array
    {
        $barcode = self::normalizeBarcode($input['barcode'] ?? null);

        if ($barcode !== null && ($known = $this->fromCatalog($barcode))) {
            return $known;
        }

        $payload = [
            'barcode' => $barcode,
            'barcode_format' => $input['barcode_format'] ?? null,
            'ocr_text' => (string) ($input['ocr_text'] ?? ''),
            'ocr_lines' => array_values($input['ocr_lines'] ?? []),
            'labels' => array_values($input['labels'] ?? []),
            'locale' => 'fr',
        ];

        $result = $barcode !== null ? Cache::get($this->cacheKey($barcode)) : null;
        if ($result === null) {
            $result = $this->callService($payload);
            if ($barcode !== null && in_array($result['source'] ?? null, self::DATABASE_SOURCES, true)) {
                Cache::put($this->cacheKey($barcode), $result, now()->addDays(self::CACHE_DAYS));
            }
        }

        if ($result === null) {
            return $this->empty($barcode);
        }

        return $this->present($result, $barcode);
    }

    /**
     * Code-barres EAN-8, UPC-A, EAN-13 ou GTIN-14 à clé de contrôle valide.
     */
    public static function normalizeBarcode(?string $raw): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $raw);
        if (!in_array(strlen($digits), [8, 12, 13, 14], true)) {
            return null;
        }
        $body = strrev(substr($digits, 0, -1));
        $sum = 0;
        for ($i = 0, $n = strlen($body); $i < $n; $i++) {
            $sum += (int) $body[$i] * ($i % 2 === 0 ? 3 : 1);
        }

        return (10 - $sum % 10) % 10 === (int) substr($digits, -1) ? $digits : null;
    }

    private function cacheKey(string $barcode): string
    {
        return "product_lookup:{$barcode}";
    }

    /**
     * Produit ASSO déjà publié avec ce code-barres : la fiche la plus récente.
     */
    private function fromCatalog(string $barcode): ?array
    {
        $product = Product::where('barcode', $barcode)
            ->where('status', 'active')
            ->latest('id')
            ->first();
        if (!$product) {
            return null;
        }

        return [
            'source' => 'asso',
            'suggested_data' => [
                'name' => $product->name,
                'description' => $product->description,
                'brand' => $product->brand,
                'barcode' => $barcode,
                'type' => $product->type ?? 'article',
                'weight_kg' => is_numeric($product->weight) ? (float) $product->weight : null,
                'category_id' => $product->category_id,
                'subcategory_id' => $product->subcategory_id,
            ],
            'confidence' => [
                'name' => 0.95,
                'brand' => $product->brand ? 0.95 : 0,
                'description' => 0.8,
                'category' => 0.95,
                'weight' => is_numeric($product->weight) ? 0.8 : 0,
            ],
        ];
    }

    private function callService(array $payload): ?array
    {
        $url = rtrim((string) config('services.product_lookup.url'), '/');
        $token = (string) config('services.product_lookup.token');
        if ($url === '' || $token === '') {
            Log::warning('[PRODUCT_LOOKUP] Service non configuré (PRODUCT_LOOKUP_URL / PRODUCT_LOOKUP_TOKEN)');

            return null;
        }

        try {
            $response = Http::timeout((int) config('services.product_lookup.timeout', 12))
                ->acceptJson()
                ->withHeaders(['X-Internal-Token' => $token])
                ->post("{$url}/lookup", $payload);
        } catch (ConnectionException $e) {
            Log::warning('[PRODUCT_LOOKUP] Service injoignable', ['error' => $e->getMessage()]);

            return null;
        }

        if (!$response->successful() || !is_array($response->json())) {
            Log::warning('[PRODUCT_LOOKUP] Réponse invalide', ['status' => $response->status()]);

            return null;
        }

        return $response->json();
    }

    /**
     * Réponse du service Python → format lu par le formulaire mobile
     * (identifiants de catégorie à la place des slugs).
     */
    private function present(array $result, ?string $barcode): array
    {
        [$categoryId, $subcategoryId] = $this->resolveCategory(
            $result['category_slug'] ?? null,
            $result['subcategory_slug'] ?? null,
        );
        $confidence = array_map('floatval', array_intersect_key(
            (array) ($result['confidence'] ?? []),
            array_flip(['name', 'brand', 'description', 'category', 'weight']),
        ));
        if ($categoryId === null) {
            $confidence['category'] = 0.0;
        }

        return [
            'source' => (string) ($result['source'] ?? 'none'),
            'suggested_data' => [
                'name' => $result['name'] ?? null,
                'description' => $result['description'] ?? null,
                'brand' => $result['brand'] ?? null,
                'barcode' => $barcode,
                'type' => 'article',
                'quantity_label' => $result['quantity_label'] ?? null,
                'weight_kg' => isset($result['weight_kg']) ? (float) $result['weight_kg'] : null,
                'category_id' => $categoryId,
                'subcategory_id' => $subcategoryId,
            ],
            'confidence' => $confidence + ['name' => 0.0, 'brand' => 0.0, 'description' => 0.0, 'category' => 0.0, 'weight' => 0.0],
        ];
    }

    private function empty(?string $barcode): array
    {
        return $this->present(['source' => 'none'], $barcode);
    }

    /**
     * Slugs du CategorySeeder → identifiants. Une sous-catégorie qui
     * n'appartient pas à la catégorie trouvée est ignorée.
     *
     * @return array{0: ?int, 1: ?int}
     */
    private function resolveCategory(?string $categorySlug, ?string $subcategorySlug): array
    {
        if (!$categorySlug) {
            return [null, null];
        }
        $category = Category::where('slug', $categorySlug)->first();
        if (!$category) {
            return [null, null];
        }
        $subcategory = $subcategorySlug
            ? Subcategory::where('category_id', $category->id)->where('slug', $subcategorySlug)->first()
            : null;

        return [$category->id, $subcategory?->id];
    }
}
