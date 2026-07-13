<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\WalletTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class TransactionController extends Controller
{
    /**
     * Display a listing of transactions with statistics and filters.
     */
    public function index(Request $request)
    {
        // Filtres - Utilise WalletTransactions (vraies transactions)
        $query = WalletTransaction::with(['user', 'admin']);

        // Filtre par provider (méthode de paiement)
        if ($request->filled('payment_method')) {
            $query->where('provider', $request->payment_method);
        }

        // Filtre par statut
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Filtre par type (credit, debit, refund, bonus)
        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        // Filtre par période
        $startDate = $request->filled('start_date') ? Carbon::parse($request->start_date)->startOfDay() : null;
        $endDate = $request->filled('end_date') ? Carbon::parse($request->end_date)->endOfDay() : null;

        if ($startDate && $endDate) {
            $query->whereBetween('created_at', [$startDate, $endDate]);
        } elseif ($startDate) {
            $query->where('created_at', '>=', $startDate);
        } elseif ($endDate) {
            $query->where('created_at', '<=', $endDate);
        }

        // Période de comparaison pour les statistiques
        $comparisonStartDate = $startDate ? $startDate->copy() : now()->startOfMonth();
        $comparisonEndDate = $endDate ? $endDate->copy() : now()->endOfMonth();

        // Pagination
        $transactions = $query->latest()->paginate(20)->withQueryString();

        // Statistiques globales - Créer la requête de base
        $baseStatsQuery = WalletTransaction::query();
        if ($startDate && $endDate) {
            $baseStatsQuery->whereBetween('created_at', [$startDate, $endDate]);
        }

        $stats = [
            // Total des crédits (recharges)
            'total_credits' => (clone $baseStatsQuery)->completed()->whereIn('type', ['credit', 'refund', 'bonus'])->sum('amount'),
            // Total des débits (retraits/paiements)
            'total_debits' => (clone $baseStatsQuery)->completed()->where('type', 'debit')->sum('amount'),
            // Net = crédits - débits
            'net_revenue' => (clone $baseStatsQuery)->completed()->whereIn('type', ['credit', 'refund', 'bonus'])->sum('amount')
                           - (clone $baseStatsQuery)->completed()->where('type', 'debit')->sum('amount'),
            'total_transactions' => (clone $baseStatsQuery)->completed()->count(),
            'pending_transactions' => (clone $baseStatsQuery)->where('status', 'pending')->count(),
            'failed_transactions' => (clone $baseStatsQuery)->where('status', 'failed')->count(),
        ];

        // Statistiques par provider (méthode de paiement)
        $paymentMethodStats = collect([
            (object)[
                'payment_method' => 'kpay',
                'label' => 'KPay',
                'count' => (clone $baseStatsQuery)->completed()->where('provider', 'kpay')->count(),
                'total' => (clone $baseStatsQuery)->completed()->where('provider', 'kpay')->sum('amount'),
            ],
            (object)[
                'payment_method' => 'paypal',
                'label' => 'PayPal',
                'count' => (clone $baseStatsQuery)->completed()->where('provider', 'paypal')->count(),
                'total' => (clone $baseStatsQuery)->completed()->where('provider', 'paypal')->sum('amount'),
            ],
        ]);

        // Données pour le graphique (revenu par jour des 30 derniers jours)
        $chartStartDate = $comparisonStartDate->copy()->subDays(29);
        $chartEndDate = $comparisonEndDate->copy();

        $chartData = [];
        $currentDate = $chartStartDate->copy();

        while ($currentDate <= $chartEndDate) {
            $dayRevenue = WalletTransaction::completed()
                ->whereDate('created_at', $currentDate)
                ->whereIn('type', ['credit', 'refund', 'bonus'])
                ->sum('amount');

            $chartData[] = [
                'date' => $currentDate->format('Y-m-d'),
                'label' => $currentDate->format('d/m'),
                'revenue' => $dayRevenue,
            ];

            $currentDate->addDay();
        }

        // Données pour le graphique par provider
        $paymentMethodChartData = [
            'kpay' => WalletTransaction::query()
                ->completed()
                ->where('provider', 'kpay')
                ->whereBetween('created_at', [$chartStartDate, $chartEndDate])
                ->sum('amount'),
            'paypal' => WalletTransaction::query()
                ->completed()
                ->where('provider', 'paypal')
                ->whereBetween('created_at', [$chartStartDate, $chartEndDate])
                ->sum('amount'),
        ];

        return view('admin.transactions.index', compact(
            'transactions',
            'stats',
            'paymentMethodStats',
            'chartData',
            'paymentMethodChartData'
        ));
    }

    /**
     * Export transactions to Excel
     */
    public function exportExcel(Request $request)
    {
        $query = WalletTransaction::with(['user', 'admin']);

        // Appliquer les mêmes filtres que l'index
        if ($request->filled('payment_method')) {
            $query->where('provider', $request->payment_method);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        if ($request->filled('start_date')) {
            $query->where('created_at', '>=', Carbon::parse($request->start_date)->startOfDay());
        }

        if ($request->filled('end_date')) {
            $query->where('created_at', '<=', Carbon::parse($request->end_date)->endOfDay());
        }

        $transactions = $query->latest()->get();

        // Créer le CSV
        $filename = 'transactions_' . now()->format('Y-m-d_H-i-s') . '.csv';
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ];

        $callback = function() use ($transactions) {
            $file = fopen('php://output', 'w');

            // En-têtes CSV
            fputcsv($file, [
                'ID',
                'Date',
                'Utilisateur',
                'Type',
                'Montant (FCFA)',
                'Solde avant',
                'Solde après',
                'Provider',
                'Statut',
                'Description',
                'Référence externe'
            ]);

            // Données
            foreach ($transactions as $transaction) {
                $metadata = $transaction->metadata ?? [];
                fputcsv($file, [
                    $transaction->id,
                    $transaction->created_at->format('d/m/Y H:i'),
                    $transaction->user?->name ?? 'N/A',
                    $transaction->type_label,
                    $transaction->amount,
                    $transaction->balance_before,
                    $transaction->balance_after,
                    ucfirst($transaction->provider ?? 'N/A'),
                    ucfirst($transaction->status),
                    $transaction->description,
                    $metadata['provider_reference'] ?? 'N/A'
                ]);
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * Export transactions to PDF
     */
    public function exportPdf(Request $request)
    {
        $query = WalletTransaction::with(['user', 'admin']);

        // Appliquer les mêmes filtres que l'index
        if ($request->filled('payment_method')) {
            $query->where('provider', $request->payment_method);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        if ($request->filled('start_date')) {
            $query->where('created_at', '>=', Carbon::parse($request->start_date)->startOfDay());
        }

        if ($request->filled('end_date')) {
            $query->where('created_at', '<=', Carbon::parse($request->end_date)->endOfDay());
        }

        $transactions = $query->latest()->limit(100)->get(); // Limiter à 100 pour le PDF

        // Statistiques
        $totalCredits = $transactions->where('status', 'completed')->whereIn('type', ['credit', 'refund', 'bonus'])->sum('amount');
        $totalDebits = $transactions->where('status', 'completed')->where('type', 'debit')->sum('amount');
        $netRevenue = $totalCredits - $totalDebits;

        // Générer le HTML pour le PDF
        $html = view('admin.transactions.pdf', compact('transactions', 'totalCredits', 'totalDebits', 'netRevenue'))->render();

        // Retourner le HTML (l'utilisateur peut imprimer en PDF depuis le navigateur)
        return response($html)->header('Content-Type', 'text/html');
    }

    /**
     * Show the specified transaction.
     */
    public function show(WalletTransaction $transaction)
    {
        $transaction->load(['user', 'admin']);
        return view('admin.transactions.show', compact('transaction'));
    }
}
