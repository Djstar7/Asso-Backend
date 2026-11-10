<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Product;
use App\Models\WalletTransaction;
use App\Models\Exchange;
use App\Models\Category;
use App\Models\Shop;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class DashboardController extends Controller
{
    /**
     * Display dashboard
     */
    public function index()
    {
        // Users statistics
        $stats['users'] = [
            'total' => User::count(),
            'livreurs' => User::where('role', 'livreur')->count(),
            'vendeurs' => User::where('role', 'vendeur')->count(),
            'clients' => User::where('role', 'client')->count(),
            'livreurs_today' => User::where('role', 'livreur')->whereDate('created_at', today())->count(),
            'vendeurs_today' => User::where('role', 'vendeur')->whereDate('created_at', today())->count(),
            'clients_today' => User::where('role', 'client')->whereDate('created_at', today())->count(),
        ];

        // Products statistics
        $stats['products'] = [
            'total' => Product::count(),
        ];

        // Transactions : mêmes données que Finance → Transactions (portefeuilles), depuis
        // la dernière remise à zéro des compteurs. L'ancienne table `transactions` n'est
        // plus alimentée par les paiements.
        $walletTransactions = fn () => WalletTransaction::query()
            ->when(\App\Support\FinanceStatsReset::since(), fn ($q, $since) => $q->where('created_at', '>=', $since));
        $stats['transactions'] = [
            'total' => $walletTransactions()->count(),
        ];

        // Exchanges statistics
        $stats['exchanges'] = [
            'total' => Exchange::count(),
        ];

        // Categories statistics
        $stats['categories'] = [
            'total' => Category::count(),
            'sub_total' => \App\Models\Subcategory::count(),
        ];

        // Chart data for last 30 days
        $chartData = [
            'users' => [
                'labels' => $this->getLast30DaysLabels(),
                'livreurs' => $this->getLast30DaysData(User::where('role', 'livreur')),
                'vendeurs' => $this->getLast30DaysData(User::where('role', 'vendeur')),
                'clients' => $this->getLast30DaysData(User::where('role', 'client')),
            ],
            'transactions' => [
                'labels' => $this->getLast30DaysLabels(),
                'data' => $this->getLast30DaysData($walletTransactions()),
            ],
            'exchanges' => [
                'labels' => $this->getLast30DaysLabels(),
                'data' => $this->getLast30DaysData(Exchange::query()),
            ],
        ];

        // Top categories
        $topCategories = Category::withCount('products')
            ->orderBy('products_count', 'desc')
            ->take(5)
            ->get();

        // Recent products
        $recentProducts = Product::latest()->take(5)->get();

        // Recent transactions
        $recentTransactions = $walletTransactions()->with('user')->latest()->take(5)->get();

        // Pending shops (awaiting verification)
        $pendingShops = Shop::pending()
            ->with('user')
            ->latest()
            ->get();

        return view('admin.dashboard', compact('stats', 'chartData', 'topCategories', 'recentProducts', 'recentTransactions', 'pendingShops'));
    }

    /**
     * Get last 30 days labels
     */
    private function getLast30DaysLabels()
    {
        $labels = [];
        for ($i = 29; $i >= 0; $i--) {
            $labels[] = Carbon::now()->subDays($i)->format('d M');
        }
        return $labels;
    }

    /**
     * Get last 30 days data
     */
    private function getLast30DaysData($query)
    {
        $data = [];
        for ($i = 29; $i >= 0; $i--) {
            $date = Carbon::now()->subDays($i)->toDateString();
            $count = (clone $query)->whereDate('created_at', $date)->count();
            $data[] = $count;
        }
        return $data;
    }
}
