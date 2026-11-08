<?php

namespace App\Http\Controllers\Admin\Library;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Subcategory;
use App\Support\DeliveryDelay;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Rayons de la Bibliothèque (catégories `is_library`) et leurs genres
 * (sous-catégories). Ils n'apparaissent plus dans la page Catégories.
 */
class ShelfController extends Controller
{
    public function index()
    {
        $shelves = Category::where('is_library', true)
            ->withCount('products')
            ->with(['subcategories' => fn ($q) => $q->withCount('products')->orderBy('name')])
            ->orderBy('name')
            ->get();

        return view('admin.library.shelves', compact('shelves'));
    }

    public function store(Request $request)
    {
        $validated = $this->validateShelf($request);
        $validated['slug'] = Str::slug($validated['name']);
        $validated['is_library'] = true;

        $shelf = Category::create($validated);
        $shelf->syncTranslations($validated['translations'] ?? null);

        return back()->with('success', 'Rayon créé.');
    }

    public function update(Request $request, Category $shelf)
    {
        $this->ensureShelf($shelf);

        $validated = $this->validateShelf($request);
        $validated['slug'] = Str::slug($validated['name']);

        $shelf->update($validated);
        $shelf->syncTranslations($validated['translations'] ?? null);

        return back()->with('success', 'Rayon mis à jour.');
    }

    public function destroy(Category $shelf)
    {
        $this->ensureShelf($shelf);

        if ($shelf->products()->exists()) {
            return back()->with('error', 'Ce rayon contient des livres : déplacez-les avant de le supprimer.');
        }

        $shelf->subcategories()->delete();
        $shelf->delete();

        return back()->with('success', 'Rayon supprimé.');
    }

    public function storeGenre(Request $request, Category $shelf)
    {
        $this->ensureShelf($shelf);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'name_en' => 'required|string|max:255',
        ]);

        $shelf->subcategories()->create($validated + ['slug' => Str::slug($validated['name'])]);

        return back()->with('success', 'Genre ajouté au rayon ' . $shelf->name . '.');
    }

    public function destroyGenre(Subcategory $genre)
    {
        $this->ensureShelf($genre->category);

        if ($genre->products()->exists()) {
            return back()->with('error', 'Ce genre contient des livres : impossible de le supprimer.');
        }

        $genre->delete();

        return back()->with('success', 'Genre supprimé.');
    }

    private function validateShelf(Request $request): array
    {
        return $request->validate([
            'name' => 'required|string|max:255',
            'name_en' => 'required|string|max:255',
            'svg_icon' => 'nullable|string',
            'description' => 'nullable|string',
            'translations.en.description' => 'nullable|string',
        ] + DeliveryDelay::rules());
    }

    /** Ces routes ne touchent qu'aux rayons, jamais aux catégories du catalogue général. */
    private function ensureShelf(?Category $category): void
    {
        abort_unless($category?->is_library, 404);
    }
}
