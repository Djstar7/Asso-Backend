@extends('admin.layouts.app')

@section('content')
<div class="p-6">
    <!-- Header -->
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-white">Transactions</h1>
            <p class="text-gray-400">Gérez et analysez toutes les transactions de paiement</p>
        </div>
        <div class="flex gap-2">
            <a href="{{ route('admin.transactions.export-excel', request()->query()) }}"
               class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors">
                <i class="fas fa-file-excel mr-2"></i>
                Excel
            </a>
            <a href="{{ route('admin.transactions.export-pdf', request()->query()) }}"
               target="_blank"
               class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors">
                <i class="fas fa-file-pdf mr-2"></i>
                PDF
            </a>
        </div>
    </div>

    <!-- Filters -->
    <div class="mb-6 bg-dark-100 rounded-xl shadow-lg border border-dark-200 p-6">
        <form method="GET" action="{{ route('admin.transactions.index') }}" class="grid grid-cols-1 md:grid-cols-5 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-300 mb-2">Date de début</label>
                <input type="date" name="start_date" value="{{ request('start_date') }}"
                       class="w-full px-4 py-2 bg-dark-50 border border-dark-300 rounded-lg text-white focus:ring-2 focus:ring-primary-500">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-300 mb-2">Date de fin</label>
                <input type="date" name="end_date" value="{{ request('end_date') }}"
                       class="w-full px-4 py-2 bg-dark-50 border border-dark-300 rounded-lg text-white focus:ring-2 focus:ring-primary-500">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-300 mb-2">Type</label>
                <select name="type"
                        class="w-full px-4 py-2 bg-dark-50 border border-dark-300 rounded-lg text-white focus:ring-2 focus:ring-primary-500">
                    <option value="">Tous</option>
                    <option value="credit" {{ request('type') == 'credit' ? 'selected' : '' }}>Crédit (Recharge)</option>
                    <option value="debit" {{ request('type') == 'debit' ? 'selected' : '' }}>Débit (Retrait)</option>
                    <option value="refund" {{ request('type') == 'refund' ? 'selected' : '' }}>Remboursement</option>
                    <option value="bonus" {{ request('type') == 'bonus' ? 'selected' : '' }}>Bonus</option>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-300 mb-2">Provider</label>
                <select name="payment_method"
                        class="w-full px-4 py-2 bg-dark-50 border border-dark-300 rounded-lg text-white focus:ring-2 focus:ring-primary-500">
                    <option value="">Tous</option>
                    <option value="kpay" {{ request('payment_method') == 'kpay' ? 'selected' : '' }}>KPay</option>
                    <option value="paypal" {{ request('payment_method') == 'paypal' ? 'selected' : '' }}>PayPal</option>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-300 mb-2">Statut</label>
                <select name="status"
                        class="w-full px-4 py-2 bg-dark-50 border border-dark-300 rounded-lg text-white focus:ring-2 focus:ring-primary-500">
                    <option value="">Tous</option>
                    <option value="completed" {{ request('status') == 'completed' ? 'selected' : '' }}>Complété</option>
                    <option value="pending" {{ request('status') == 'pending' ? 'selected' : '' }}>En attente</option>
                    <option value="failed" {{ request('status') == 'failed' ? 'selected' : '' }}>Échoué</option>
                </select>
            </div>
            <div class="md:col-span-5 flex gap-2">
                <button type="submit"
                        class="px-6 py-2 bg-gradient-to-r from-primary-500 to-primary-600 text-white rounded-lg hover:shadow-lg transition-all">
                    <i class="fas fa-search mr-2"></i> Filtrer
                </button>
                <a href="{{ route('admin.transactions.index') }}"
                   class="px-6 py-2 bg-dark-300 text-white rounded-lg hover:bg-dark-400 transition-all">
                    <i class="fas fa-redo mr-2"></i> Réinitialiser
                </a>
            </div>
        </form>
    </div>

    <!-- Statistics Cards -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
        <div class="bg-gradient-to-br from-green-500/10 to-green-600/10 border border-green-500/30 rounded-xl p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-400 mb-1">Total Crédits</p>
                    <p class="text-2xl font-bold text-white">{{ number_format($stats['total_credits'], 0, ',', ' ') }} <span class="text-sm">FCFA</span></p>
                    <p class="text-xs text-green-400 mt-1">Recharges + Remboursements</p>
                </div>
                <div class="w-12 h-12 bg-green-500/20 rounded-lg flex items-center justify-center">
                    <i class="fas fa-arrow-down text-green-500 text-xl"></i>
                </div>
            </div>
        </div>

        <div class="bg-gradient-to-br from-red-500/10 to-red-600/10 border border-red-500/30 rounded-xl p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-400 mb-1">Total Débits</p>
                    <p class="text-2xl font-bold text-white">{{ number_format($stats['total_debits'], 0, ',', ' ') }} <span class="text-sm">FCFA</span></p>
                    <p class="text-xs text-red-400 mt-1">Retraits + Paiements</p>
                </div>
                <div class="w-12 h-12 bg-red-500/20 rounded-lg flex items-center justify-center">
                    <i class="fas fa-arrow-up text-red-500 text-xl"></i>
                </div>
            </div>
        </div>

        <div class="bg-gradient-to-br from-purple-500/10 to-purple-600/10 border border-purple-500/30 rounded-xl p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-400 mb-1">Transactions</p>
                    <p class="text-2xl font-bold text-white">{{ $stats['total_transactions'] }}</p>
                    <p class="text-xs text-gray-500 mt-1">
                        {{ $stats['pending_transactions'] }} en attente · {{ $stats['failed_transactions'] }} échouées
                    </p>
                </div>
                <div class="w-12 h-12 bg-purple-500/20 rounded-lg flex items-center justify-center">
                    <i class="fas fa-receipt text-purple-500 text-xl"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Charts -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
        <!-- Revenue Chart -->
        <div class="bg-dark-100 rounded-xl shadow-lg border border-dark-200 p-6">
            <h3 class="text-lg font-semibold text-white mb-4 flex items-center">
                <i class="fas fa-chart-area text-primary-500 mr-2"></i>
                Évolution du Revenu (30 derniers jours)
            </h3>
            <div style="position: relative; height: 250px;">
                <canvas id="revenueChart"></canvas>
            </div>
        </div>

        <!-- Payment Methods Chart -->
        <div class="bg-dark-100 rounded-xl shadow-lg border border-dark-200 p-6">
            <h3 class="text-lg font-semibold text-white mb-4 flex items-center">
                <i class="fas fa-chart-pie text-primary-500 mr-2"></i>
                Répartition par Méthode de Paiement
            </h3>
            <div style="position: relative; height: 250px;">
                <canvas id="paymentMethodsChart"></canvas>
            </div>
        </div>
    </div>

    <!-- Payment Methods Statistics -->
    <div class="mb-6 bg-dark-100 rounded-xl shadow-lg border border-dark-200 p-6">
        <h3 class="text-lg font-semibold text-white mb-4">Statistiques par Provider</h3>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <!-- Total -->
            <div class="bg-gradient-to-br from-primary-500/10 to-primary-600/10 border border-primary-500/30 rounded-lg p-4">
                <div class="flex items-center justify-between mb-2">
                    <span class="text-sm text-gray-400">
                        <i class="fas fa-chart-bar text-primary-500 mr-1"></i> TOTAL
                    </span>
                    <span class="text-xs bg-primary-500/20 text-primary-300 px-2 py-1 rounded">{{ $stats['total_transactions'] }}</span>
                </div>
                <p class="text-2xl font-bold text-white">{{ number_format($stats['total_credits'], 0, ',', ' ') }} <span class="text-sm text-gray-400">FCFA</span></p>
            </div>

            @foreach($paymentMethodStats as $stat)
                <div class="bg-dark-50 rounded-lg p-4 border border-dark-300">
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-sm text-gray-400">
                            @if($stat->payment_method == 'paypal')
                                <i class="fab fa-paypal text-blue-500 mr-1"></i> PayPal
                            @elseif($stat->payment_method == 'kpay')
                                <i class="fas fa-mobile-alt text-orange-500 mr-1"></i> KPay
                            @endif
                        </span>
                        <span class="text-xs bg-primary-500/20 text-primary-300 px-2 py-1 rounded">{{ $stat->count }}</span>
                    </div>
                    <p class="text-lg font-bold text-white">{{ number_format($stat->total, 0, ',', ' ') }} <span class="text-sm text-gray-400">FCFA</span></p>
                </div>
            @endforeach
        </div>
    </div>

    <!-- Transactions Table -->
    <div class="bg-dark-100 rounded-xl shadow-lg border border-dark-200">
        @if($transactions->count() > 0)
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-dark-200">
                    <thead class="bg-dark-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-white uppercase tracking-wider">ID</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-white uppercase tracking-wider">Date</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-white uppercase tracking-wider">Utilisateur</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-white uppercase tracking-wider">Type</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-white uppercase tracking-wider">Montant</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-white uppercase tracking-wider">Provider</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-white uppercase tracking-wider">Statut</th>
                            <th class="px-6 py-3 text-right text-xs font-semibold text-white uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="bg-dark-100 divide-y divide-dark-200">
                        @foreach($transactions as $transaction)
                            <tr class="hover:bg-dark-50 transition-colors">
                                <td class="px-6 py-4">
                                    <div class="text-sm">
                                        <div class="font-medium text-white">#{{ $transaction->id }}</div>
                                        @php
                                            $metadata = $transaction->metadata ?? [];
                                            $providerRef = $metadata['provider_reference'] ?? null;
                                        @endphp
                                        @if($providerRef)
                                            <div class="text-xs text-gray-500">{{ Str::limit($providerRef, 15) }}</div>
                                        @endif
                                    </div>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-300">
                                    {{ $transaction->created_at->format('d/m/Y H:i') }}
                                </td>
                                <td class="px-6 py-4">
                                    <div class="text-sm">
                                        <div class="font-medium text-white">{{ $transaction->user?->name ?? 'N/A' }}</div>
                                        <div class="text-xs text-gray-500">{{ $transaction->user?->email }}</div>
                                    </div>
                                </td>
                                <td class="px-6 py-4">
                                    <span class="px-3 py-1 text-xs font-semibold rounded-full
                                        @if($transaction->type == 'credit') bg-green-500/20 text-green-300 border border-green-500/50
                                        @elseif($transaction->type == 'debit') bg-red-500/20 text-red-300 border border-red-500/50
                                        @elseif($transaction->type == 'refund') bg-blue-500/20 text-blue-300 border border-blue-500/50
                                        @else bg-yellow-500/20 text-yellow-300 border border-yellow-500/50
                                        @endif">
                                        <i class="{{ $transaction->type_icon }} mr-1"></i>
                                        {{ $transaction->type_label }}
                                    </span>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="text-sm">
                                        <div class="font-medium text-white">
                                            @if($transaction->isCredit())
                                                <span class="text-green-400">+{{ number_format($transaction->amount, 0, ',', ' ') }}</span>
                                            @else
                                                <span class="text-red-400">-{{ number_format(abs($transaction->amount), 0, ',', ' ') }}</span>
                                            @endif
                                            FCFA
                                        </div>
                                        <div class="text-xs text-gray-500">Solde: {{ number_format($transaction->balance_after, 0, ',', ' ') }}</div>
                                    </div>
                                </td>
                                <td class="px-6 py-4">
                                    @if($transaction->provider)
                                        <span class="px-3 py-1 text-xs font-semibold rounded-full
                                            @if($transaction->provider == 'paypal') bg-blue-500/20 text-blue-300 border border-blue-500/50
                                            @else bg-orange-500/20 text-orange-300 border border-orange-500/50
                                            @endif">
                                            @if($transaction->provider == 'paypal')
                                                <i class="fab fa-paypal mr-1"></i> PayPal
                                            @else
                                                <i class="fas fa-mobile-alt mr-1"></i> KPay
                                            @endif
                                        </span>
                                    @else
                                        <span class="text-xs text-gray-500">-</span>
                                    @endif
                                </td>
                                <td class="px-6 py-4">
                                    @if($transaction->status == 'completed')
                                        <span class="px-3 py-1 text-xs font-semibold rounded-full bg-green-500/20 text-green-300 border border-green-500/50">
                                            <i class="fas fa-check-circle mr-1"></i> Complété
                                        </span>
                                    @elseif($transaction->status == 'pending')
                                        <span class="px-3 py-1 text-xs font-semibold rounded-full bg-yellow-500/20 text-yellow-300 border border-yellow-500/50">
                                            <i class="fas fa-clock mr-1"></i> En attente
                                        </span>
                                    @else
                                        <span class="px-3 py-1 text-xs font-semibold rounded-full bg-red-500/20 text-red-300 border border-red-500/50">
                                            <i class="fas fa-times-circle mr-1"></i> Échoué
                                        </span>
                                    @endif
                                </td>
                                <td class="px-6 py-4 text-right">
                                    <a href="{{ route('admin.transactions.show', $transaction) }}"
                                       class="px-3 py-1.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors text-xs"
                                       title="Voir">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <div class="px-6 py-4 border-t border-dark-200">
                {{ $transactions->links() }}
            </div>
        @else
            <div class="text-center py-12 text-gray-400">
                <i class="fas fa-receipt text-6xl text-gray-600 mb-4"></i>
                <p class="text-lg">Aucune transaction trouvée</p>
            </div>
        @endif
    </div>
