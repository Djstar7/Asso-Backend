@extends('admin.layouts.app')

@section('title', 'Dashboard')
@section('header', 'Dashboard')

@section('content')
@adminCan('admin.shipments.index')
<div class="mb-6 p-5 bg-dark-100 border border-dark-200 rounded-xl flex flex-wrap items-center justify-between gap-4">
    <div><h2 class="text-lg font-semibold text-white">Statuts des commandes et expéditions</h2>
        <p class="text-sm text-gray-400">Collecte Solex, remise au transporteur, transit, dédouanement et livraison.</p></div>
    <a href="{{ route('admin.shipments.index') }}" class="px-4 py-2 bg-primary-500 rounded-lg text-white text-sm">Retrouver les commandes et leur suivi</a>
    <a href="{{ route('admin.shipments.index', ['scope' => 'export', 'status' => 'all']) }}" class="text-primary-400 text-sm">Commandes internationales · Solex → DHL / FedEx</a>
</div>
@endadminCan
<!-- Pending Shops Notifications -->
@if($pendingShops->count() > 0)
<div class="mb-8">
    <h2 class="text-xl font-bold text-white mb-4 flex items-center">
        <i class="fas fa-bell text-primary-500 mr-2"></i>
        Notifications
    </h2>
    <div class="space-y-4">
        @foreach($pendingShops as $shop)
        <div class="bg-gradient-to-r from-yellow-500/10 to-orange-500/10 border-l-4 border-yellow-500 rounded-lg p-4 hover:shadow-lg transition-all">
            <div class="flex items-start gap-4">
                <div class="flex-shrink-0">
                    @if($shop->logo_url)
                        <img src="{{ $shop->logo_url }}"
                             alt="Logo {{ $shop->name }}"
                             class="w-16 h-16 object-cover rounded-lg border-2 border-yellow-500 shadow-md"
                             onerror="this.onerror=null; this.parentElement.innerHTML='<div class=\'w-16 h-16 bg-yellow-500/20 rounded-lg flex items-center justify-center border-2 border-yellow-500\'><i class=\'fas fa-store text-yellow-500 text-2xl\'></i></div>';">
                    @else
                        <div class="w-16 h-16 bg-yellow-500/20 rounded-lg flex items-center justify-center border-2 border-yellow-500">
                            <i class="fas fa-store text-yellow-500 text-2xl"></i>
                        </div>
                    @endif
                </div>
                <div class="flex-1 min-w-0">
                    <h3 class="text-lg font-bold text-white mb-1">Boutique en attente de vérification</h3>
                    <p class="text-gray-300 text-sm mb-2">
                        <i class="fas fa-store mr-1 text-yellow-500"></i>
                        <strong>{{ $shop->name }}</strong> - Cette boutique a été créée par
                        <strong>{{ $shop->user->name }}</strong> et attend votre validation.
                    </p>
                    @if($shop->description)
                        <p class="text-gray-400 text-sm mb-3 line-clamp-2">{{ $shop->description }}</p>
                    @endif
                    <div class="flex gap-2">
                        <form action="{{ route('admin.shops.verify', $shop) }}" method="POST" class="inline">
                            @csrf
                            <button type="submit"
                                    class="px-4 py-2 bg-gradient-to-r from-green-500 to-green-600 text-white rounded-lg hover:shadow-lg transition-all text-sm font-semibold">
                                <i class="fas fa-check-circle mr-1"></i>
                                Vérifier et Activer
                            </button>
                        </form>
                        <button type="button"
                                onclick="showRejectModal({{ $shop->id }}, '{{ $shop->name }}')"
                                class="px-4 py-2 bg-gradient-to-r from-red-500 to-red-600 text-white rounded-lg hover:shadow-lg transition-all text-sm font-semibold">
                            <i class="fas fa-times-circle mr-1"></i>
                            Rejeter
                        </button>
                        <a href="{{ route('admin.shops.show', $shop) }}"
                           class="px-4 py-2 bg-dark-200 border border-dark-300 text-white rounded-lg hover:bg-dark-300 transition-all text-sm font-semibold">
                            <i class="fas fa-eye mr-1"></i>
                            Voir Détails
                        </a>
                    </div>
                </div>
            </div>
        </div>
        @endforeach
    </div>
