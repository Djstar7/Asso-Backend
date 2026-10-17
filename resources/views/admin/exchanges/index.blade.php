@extends('admin.layouts.app')

@section('content')
<div class="p-6 space-y-6">
    <!-- En-tête -->
    <div class="flex justify-between items-center">
        <div>
            <h1 class="text-3xl font-bold text-gray-100">Surveillance des Échanges</h1>
            <p class="text-gray-400 mt-1">Suivi des conversations et contacts externes</p>
        </div>
    </div>

    <!-- Filtres de période -->
    <div class="bg-dark-100 border border-dark-200 rounded-lg p-4">
        <form method="GET" action="{{ route('admin.exchanges.index') }}" class="flex flex-wrap gap-4 items-end">
            <div class="flex-1 min-w-[200px]">
                <label for="start_date" class="block text-sm font-medium text-gray-300 mb-2">Date de début</label>
                <input type="date" id="start_date" name="start_date"
                    value="{{ $startDate }}"
                    class="w-full px-4 py-2 bg-dark-50 border border-dark-200 rounded-lg text-gray-100 focus:ring-2 focus:ring-blue-500">
            </div>
            <div class="flex-1 min-w-[200px]">
                <label for="end_date" class="block text-sm font-medium text-gray-300 mb-2">Date de fin</label>
                <input type="date" id="end_date" name="end_date"
                    value="{{ $endDate }}"
                    class="w-full px-4 py-2 bg-dark-50 border border-dark-200 rounded-lg text-gray-100 focus:ring-2 focus:ring-blue-500">
            </div>
            <button type="submit" class="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition-colors">
                Filtrer
            </button>
        </form>
    </div>

    <!-- Statistiques principales -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        <!-- Conversations -->
        <div class="bg-gradient-to-br from-blue-600 to-blue-700 rounded-lg p-6 text-white">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-blue-100 text-sm font-medium">Conversations</p>
                    <p class="text-3xl font-bold mt-2">{{ number_format($totalConversations) }}</p>
                </div>
                <div class="bg-blue-500 bg-opacity-30 p-3 rounded-lg">
                    <i class="fas fa-comments text-2xl"></i>
                </div>
            </div>
        </div>

        <!-- Messages -->
        <div class="bg-gradient-to-br from-purple-600 to-purple-700 rounded-lg p-6 text-white">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-purple-100 text-sm font-medium">Messages</p>
                    <p class="text-3xl font-bold mt-2">{{ number_format($totalMessages) }}</p>
                </div>
                <div class="bg-purple-500 bg-opacity-30 p-3 rounded-lg">
                    <i class="fas fa-envelope text-2xl"></i>
                </div>
            </div>
        </div>

        <!-- Messages non lus -->
        <div class="bg-gradient-to-br from-orange-600 to-orange-700 rounded-lg p-6 text-white">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-orange-100 text-sm font-medium">Messages Non Lus</p>
                    <p class="text-3xl font-bold mt-2">{{ number_format($unreadMessages) }}</p>
                </div>
                <div class="bg-orange-500 bg-opacity-30 p-3 rounded-lg">
                    <i class="fas fa-envelope-open text-2xl"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Graphique d'évolution -->
    <div class="bg-dark-100 border border-dark-200 rounded-lg p-6">
        <h3 class="text-xl font-semibold text-gray-100 mb-4">Évolution des Échanges (30 derniers jours)</h3>
        <div style="position: relative; height: 300px;">
            <canvas id="exchangesChart"></canvas>
        </div>
    </div>

    <!-- Conversations récentes -->
    <div class="bg-dark-100 border border-dark-200 rounded-lg p-6">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-xl font-semibold text-gray-100">Conversations Récentes</h3>
            <a href="{{ route('admin.exchanges.conversations') }}"
                class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition-colors">
                Voir tout
            </a>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead>
                    <tr class="border-b border-dark-200">
                        <th class="text-left py-3 px-4 text-gray-300 font-medium">Participants</th>
                        <th class="text-left py-3 px-4 text-gray-300 font-medium">Produit</th>
                        <th class="text-left py-3 px-4 text-gray-300 font-medium">Dernier Message</th>
                        <th class="text-left py-3 px-4 text-gray-300 font-medium">Date</th>
                        <th class="text-center py-3 px-4 text-gray-300 font-medium">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($recentConversations as $conversation)
                    <tr class="border-b border-dark-200 hover:bg-dark-50 transition-colors">
                        <td class="py-3 px-4">
                            <div class="flex items-center gap-2">
                                <span class="text-gray-100">{{ $conversation->user1->name }}</span>
                                <i class="fas fa-exchange-alt text-gray-400 text-xs"></i>
                                <span class="text-gray-100">{{ $conversation->user2->name }}</span>
                            </div>
                        </td>
                        <td class="py-3 px-4">
                            @if($conversation->product)
                                <span class="text-gray-300">{{ Str::limit($conversation->product->name, 30) }}</span>
                            @else
                                <span class="text-gray-500 italic">Produit supprimé</span>
                            @endif
                        </td>
                        <td class="py-3 px-4">
                            @if($conversation->latestMessage)
                                <span class="text-gray-300">{{ Str::limit($conversation->latestMessage->message, 40) }}</span>
                            @else
                                <span class="text-gray-500 italic">Aucun message</span>
                            @endif
                        </td>
                        <td class="py-3 px-4 text-gray-300">
                            {{ $conversation->last_message_at?->displayTz()->format('d/m/Y H:i') ?? 'N/A' }}
                        </td>
                        <td class="py-3 px-4 text-center">
                            <a href="{{ route('admin.exchanges.show', $conversation) }}"
                                class="inline-flex items-center px-3 py-1 bg-blue-600 hover:bg-blue-700 text-white text-sm rounded-lg transition-colors">
                                <i class="fas fa-eye mr-2"></i>
                                Voir
                            </a>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="5" class="py-8 text-center text-gray-400">
                            <i class="fas fa-inbox text-4xl mb-2"></i>
                            <p>Aucune conversation récente</p>
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <!-- Lien rapide -->
    <div class="max-w-xl">
        <a href="{{ route('admin.exchanges.conversations') }}"
            class="bg-dark-100 border border-dark-200 rounded-lg p-6 hover:bg-dark-50 transition-colors group block">
            <div class="flex items-center gap-4">
                <div class="bg-blue-600 p-4 rounded-lg group-hover:bg-blue-700 transition-colors">
                    <i class="fas fa-comments text-white text-2xl"></i>
                </div>
                <div>
                    <h4 class="text-lg font-semibold text-gray-100">Toutes les Conversations</h4>
                    <p class="text-gray-400">Voir et gérer toutes les conversations</p>
                </div>
            </div>
        </a>
    </div>
