<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    /**
     * List all categories with subcategories and product counts
     */
    public function index(Request $request)
    {
        // Compteurs de l'accueil : catalogue local uniquement. Les produits gros
        // (ASSO CHINA / DUBAÏ / TURQUIE) ont leur propre catalogue (/v1/import/{code}/products).
        $localOnly = fn ($q) => $q->where(function ($p) {
            $p->whereNull('origin_country')
                ->orWhere('origin_country', '');
        });

        $categories = Category::withCount(['products' => $localOnly])
            ->with(['subcategories' => function ($q) use ($localOnly) {
                $q->withCount(['products' => $localOnly]);
            }])
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'categories' => $categories->map(fn($cat) => [
                'id' => $cat->id,
                'name' => $cat->name,
                'name_en' => $cat->name_en,
                'slug' => $cat->slug,
                'description' => $cat->description,
                'svg_icon' => $cat->svg_icon,
                'products_count' => $cat->products_count,
                'subcategories' => $cat->subcategories->map(fn($sub) => [
                    'id' => $sub->id,
                    'name' => $sub->name,
                    'name_en' => $sub->name_en,
                    'slug' => $sub->slug,
                    'products_count' => $sub->products_count,
                ]),
            ]),
        ]);
    }
}