</div>
@endif

<!-- Stats Cards -->
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
    <!-- Livreurs Card -->
    <div class="bg-dark-100 rounded-xl shadow-lg border border-dark-200 p-6 hover:shadow-2xl hover:shadow-blue-500/10 transition-all duration-300 transform hover:-translate-y-1">
        <div class="flex items-center justify-between">
            <div class="flex-1">
                <p class="text-sm font-medium text-gray-400 mb-1">Livreurs</p>
                <p class="text-3xl font-bold text-white">{{ number_format($stats['users']['livreurs'] ?? 0) }}</p>
                <p class="text-sm text-green-400 mt-2 flex items-center">
                    <i class="fas fa-arrow-up mr-1"></i>
                    +{{ $stats['users']['livreurs_today'] ?? 0 }} aujourd'hui
                </p>
            </div>
            <div class="w-14 h-14 bg-gradient-to-br from-blue-500 to-blue-600 rounded-full flex items-center justify-center shadow-lg shadow-blue-500/30">
                <i class="fas fa-shipping-fast text-2xl text-white"></i>
            </div>
        </div>
    </div>

    <!-- Vendeurs Card -->
    <div class="bg-dark-100 rounded-xl shadow-lg border border-dark-200 p-6 hover:shadow-2xl hover:shadow-primary-500/10 transition-all duration-300 transform hover:-translate-y-1">
        <div class="flex items-center justify-between">
            <div class="flex-1">
                <p class="text-sm font-medium text-gray-400 mb-1">Vendeurs</p>
                <p class="text-3xl font-bold text-white">{{ number_format($stats['users']['vendeurs'] ?? 0) }}</p>
                <p class="text-sm text-green-400 mt-2 flex items-center">
                    <i class="fas fa-arrow-up mr-1"></i>
                    +{{ $stats['users']['vendeurs_today'] ?? 0 }} aujourd'hui
                </p>
            </div>
            <div class="w-14 h-14 bg-gradient-to-br from-primary-500 to-primary-600 rounded-full flex items-center justify-center shadow-lg shadow-primary-500/30">
                <i class="fas fa-store text-2xl text-white"></i>
            </div>
        </div>
    </div>

    <!-- Clients Card -->
    <div class="bg-dark-100 rounded-xl shadow-lg border border-dark-200 p-6 hover:shadow-2xl hover:shadow-purple-500/10 transition-all duration-300 transform hover:-translate-y-1">
        <div class="flex items-center justify-between">
            <div class="flex-1">
                <p class="text-sm font-medium text-gray-400 mb-1">Clients</p>
                <p class="text-3xl font-bold text-white">{{ number_format($stats['users']['clients'] ?? 0) }}</p>
                <p class="text-sm text-green-400 mt-2 flex items-center">
                    <i class="fas fa-arrow-up mr-1"></i>
                    +{{ $stats['users']['clients_today'] ?? 0 }} aujourd'hui
                </p>
            </div>
            <div class="w-14 h-14 bg-gradient-to-br from-purple-500 to-purple-600 rounded-full flex items-center justify-center shadow-lg shadow-purple-500/30">
                <i class="fas fa-users text-2xl text-white"></i>
            </div>
        </div>
    </div>

    <!-- Total Users Card -->
    <div class="bg-gradient-to-br from-primary-500 to-primary-600 rounded-xl shadow-lg shadow-primary-500/30 p-6 hover:shadow-2xl hover:shadow-primary-500/40 transition-all duration-300 transform hover:-translate-y-1">
        <div class="flex items-center justify-between">
            <div class="flex-1">
                <p class="text-sm font-medium text-white/90 mb-1">Total Utilisateurs</p>
                <p class="text-3xl font-bold text-white">{{ number_format($stats['users']['total'] ?? 0) }}</p>
                <p class="text-sm text-white/90 mt-2 flex items-center">
                    <i class="fas fa-users mr-1"></i>
                    Tous les rôles
                </p>
            </div>
            <div class="w-14 h-14 bg-white/20 backdrop-blur-sm rounded-full flex items-center justify-center shadow-lg">
                <i class="fas fa-user-friends text-2xl text-white"></i>
            </div>
        </div>
    </div>
