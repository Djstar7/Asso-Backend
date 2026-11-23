<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ImportCjProductJob;
use App\Models\Category;
use App\Models\Product;
use App\Models\Subcategory;
use App\Models\Supplier;
use App\Models\SupplierCategory;
use App\Models\SupplierProduct;
use App\Services\Suppliers\Cj\CjApiException;
use App\Services\Suppliers\SupplierManager;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Catalogue CJ dans le back-office (admin uniquement) : correspondance des
 * catégories, recherche et import, suivi et publication des fiches importées.
 */
class SupplierCatalogController extends Controller
{
    public function __construct(private SupplierManager $suppliers) {}

    public function categories(Request $request)
    {
        $cj = Supplier::forCode(Supplier::CJ);
        $query = SupplierCategory::where('supplier_id', $cj->id)->with(['category', 'subcategory'])->orderBy('path');
        if ($search = trim((string) $request->input('q'))) {
            $query->where('path', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%');
        }
        match ($request->input('mapped')) {
            'yes' => $query->whereNotNull('category_id'),
            'no' => $query->whereNull('category_id'),
            default => null,
        };

        return view('admin.suppliers.categories', [
            'cj' => $cj,
            'categories' => $query->paginate(50)->withQueryString(),
            'targets' => $this->targets(),
            'mappedCount' => SupplierCategory::where('supplier_id', $cj->id)->whereNotNull('category_id')->count(),
            'totalCount' => SupplierCategory::where('supplier_id', $cj->id)->count(),
        ]);
    }

    public function syncCategories()
    {
        try {
            $count = $this->suppliers->driver(Supplier::CJ)->catalog()->syncCategories();
        } catch (CjApiException $e) {
            return back()->with('error', 'Catégories CJ non récupérées : '.$e->describe());
        }

        return back()->with('success', "{$count} catégories CJ synchronisées. Les correspondances déjà faites sont conservées.");
    }

    public function mapCategory(Request $request, SupplierCategory $supplierCategory)
    {
        [$categoryId, $subcategoryId] = $this->parseTarget($request->input('target'));
        $supplierCategory->update(['category_id' => $categoryId, 'subcategory_id' => $subcategoryId]);

        return back()->with('success', $categoryId
            ? "« {$supplierCategory->name} » est reliée."
            : "« {$supplierCategory->name} » n’est plus reliée.");
    }

    public function search(Request $request)
    {
        $cj = Supplier::forCode(Supplier::CJ);
        $filters = $request->validate([
            'keyword' => 'nullable|string|max:200',
            'category' => 'nullable|string|max:100',
            'min_price' => 'nullable|numeric|min:0',
            'max_price' => 'nullable|numeric|min:0',
            'page' => 'nullable|integer|min:1|max:1000',
        ]);
        $result = null;
        $error = null;
        if (array_filter(array_intersect_key($filters, array_flip(['keyword', 'category', 'min_price', 'max_price'])), fn ($v) => $v !== null && $v !== '')) {
            if (! $cj->isConnected()) {
                $error = 'CJ n’est pas connecté : testez la connexion dans l’onglet Connexion.';
            } else {
                try {
                    $result = $this->suppliers->driver(Supplier::CJ)->catalog()->search($filters, (int) ($filters['page'] ?? 1), 40);
                } catch (CjApiException $e) {
                    $error = 'Recherche CJ impossible : '.$e->describe();
                }
            }
        }

        $known = $result
            ? SupplierProduct::where('supplier_id', $cj->id)->whereIn('external_id', array_column($result['items'], 'id'))->pluck('status', 'external_id')->all()
            : [];
        $mapped = $result
            ? SupplierCategory::where('supplier_id', $cj->id)->whereIn('external_id', array_filter(array_column($result['items'], 'category_id')))->whereNotNull('category_id')->pluck('external_id')->flip()->all()
            : [];

        return view('admin.suppliers.catalog', [
            'cj' => $cj,
            'filters' => $filters,
            'result' => $result,
            'error' => $error,
            'known' => $known,
            'mapped' => $mapped,
            'targets' => $this->targets(),
            'cjCategories' => SupplierCategory::where('supplier_id', $cj->id)->orderBy('path')->get(['external_id', 'path']),
        ]);
    }

    public function import(Request $request)
    {
        $data = $request->validate([
            'pids' => 'required|array|min:1|max:100',
            'pids.*' => 'required|string|max:100|regex:/^[A-Za-z0-9-]+$/',
        ], ['pids.required' => 'Cochez au moins un produit.']);
        [$categoryId, $subcategoryId] = $this->parseTarget($request->input('target'));

        foreach (array_unique($data['pids']) as $pid) {
            ImportCjProductJob::dispatch($pid, $categoryId, $subcategoryId);
        }

        return redirect()->route('admin.suppliers.cj.products')->with('success',
            count(array_unique($data['pids'])).' produit(s) mis en file d’import. Les fiches arrivent inactives : relisez-les puis publiez-les.');
    }

    public function products(Request $request)
    {
        $cj = Supplier::forCode(Supplier::CJ);
        $query = SupplierProduct::where('supplier_id', $cj->id)->with('product')->latest('updated_at');
        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }
        if ($search = trim((string) $request->input('q'))) {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%';
            $query->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('external_id', $search)->orWhere('external_sku', $search));
        }
        if ($request->input('published') === 'no') {
            $query->where('status', SupplierProduct::IMPORTED)
                ->whereHas('product', fn ($p) => $p->where('status', '!=', 'active'));
        }