</div>

@push('scripts')
<script>
// Revenue Chart
const revenueCtx = document.getElementById('revenueChart').getContext('2d');
const revenueChart = new Chart(revenueCtx, {
    type: 'line',
    data: {
        labels: @json(array_column($chartData, 'label')),
        datasets: [{
            label: 'Revenu (CFA)',
            data: @json(array_column($chartData, 'revenue')),
            borderColor: '#f97316',
            backgroundColor: 'rgba(249, 115, 22, 0.1)',
            borderWidth: 2,
            fill: true,
            tension: 0.4
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        animation: {
            duration: 0
        },
        plugins: {
            legend: {
                labels: {
                    color: '#fff'
                }
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                ticks: {
                    color: '#9ca3af',
                    callback: function(value) {
                        return new Intl.NumberFormat('fr-FR').format(value) + ' CFA';
                    }
                },
                grid: {
                    color: 'rgba(255, 255, 255, 0.05)'
                }
            },
            x: {
                ticks: {
                    color: '#9ca3af'
                },
                grid: {
                    color: 'rgba(255, 255, 255, 0.05)'
                }
            }
        }
    }
});

// Payment Methods Chart
const paymentCtx = document.getElementById('paymentMethodsChart').getContext('2d');
const paymentChart = new Chart(paymentCtx, {
    type: 'doughnut',
    data: {
        labels: ['PayPal (Visa, MasterCard)', 'FedaPay'],
        datasets: [{
            data: @json(array_values($paymentMethodChartData)),
            backgroundColor: [
                'rgba(59, 130, 246, 0.8)',
                'rgba(34, 197, 94, 0.8)'
            ],
            borderColor: [
                'rgba(59, 130, 246, 1)',
                'rgba(34, 197, 94, 1)'
            ],
            borderWidth: 2
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        animation: {
            duration: 0
        },
        plugins: {
            legend: {
                position: 'bottom',
                labels: {
                    color: '#fff',
                    padding: 15
                }
            },
            tooltip: {
                callbacks: {
                    label: function(context) {
                        return context.label + ': ' + new Intl.NumberFormat('fr-FR').format(context.parsed) + ' CFA';
                    }
                }
            }
        }
    }
});
</script>
@endpush
@endsection