</div>

<!-- Secondary Stats -->
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
    <!-- Products Card -->
    <div class="bg-dark-100 rounded-xl shadow-lg border border-dark-200 p-6 hover:shadow-xl hover:shadow-green-500/10 transition-all duration-300">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm font-medium text-gray-400">Produits</p>
                <p class="text-2xl font-bold text-white mt-1">{{ number_format($stats['products']['total'] ?? 0) }}</p>
            </div>
            <div class="w-12 h-12 bg-green-500/20 rounded-lg flex items-center justify-center border border-green-500/30">
                <i class="fas fa-box text-xl text-green-400"></i>
            </div>
        </div>
    </div>

    <!-- Transactions Card -->
    <div class="bg-dark-100 rounded-xl shadow-lg border border-dark-200 p-6 hover:shadow-xl hover:shadow-blue-500/10 transition-all duration-300">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm font-medium text-gray-400">Transactions</p>
                <p class="text-2xl font-bold text-white mt-1">{{ number_format($stats['transactions']['total'] ?? 0) }}</p>
            </div>
            <div class="w-12 h-12 bg-blue-500/20 rounded-lg flex items-center justify-center border border-blue-500/30">
                <i class="fas fa-exchange-alt text-xl text-blue-400"></i>
            </div>
        </div>
    </div>

    <!-- Exchanges Card -->
    <div class="bg-dark-100 rounded-xl shadow-lg border border-dark-200 p-6 hover:shadow-xl hover:shadow-primary-500/10 transition-all duration-300">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm font-medium text-gray-400">Échanges</p>
                <p class="text-2xl font-bold text-white mt-1">{{ number_format($stats['exchanges']['total'] ?? 0) }}</p>
            </div>
            <div class="w-12 h-12 bg-primary-500/20 rounded-lg flex items-center justify-center border border-primary-500/30">
                <i class="fas fa-sync-alt text-xl text-primary-400"></i>
            </div>
        </div>
    </div>

    <!-- Categories Card -->
    <div class="bg-dark-100 rounded-xl shadow-lg border border-dark-200 p-6 hover:shadow-xl hover:shadow-purple-500/10 transition-all duration-300">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm font-medium text-gray-400">Catégories</p>
                <p class="text-2xl font-bold text-white mt-1">
                    {{ number_format($stats['categories']['total'] ?? 0) }}
                    <span class="text-sm text-gray-500">/ {{ number_format($stats['categories']['sub_total'] ?? 0) }}</span>
                </p>
                <p class="text-xs text-gray-500 mt-1">Catégories / Sous-catégories</p>
            </div>
            <div class="w-12 h-12 bg-purple-500/20 rounded-lg flex items-center justify-center border border-purple-500/30">
                <i class="fas fa-folder text-xl text-purple-400"></i>
            </div>
        </div>
    </div>
</div>