        return view('admin.suppliers.products', [
            'cj' => $cj,
            'products' => $query->paginate(30)->withQueryString(),
            'counts' => SupplierProduct::where('supplier_id', $cj->id)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')->all(),
        ]);
    }

    public function retry(SupplierProduct $supplierProduct)
    {
        abort_unless($supplierProduct->supplier?->code === Supplier::CJ, 404);
        $product = $supplierProduct->product;
        ImportCjProductJob::dispatch($supplierProduct->external_id, $product?->category_id, $product?->subcategory_id);

        return back()->with('success', 'Import relancé pour « '.$supplierProduct->name.' ».');
    }

    public function publish(Request $request)
    {
        $data = $request->validate(['ids' => 'required|array|min:1', 'ids.*' => 'integer']);
        $cj = Supplier::forCode(Supplier::CJ);
        $productIds = SupplierProduct::where('supplier_id', $cj->id)->whereIn('id', $data['ids'])
            ->where('status', SupplierProduct::IMPORTED)->whereNotNull('product_id')->pluck('product_id');

        $published = 0;
        Product::withoutGlobalScopes()->whereIn('id', $productIds)->with('images')->get()
            ->each(function (Product $product) use (&$published) {
                if ($product->images->isNotEmpty() && (float) $product->price > 0) {
                    $product->update(['status' => 'active']);
                    $published++;
                }
            });

        $skipped = count($data['ids']) - $published;

        return back()->with('success', "{$published} fiche(s) publiée(s).".($skipped > 0 ? " {$skipped} ignorée(s) : import incomplet ou en erreur." : ''));
    }

    /** Choix « catégorie » ou « catégorie > sous-catégorie » des formulaires. */
    private function targets(): array
    {
        $targets = [];
        foreach (Category::where(fn ($q) => $q->where('is_library', false)->orWhereNull('is_library'))->orderBy('name')->get() as $category) {
            $targets[(string) $category->id] = $category->name;
            foreach (Subcategory::where('category_id', $category->id)->orderBy('name')->get() as $sub) {
                $targets[$category->id.':'.$sub->id] = $category->name.' > '.$sub->name;
            }
        }

        return $targets;
    }

    /** @return array{0: ?int, 1: ?int} */
    private function parseTarget(?string $target): array
    {
        if ($target === null || $target === '') {
            return [null, null];
        }
        if (! preg_match('/^(\d+)(?::(\d+))?$/', $target, $m) || ! Category::whereKey((int) $m[1])->exists()) {
            throw ValidationException::withMessages(['target' => 'Catégorie ASSO invalide.']);
        }
        $sub = isset($m[2]) ? (int) $m[2] : null;
        if ($sub && ! Subcategory::whereKey($sub)->where('category_id', (int) $m[1])->exists()) {
            throw ValidationException::withMessages(['target' => 'Sous-catégorie ASSO invalide.']);
        }

        return [(int) $m[1], $sub];
    }
}