</div>

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    // Graphique d'évolution des échanges
    const exchangesCtx = document.getElementById('exchangesChart').getContext('2d');
    const dailyStats = @json($dailyStats);

    const dates = Object.keys(dailyStats);
    const conversationsData = dates.map(date => dailyStats[date].conversations);
    const messagesData = dates.map(date => dailyStats[date].messages);

    new Chart(exchangesCtx, {
        type: 'line',
        data: {
            labels: dates.map(date => new Date(date).toLocaleDateString('fr-FR', { day: '2-digit', month: '2-digit' })),
            datasets: [
                {
                    label: 'Conversations',
                    data: conversationsData,
                    borderColor: 'rgba(59, 130, 246, 1)',
                    backgroundColor: 'rgba(59, 130, 246, 0.1)',
                    tension: 0.4,
                    fill: true,
                },
                {
                    label: 'Messages',
                    data: messagesData,
                    borderColor: 'rgba(168, 85, 247, 1)',
                    backgroundColor: 'rgba(168, 85, 247, 0.1)',
                    tension: 0.4,
                    fill: true,
                }
            ]
        },
        options: {
            animation: { duration: 0 },
            maintainAspectRatio: false,
            responsive: true,
            plugins: {
                legend: {
                    labels: { color: '#e5e7eb' }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    grid: { color: 'rgba(255, 255, 255, 0.1)' },
                    ticks: { color: '#e5e7eb' }
                },
                x: {
                    grid: { color: 'rgba(255, 255, 255, 0.1)' },
                    ticks: {
                        color: '#e5e7eb',
                        maxRotation: 45,
                        minRotation: 45
                    }
                }
            }
        }
    });
</script>
@endpush
@endsection