<!-- Charts Row -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
    <!-- Users Chart -->
    <div class="bg-dark-100 rounded-xl shadow-lg border border-dark-200 p-6">
        <div class="flex items-center justify-between mb-6">
            <h3 class="text-lg font-semibold text-white">Inscriptions (30 derniers jours)</h3>
            <div class="flex items-center space-x-3">
                <div class="flex items-center">
                    <span class="w-3 h-3 bg-blue-500 rounded-full mr-2"></span>
                    <span class="text-xs text-gray-400">Livreurs</span>
                </div>
                <div class="flex items-center">
                    <span class="w-3 h-3 bg-primary-500 rounded-full mr-2"></span>
                    <span class="text-xs text-gray-400">Vendeurs</span>
                </div>
                <div class="flex items-center">
                    <span class="w-3 h-3 bg-purple-500 rounded-full mr-2"></span>
                    <span class="text-xs text-gray-400">Clients</span>
                </div>
            </div>
        </div>
        <div class="relative h-64">
            <canvas id="usersChart"></canvas>
        </div>
    </div>

    <!-- Transactions Chart -->
    <div class="bg-dark-100 rounded-xl shadow-lg border border-dark-200 p-6">
        <div class="flex items-center justify-between mb-6">
            <h3 class="text-lg font-semibold text-white">Transactions & Échanges (30 jours)</h3>
            <div class="flex items-center space-x-3">
                <div class="flex items-center">
                    <span class="w-3 h-3 bg-green-500 rounded-full mr-2"></span>
                    <span class="text-xs text-gray-400">Transactions</span>
                </div>
                <div class="flex items-center">
                    <span class="w-3 h-3 bg-primary-500 rounded-full mr-2"></span>
                    <span class="text-xs text-gray-400">Échanges</span>
                </div>
            </div>
        </div>
        <div class="relative h-64">
            <canvas id="transactionsChart"></canvas>
        </div>
    </div>
</div>

<!-- Products Stats -->
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
    <!-- Top Categories -->
    <div class="bg-dark-100 rounded-xl shadow-lg border border-dark-200 p-6">
        <h3 class="text-lg font-semibold text-white mb-4 flex items-center">
            <i class="fas fa-chart-bar text-primary-500 mr-2"></i>
            Top Catégories
        </h3>
        <div class="space-y-3">
            @forelse($topCategories ?? [] as $category)
                <div class="flex items-center justify-between p-3 bg-dark-50 rounded-lg hover:bg-dark-200 transition-colors border border-dark-300">
                    <div class="flex items-center">
                        <div class="w-10 h-10 bg-gradient-to-br from-primary-500 to-primary-600 rounded-lg flex items-center justify-center text-white font-semibold mr-3 shadow-lg shadow-primary-500/30">
                            {{ strtoupper(substr($category->name ?? 'C', 0, 1)) }}
                        </div>
                        <div>
                            <p class="font-medium text-white">{{ $category->name ?? 'Catégorie' }}</p>
                            <p class="text-xs text-gray-400">{{ $category->products_count ?? 0 }} produits</p>
                        </div>
                    </div>
                    <span class="px-3 py-1 bg-primary-500/20 text-primary-400 rounded-full text-xs font-medium border border-primary-500/30">
                        {{ $category->products_count ?? 0 }}
                    </span>
                </div>
            @empty
                <div class="text-center py-8 text-gray-500">
                    <i class="fas fa-folder-open text-4xl text-gray-600 mb-2"></i>
                    <p>Aucune catégorie</p>
                </div>
            @endforelse
        </div>
    </div>

    <!-- Recent Products -->
    <div class="bg-dark-100 rounded-xl shadow-lg border border-dark-200 p-6">
        <h3 class="text-lg font-semibold text-white mb-4 flex items-center">
            <i class="fas fa-box-open text-blue-500 mr-2"></i>
            Produits Récents
        </h3>
        <div class="space-y-3">
            @forelse($recentProducts ?? [] as $product)
                <div class="flex items-center justify-between p-3 bg-dark-50 rounded-lg hover:bg-dark-200 transition-colors border border-dark-300">
                    <div class="flex items-center">
                        <div class="w-10 h-10 bg-gradient-to-br from-blue-500 to-blue-600 rounded-lg flex items-center justify-center text-white mr-3 shadow-lg shadow-blue-500/30">
                            <i class="fas fa-box"></i>
                        </div>
                        <div>
                            <p class="font-medium text-white text-sm">{{ Str::limit($product->name ?? 'Produit', 20) }}</p>
                            <p class="text-xs text-gray-400">{{ $product->created_at?->diffForHumans() }}</p>
                        </div>
                    </div>
                    <span class="text-xs font-semibold text-green-400">{{ number_format($product->price ?? 0) }} FCFA</span>
                </div>
            @empty
                <div class="text-center py-8 text-gray-500">
                    <i class="fas fa-box text-4xl text-gray-600 mb-2"></i>
                    <p>Aucun produit</p>
                </div>
            @endforelse
        </div>
    </div>

    <!-- Recent Transactions -->
    <div class="bg-dark-100 rounded-xl shadow-lg border border-dark-200 p-6">
        <h3 class="text-lg font-semibold text-white mb-4 flex items-center">
            <i class="fas fa-receipt text-green-500 mr-2"></i>
            Transactions Récentes
        </h3>
        <div class="space-y-3">
            @forelse($recentTransactions ?? [] as $transaction)
                <div class="flex items-center justify-between p-3 bg-dark-50 rounded-lg hover:bg-dark-200 transition-colors border border-dark-300">
                    <div class="flex items-center">
                        <div class="w-10 h-10 bg-gradient-to-br from-green-500 to-green-600 rounded-lg flex items-center justify-center text-white mr-3 shadow-lg shadow-green-500/30">
                            <i class="fas fa-exchange-alt"></i>
                        </div>
                        <div>
                            <p class="font-medium text-white text-sm">{{ $transaction->reference ?? 'TRX-XXX' }}</p>
                            <p class="text-xs text-gray-400">{{ $transaction->created_at?->diffForHumans() }}</p>
                        </div>
                    </div>
                    <span class="text-xs font-semibold text-green-400">{{ number_format($transaction->amount ?? 0) }} FCFA</span>
                </div>
            @empty
                <div class="text-center py-8 text-gray-500">
                    <i class="fas fa-receipt text-4xl text-gray-600 mb-2"></i>
                    <p>Aucune transaction</p>
                </div>
            @endforelse
        </div>
    </div>
