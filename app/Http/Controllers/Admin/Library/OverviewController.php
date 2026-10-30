<?php

namespace App\Http\Controllers\Admin\Library;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

/** Tableau de bord de la Bibliothèque : volume, ventes, fiches à compléter. */
class OverviewController extends Controller
{
    private const SALES_DAYS = 30;

    public function index()
    {
        $books = Product::library();

        $stats = [
            'total' => (clone $books)->count(),
            'active' => (clone $books)->where('status', 'active')->count(),
            'out_of_stock' => (clone $books)->where('stock', '<=', 0)->count(),
            'no_sheet' => (clone $books)->doesntHave('book')->count(),
            'no_isbn' => (clone $books)->whereHas('book', fn ($b) => $b->whereNull('isbn'))->count(),
            'no_image' => (clone $books)->doesntHave('images')->count(),
        ];

        // Ventes payées des 30 derniers jours.
        $paidItems = OrderItem::whereIn('product_id', Product::library()->select('id'))
            ->whereHas('order', fn ($o) => $o->where('payment_status', Order::PAYMENT_PAID)
                ->where('created_at', '>=', now()->subDays(self::SALES_DAYS)));

        $sales = [
            'copies' => (int) (clone $paidItems)->sum('quantity'),
            'revenue' => (float) (clone $paidItems)->sum('total_price'),
        ];

        $topBooks = (clone $paidItems)
            ->select('product_id', DB::raw('SUM(quantity) as copies'), DB::raw('SUM(total_price) as revenue'))
            ->groupBy('product_id')
            ->orderByDesc('copies')
            ->limit(5)
            ->with(['product.book', 'product.primaryImage'])
            ->get();

        $shelves = Category::where('is_library', true)
            ->withCount(['products', 'subcategories'])
            ->orderBy('name')
            ->get();

        return view('admin.library.overview', [
            'stats' => $stats,
            'sales' => $sales,
            'salesDays' => self::SALES_DAYS,
            'topBooks' => $topBooks,
            'shelves' => $shelves,
        ]);
    }
}
