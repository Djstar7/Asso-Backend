<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\WalletTransaction;
use Carbon\Carbon;
use Illuminate\Http\Request;

class TransactionController extends Controller
{
    /**
     * Display a listing of transactions with statistics and filters.
     */
    public function index(Request $request)
    {
        $query = $this->filtered($request);
        $endDate = $request->filled('end_date') ? Carbon::parse($request->end_date)->endOfDay() : now()->endOfDay();
        // The chart is bounded independently of the export/filter date range.
        $chartStartDate = $endDate->copy()->subDays(29)->startOfDay();
        $chartEndDate = $endDate;

        // Pagination
        $transactions = $query->latest()->paginate(20)->withQueryString();

        // Statistiques globales - Créer la requête de base
        $baseStatsQuery = $this->filtered($request);

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
            (object) [
                'payment_method' => 'kpay',
                'label' => 'KPay',
                'count' => (clone $baseStatsQuery)->completed()->where('provider', 'kpay')->count(),
                'total' => (clone $baseStatsQuery)->completed()->where('provider', 'kpay')->sum('amount'),
            ],
            (object) [
                'payment_method' => 'paypal',
                'label' => 'PayPal',
                'count' => (clone $baseStatsQuery)->completed()->where('provider', 'paypal')->count(),
                'total' => (clone $baseStatsQuery)->completed()->where('provider', 'paypal')->sum('amount'),
            ],
        ]);

        // Données pour le graphique (revenu par jour des 30 derniers jours)

        $chartData = [];
        $currentDate = $chartStartDate->copy();

        while ($currentDate <= $chartEndDate) {
            $dayRevenue = (clone $baseStatsQuery)->completed()
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
            'kpay' => (clone $baseStatsQuery)
                ->completed()
                ->where('provider', 'kpay')
                ->whereBetween('created_at', [$chartStartDate, $chartEndDate])
                ->sum('amount'),
            'paypal' => (clone $baseStatsQuery)
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
        $query = $this->filtered($request);

        $transactions = $query->latest()->lazy(500);

        // Créer le CSV
        $filename = 'transactions_'.now()->format('Y-m-d_H-i-s').'.csv';
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ];

        $callback = function () use ($transactions) {
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
                'Référence externe',
            ]);

            // Données
            foreach ($transactions as $transaction) {
                $metadata = $transaction->metadata ?? [];
                fputcsv($file, [
                    $transaction->id,
                    $transaction->created_at->format('d/m/Y H:i'),
                    $this->csvText($transaction->user?->name ?? 'N/A'),
                    $transaction->type_label,
                    $transaction->amount,
                    $transaction->balance_before,
                    $transaction->balance_after,
                    ucfirst($transaction->provider ?? 'N/A'),
                    ucfirst($transaction->status),
                    $this->csvText($transaction->description),
                    $this->csvText($metadata['provider_reference'] ?? 'N/A'),
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
        $query = $this->filtered($request);

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

    private function filtered(Request $request): \Illuminate\Database\Eloquent\Builder
    {
        $request->validate([
            'start_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:2100-12-31'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:2100-12-31'],
            'payment_method' => ['nullable', 'string', 'max:50'],
            'status' => ['nullable', 'string', 'max:50'],
            'type' => ['nullable', 'string', 'max:50'],
        ]);
        if ($request->filled('start_date') && $request->filled('end_date') && $request->start_date > $request->end_date) {
            throw \Illuminate\Validation\ValidationException::withMessages(['end_date' => 'La date de fin doit suivre la date de début.']);
        }
        $query = WalletTransaction::with(['user', 'admin']);
        foreach (['payment_method' => 'provider', 'status' => 'status', 'type' => 'type'] as $field => $column) {
            if ($request->filled($field)) {
                $query->where($column, $request->input($field));
            }
        }
        if ($request->filled('start_date')) {
            $query->where('created_at', '>=', Carbon::parse($request->start_date)->startOfDay());
        }
        if ($request->filled('end_date')) {
            $query->where('created_at', '<=', Carbon::parse($request->end_date)->endOfDay());
        }

        return $query;
    }

    private function csvText(?string $text): string
    {
        return preg_match('/^[\s]*[=+@-]/u', $text ?? '') ? "'".$text : ($text ?? '');
    }
}