</div>

<!-- Reject Modal -->
<div id="rejectModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center" onclick="if(event.target === this) hideRejectModal()">
    <div class="bg-dark-100 rounded-xl shadow-2xl max-w-md w-full mx-4 border border-dark-200">
        <div class="p-6">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-xl font-bold text-white flex items-center">
                    <i class="fas fa-times-circle text-red-500 mr-2"></i>
                    Rejeter la boutique
                </h3>
                <button onclick="hideRejectModal()" class="text-gray-400 hover:text-white">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <p class="text-gray-400 mb-4">
                Vous êtes sur le point de rejeter la boutique <strong id="rejectShopName" class="text-white"></strong>.
            </p>
            <form id="rejectForm" method="POST" action="">
                @csrf
                <div class="mb-4">
                    <label class="block text-sm font-medium text-white mb-2">
                        Raison du rejet <span class="text-red-500">*</span>
                    </label>
                    <textarea name="reason"
                              rows="4"
                              required
                              maxlength="500"
                              class="w-full px-4 py-2 bg-dark-50 border border-dark-300 text-white placeholder-gray-400 rounded-lg focus:ring-2 focus:ring-red-500 focus:border-red-500"
                              placeholder="Expliquez pourquoi cette boutique est rejetée..."></textarea>
                    <p class="text-xs text-gray-400 mt-1">Maximum 500 caractères</p>
                </div>
                <div class="flex gap-3">
                    <button type="button"
                            onclick="hideRejectModal()"
                            class="flex-1 px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 transition-all">
                        Annuler
                    </button>
                    <button type="submit"
                            class="flex-1 px-4 py-2 bg-gradient-to-r from-red-500 to-red-600 text-white rounded-lg hover:shadow-lg transition-all font-semibold">
                        <i class="fas fa-times-circle mr-1"></i>
                        Rejeter
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
function showRejectModal(shopId, shopName) {
    document.getElementById('rejectShopName').textContent = shopName;
    document.getElementById('rejectForm').action = '/admin/shops/' + shopId + '/reject';
    document.getElementById('rejectModal').classList.remove('hidden');
}

function hideRejectModal() {
    document.getElementById('rejectModal').classList.add('hidden');
    document.getElementById('rejectForm').reset();
}

