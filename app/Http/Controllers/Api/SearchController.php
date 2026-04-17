<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\SearchSynonym;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SearchController extends Controller
{
    /**
     * Recherche intelligente de produits
     *
     * Cette méthode utilise :
     * - PostgreSQL Full-Text Search avec to_tsvector et to_tsquery
     * - Extension pg_trgm pour la recherche par similarité
     * - Table de synonymes pour mapper des termes (ex: PC -> ordinateur)
     * - Support UTF-8 natif avec unaccent
     * - Scoring de pertinence
     */
    public function search(Request $request)
    {
        $request->validate([
            'q' => 'required|string|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
            'category_id' => 'nullable|exists:categories,id',
            'subcategory_id' => 'nullable|exists:subcategories,id',
            'min_price' => 'nullable|numeric|min:0',
            'max_price' => 'nullable|numeric|min:0',
            'type' => 'nullable|in:article,service',
        ]);

        $searchQuery = trim($request->input('q'));
        $perPage = $request->input('per_page', 20);

        Log::info('[SMART_SEARCH] Starting search', [
            'query' => $searchQuery,
            'user_id' => $request->user()?->id,
        ]);

        // 1. Expand search query with synonyms
        $expandedTerms = SearchSynonym::expandSearchQuery($searchQuery);

        Log::info('[SMART_SEARCH] Expanded terms', [
            'original' => $searchQuery,
            'expanded' => $expandedTerms,
        ]);

        // 2. Build the intelligent search query
        $query = Product::query()
            ->with(['images', 'primaryImage', 'category', 'subcategory', 'shop', 'user'])
            ->where('status', 'active')
            ->whereHas('shop', function ($q) {
                $q->where('status', 'active');
            });

        // 3. Apply search with multiple strategies
        $query->where(function ($q) use ($searchQuery, $expandedTerms) {
            // Strategy 1: Full-Text Search (highest priority)
            // Utilise le vecteur de recherche pour une recherche full-text rapide
            $tsQuery = $this->buildTsQuery($expandedTerms);
            $q->orWhereRaw("search_vector @@ to_tsquery('french', ?)", [$tsQuery]);

            // Strategy 2: Trigram similarity search (for typos and partial matches)
            // Utilise pg_trgm pour gérer les fautes de frappe
            foreach ($expandedTerms as $term) {
                if (strlen($term) >= 3) {
                    $q->orWhereRaw("similarity(f_unaccent(name), f_unaccent(?)) > 0.3", [$term]);
                    $q->orWhereRaw("f_unaccent(name) ILIKE ?", ['%' . $term . '%']);
                    $q->orWhereRaw("f_unaccent(description) ILIKE ?", ['%' . $term . '%']);
                }
            }

            // Strategy 3: Exact phrase match (bonus)
            $q->orWhereRaw("f_unaccent(name) ILIKE ?", ['%' . $searchQuery . '%']);
        });

        // 4. Calculate relevance score
        $selectRaw = "
            products.*,
            (
                -- Score from full-text search (weight: 100)
                COALESCE(ts_rank(search_vector, to_tsquery('french', ?)), 0) * 100 +

                -- Score from trigram similarity on name (weight: 50)
                COALESCE(similarity(f_unaccent(name), f_unaccent(?)), 0) * 50 +

                -- Score from exact match in name (weight: 75)
                CASE WHEN f_unaccent(name) ILIKE ? THEN 75 ELSE 0 END +

                -- Score from partial match in name (weight: 25)
                CASE WHEN f_unaccent(name) ILIKE ? THEN 25 ELSE 0 END +

                -- Score from match in description (weight: 10)
                CASE WHEN f_unaccent(description) ILIKE ? THEN 10 ELSE 0 END
            ) as relevance_score
        ";

        $tsQuery = $this->buildTsQuery($expandedTerms);
        $query->selectRaw(
            $selectRaw,
            [
                $tsQuery,                           // Full-text search
                $searchQuery,                       // Trigram similarity
                $searchQuery,                       // Exact match
                '%' . $searchQuery . '%',          // Partial match in name
                '%' . $searchQuery . '%'           // Match in description
            ]
        );

        // 5. Apply filters
        if ($request->filled('category_id')) {
            $query->where('category_id', $request->input('category_id'));
        }

        if ($request->filled('subcategory_id')) {
            $query->where('subcategory_id', $request->input('subcategory_id'));
        }

        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }

        if ($request->filled('min_price')) {
            $query->where('price', '>=', $request->input('min_price'));
        }

        if ($request->filled('max_price')) {
            $query->where('price', '<=', $request->input('max_price'));
        }

        // 6. Order by relevance score
        $query->orderBy('relevance_score', 'desc');

        // 7. Execute query with pagination
        $products = $query->paginate($perPage);

        // 8. Get favorites if user is authenticated
        $favoriteIds = [];
        if ($request->user()) {
            $favoriteIds = DB::table('favorites')
                ->where('user_id', $request->user()->id)
                ->pluck('product_id')
                ->toArray();
        }

        // 9. Format products for response
        $productsData = $products->getCollection()->map(function ($product) use ($favoriteIds) {
            return $this->formatProduct($product, $favoriteIds);
        });

        Log::info('[SMART_SEARCH] Search completed', [
            'query' => $searchQuery,
            'results_count' => $products->total(),
            'page' => $products->currentPage(),
        ]);

        return response()->json([
            'success' => true,
            'query' => $searchQuery,
            'expanded_terms' => $expandedTerms,
            'products' => $productsData,
            'pagination' => [
                'current_page' => $products->currentPage(),
                'last_page' => $products->lastPage(),
                'per_page' => $products->perPage(),
                'total' => $products->total(),
                'has_more' => $products->hasMorePages(),
            ],
        ]);
    }

    /**
     * Get search suggestions (autocomplete)
     */
    public function suggestions(Request $request)
    {
        $request->validate([
            'q' => 'required|string|min:1',
            'limit' => 'nullable|integer|min:1|max:20',
        ]);

        $query = trim($request->input('q'));
        $limit = $request->input('limit', 10);

        // Get product name suggestions using trigram similarity
        $suggestions = Product::query()
            ->selectRaw("name, similarity(f_unaccent(name), f_unaccent(?)) as sim", [$query])
            ->where('status', 'active')
            ->whereRaw("similarity(f_unaccent(name), f_unaccent(?)) > 0.2", [$query])
            ->orderBy('sim', 'DESC')
            ->limit($limit)
            ->distinct()
            ->pluck('name');

        // Also get synonym suggestions
        $synonymSuggestions = SearchSynonym::query()
            ->where('is_active', true)
            ->where(function ($q) use ($query) {
                $q->where('term', 'ILIKE', $query . '%')
                    ->orWhere('synonym', 'ILIKE', $query . '%');
            })
            ->limit(5)
            ->get()
            ->map(function ($synonym) {
                return [
                    'term' => $synonym->term,
                    'synonym' => $synonym->synonym,
                ];
            });

        return response()->json([
            'success' => true,
            'query' => $query,
            'suggestions' => $suggestions,
            'synonym_suggestions' => $synonymSuggestions,
        ]);
    }

    /**
     * Get popular search terms
     */
    public function popularSearches(Request $request)
    {
        $limit = $request->input('limit', 10);

        // For now, return most common product words
        // In production, you would track actual user searches in a search_logs table
        $popularTerms = [
            'ordinateur',
            'téléphone',
            'vêtement',
            'chaussure',
            'meuble',
            'électronique',
            'sport',
            'livre',
            'jouet',
            'beauté',
        ];

        return response()->json([
            'success' => true,
            'popular_searches' => array_slice($popularTerms, 0, $limit),
        ]);
    }

    /**
     * Build PostgreSQL tsquery from expanded terms
     */
    private function buildTsQuery(array $terms): string
    {
        // Remove accents and special characters from terms
        $cleanedTerms = array_map(function ($term) {
            return preg_replace('/[^a-z0-9\s]/i', '', $term);
        }, $terms);

        $cleanedTerms = array_filter($cleanedTerms, fn($t) => strlen($t) > 0);

        if (empty($cleanedTerms)) {
            return '';
        }

        // Join with OR operator for PostgreSQL tsquery
        return implode(' | ', array_map(fn($term) => $term . ':*', $cleanedTerms));
    }

    /**
     * Format product for API response
     */
    private function formatProduct($product, $favoriteIds = []): array
    {
        $data = [
            'id' => $product->id,
            'name' => $product->name,
            'slug' => $product->slug,
            'price' => (float) $product->price,
            'min_price' => $product->min_price ? (float) $product->min_price : null,
            'max_price' => $product->max_price ? (float) $product->max_price : null,
            'price_type' => $product->price_type ?? 'fixed',
            'formatted_price' => $product->formatted_price,
            'type' => $product->type ?? 'article',
            'weight_category' => $product->weight_category ?? 'X-small',
            'stock' => $product->stock,
            'status' => $product->status,
            'is_favorite' => in_array($product->id, $favoriteIds),
            'relevance_score' => $product->relevance_score ?? 0,
            'primary_image' => $product->primaryImage ? $this->getImageUrl($product->primaryImage->image_path) : null,
            'images' => $product->images->map(fn($img) => [
                'id' => $img->id,
                'url' => $this->getImageUrl($img->image_path),
                'is_primary' => (bool) $img->is_primary,
            ]),
            'category' => $product->category ? [
                'id' => $product->category->id,
                'name' => $product->category->name,
                'slug' => $product->category->slug,
            ] : null,
            'subcategory' => $product->subcategory ? [
                'id' => $product->subcategory->id,
                'name' => $product->subcategory->name,
            ] : null,
            'seller' => $product->user ? [
                'id' => $product->user->id,
                'name' => $product->user->name,
                'avatar' => $product->user->avatar ? $this->getImageUrl($product->user->avatar) : null,
            ] : null,
            'shop' => $product->shop ? [
                'id' => $product->shop->id,
                'name' => $product->shop->name,
                'slug' => $product->shop->slug,
                'logo' => $product->shop->logo ? $this->getImageUrl($product->shop->logo) : null,
                'is_certified' => (bool) $product->shop->is_certified,
                'address' => $product->shop->address,
            ] : null,
            'created_at' => $product->created_at->toIso8601String(),
        ];

        return $data;
    }

    /**
     * Helper to get correct image URL
     */
    private function getImageUrl($imagePath)
    {
        if (empty($imagePath)) {
            return null;
        }

        $cleanPath = str_starts_with($imagePath, 'storage/')
            ? substr($imagePath, 8)
            : $imagePath;

        return asset('storage/' . $cleanPath);
    }
}
