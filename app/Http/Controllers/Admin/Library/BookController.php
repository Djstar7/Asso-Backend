<?php

namespace App\Http\Controllers\Admin\Library;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductBook;
use App\Models\Shop;
use Illuminate\Http\Request;

/**
 * Liste des livres de la section Bibliothèque. Création et modification
 * passent par le formulaire produit en mode Bibliothèque
 * (admin.products.create?library=1 / admin.products.edit).
 */
class BookController extends Controller
{
    /** Contrôles de qualité proposés en filtre (« à compléter »). */
    public const ISSUES = [
        'no_sheet' => 'Sans fiche livre',
        'no_isbn' => 'Sans ISBN',
        'no_image' => 'Sans couverture',
        'out_of_stock' => 'En rupture',
    ];

    public function index(Request $request)
    {
        $query = Product::library()
            ->with(['book', 'shop', 'category', 'subcategory', 'primaryImage'])
            ->latest();

        if ($search = trim((string) $request->get('search'))) {
            // LOWER() plutôt qu'ILIKE : identique sous PostgreSQL et SQLite (tests).
            $like = '%' . mb_strtolower($search) . '%';
            $isbn = ProductBook::normalizeIsbn($search);
            $query->where(fn ($q) => $q->whereRaw('LOWER(products.name) LIKE ?', [$like])
                ->orWhereHas('book', fn ($b) => $b->whereRaw('LOWER(author) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(publisher) LIKE ?', [$like])
                    ->when($isbn, fn ($b) => $b->orWhere('isbn', 'like', '%' . $isbn . '%'))));
        }

        $query->when($request->filled('category_id'), fn ($q) => $q->where('category_id', $request->category_id))
            ->when($request->filled('shop_id'), fn ($q) => $q->where('shop_id', $request->shop_id))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('language'), fn ($q) => $q->whereHas('book', fn ($b) => $b->where('language', $request->language)))
            ->when($request->filled('format'), fn ($q) => $q->whereHas('book', fn ($b) => $b->where('format', $request->format)));

        match ($request->get('issue')) {
            'no_sheet' => $query->doesntHave('book'),
            'no_isbn' => $query->whereHas('book', fn ($b) => $b->whereNull('isbn')),
            'no_image' => $query->doesntHave('images'),
            'out_of_stock' => $query->where('stock', '<=', 0),
            default => null,
        };

        $books = $query->paginate(25)->withQueryString();
        $shelves = Category::where('is_library', true)->orderBy('name')->get();
        $shops = Shop::whereIn('id', Product::library()->select('shop_id'))->orderBy('name')->get();

        return view('admin.library.books.index', [
            'books' => $books,
            'shelves' => $shelves,
            'shops' => $shops,
            'issues' => self::ISSUES,
        ]);
    }
}