document.addEventListener('DOMContentLoaded', function() {
    // Configure Chart.js for dark mode
    Chart.defaults.color = '#9ca3af';
    Chart.defaults.borderColor = 'rgba(75, 85, 99, 0.3)';

    // Users Chart
    const usersCtx = document.getElementById('usersChart').getContext('2d');
    new Chart(usersCtx, {
        type: 'line',
        data: {
            labels: {!! json_encode($chartData['users']['labels'] ?? array_map(fn($i) => date('d M', strtotime("-$i days")), range(29, 0))) !!},
            datasets: [
                {
                    label: 'Livreurs',
                    data: {!! json_encode($chartData['users']['livreurs'] ?? array_fill(0, 30, rand(0, 10))) !!},
                    borderColor: '#3b82f6',
                    backgroundColor: 'rgba(59, 130, 246, 0.1)',
                    fill: true,
                    tension: 0.4,
                    borderWidth: 2,
                    pointRadius: 0,
                    pointHoverRadius: 6,
                },
                {
                    label: 'Vendeurs',
                    data: {!! json_encode($chartData['users']['vendeurs'] ?? array_fill(0, 30, rand(0, 10))) !!},
                    borderColor: '#f97316',
                    backgroundColor: 'rgba(249, 115, 22, 0.1)',
                    fill: true,
                    tension: 0.4,
                    borderWidth: 2,
                    pointRadius: 0,
                    pointHoverRadius: 6,
                },
                {
                    label: 'Clients',
                    data: {!! json_encode($chartData['users']['clients'] ?? array_fill(0, 30, rand(0, 10))) !!},
                    borderColor: '#a855f7',
                    backgroundColor: 'rgba(168, 85, 247, 0.1)',
                    fill: true,
                    tension: 0.4,
                    borderWidth: 2,
                    pointRadius: 0,
                    pointHoverRadius: 6,
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: 'rgba(0, 0, 0, 0.9)',
                    padding: 12,
                    borderRadius: 8,
                    titleColor: '#fff',
                    bodyColor: '#fff',
                }
            },
            scales: {
                x: {
                    grid: { display: false },
                    ticks: { maxTicksLimit: 7, font: { size: 11 }, color: '#9ca3af' }
                },
                y: {
                    beginAtZero: true,
                    grid: { color: 'rgba(75, 85, 99, 0.2)' },
                    ticks: { font: { size: 11 }, color: '#9ca3af' }
                }
            },
            interaction: {
                intersect: false,
                mode: 'index'
            }
        }
    });

    // Transactions Chart
    const transactionsCtx = document.getElementById('transactionsChart').getContext('2d');
    new Chart(transactionsCtx, {
        type: 'bar',
        data: {
            labels: {!! json_encode($chartData['transactions']['labels'] ?? array_map(fn($i) => date('d M', strtotime("-$i days")), range(29, 0))) !!},
            datasets: [
                {
                    label: 'Transactions',
                    data: {!! json_encode($chartData['transactions']['data'] ?? array_fill(0, 30, rand(0, 50))) !!},
                    backgroundColor: 'rgba(34, 197, 94, 0.8)',
                    borderColor: '#22c55e',
                    borderWidth: 1,
                    borderRadius: 6,
                    barThickness: 12
                },
                {
                    label: 'Échanges',
                    data: {!! json_encode($chartData['exchanges']['data'] ?? array_fill(0, 30, rand(0, 30))) !!},
                    backgroundColor: 'rgba(249, 115, 22, 0.8)',
                    borderColor: '#f97316',
                    borderWidth: 1,
                    borderRadius: 6,
                    barThickness: 12
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: 'rgba(0, 0, 0, 0.9)',
                    padding: 12,
                    borderRadius: 8,
                    titleColor: '#fff',
                    bodyColor: '#fff',
                }
            },
            scales: {
                x: {
                    grid: { display: false },
                    ticks: { maxTicksLimit: 7, font: { size: 11 }, color: '#9ca3af' }
                },
                y: {
                    beginAtZero: true,
                    grid: { color: 'rgba(75, 85, 99, 0.2)' },
                    ticks: { font: { size: 11 }, color: '#9ca3af' }
                }
            }
        }
    });
});
</script>
@endpush
