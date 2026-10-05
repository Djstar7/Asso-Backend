<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Subcategory;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CategorySettingsController extends Controller
{
    /**
     * Display the category settings page
     */
    public function index()
    {
        $categories = Category::withCount(['subcategories', 'products'])->get();
        $subcategories = Subcategory::with('category')->withCount('products')->get();

        return view('admin.settings.categories', compact('categories', 'subcategories'));
    }

    /**
     * Store a new category
     */
    public function storeCategory(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'name_en' => 'required|string|max:255',
            'svg_icon' => 'nullable|string',
            'description' => 'nullable|string',
            'translations.en.description' => 'nullable|string',
        ]);

        $validated['slug'] = Str::slug($validated['name']);

        $category = Category::create($validated);
        $category->syncTranslations($validated['translations'] ?? null);

        return redirect()->back()->with('success', 'Catégorie créée avec succès!');
    }

    /**
     * Update a category
     */
    public function updateCategory(Request $request, Category $category)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'name_en' => 'required|string|max:255',
            'svg_icon' => 'nullable|string',
            'description' => 'nullable|string',
            'translations.en.description' => 'nullable|string',
        ]);

        $validated['slug'] = Str::slug($validated['name']);

        $category->update($validated);
        $category->syncTranslations($validated['translations'] ?? null);

        return redirect()->back()->with('success', 'Catégorie mise à jour avec succès!');
    }

    /**
     * Delete a category
     */
    public function destroyCategory(Category $category)
    {
        // Check if category has products
        if ($category->products()->count() > 0) {
            return redirect()->back()->with('error', 'Impossible de supprimer cette catégorie car elle contient des produits.');
        }

        $category->delete();

        return redirect()->back()->with('success', 'Catégorie supprimée avec succès!');
    }

    /**
     * Store a new subcategory
     */
    public function storeSubcategory(Request $request)
    {
        $validated = $request->validate([
            'category_id' => 'bail|required|integer|exists:categories,id',
            'name' => 'required|string|max:255',
            'name_en' => 'required|string|max:255',
        ]);

        $validated['slug'] = Str::slug($validated['name']);

        Subcategory::create($validated);

        return redirect()->back()->with('success', 'Sous-catégorie créée avec succès!');
    }

    /**
     * Update a subcategory
     */
    public function updateSubcategory(Request $request, Subcategory $subcategory)
    {
        $validated = $request->validate([
            'category_id' => 'bail|required|integer|exists:categories,id',
            'name' => 'required|string|max:255',
            'name_en' => 'required|string|max:255',
        ]);

        $validated['slug'] = Str::slug($validated['name']);

        $subcategory->update($validated);

        return redirect()->back()->with('success', 'Sous-catégorie mise à jour avec succès!');
    }

    /**
     * Delete a subcategory
     */
    public function destroySubcategory(Subcategory $subcategory)
    {
        // Check if subcategory has products
        if ($subcategory->products()->count() > 0) {
            return redirect()->back()->with('error', 'Impossible de supprimer cette sous-catégorie car elle contient des produits.');
        }

        $subcategory->delete();

        return redirect()->back()->with('success', 'Sous-catégorie supprimée avec succès!');
    }
}
