@extends('admin.layouts.app')

@section('content')
<div class="p-6 space-y-6">
    <!-- En-tête -->
    <div class="flex justify-between items-center">
        <div>
            <h1 class="text-3xl font-bold text-gray-100">Commissions d'Affiliation</h1>
            <p class="text-gray-400 mt-1">Gérez les commissions générées par le programme de parrainage</p>
        </div>
    </div>

    <!-- Statistiques -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-6 gap-6">
        <div class="bg-gradient-to-br from-blue-600 to-blue-700 rounded-lg p-6 text-white">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-blue-100 text-sm font-medium">Total</p>
                    <p class="text-3xl font-bold mt-2">{{ $stats['total'] }}</p>
                </div>
                <div class="bg-blue-500 bg-opacity-30 p-3 rounded-lg">
                    <i class="fas fa-list text-2xl"></i>
                </div>
            </div>
        </div>

        <div class="bg-gradient-to-br from-yellow-600 to-yellow-700 rounded-lg p-6 text-white">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-yellow-100 text-sm font-medium">En attente</p>
                    <p class="text-3xl font-bold mt-2">{{ $stats['pending'] }}</p>
                </div>
                <div class="bg-yellow-500 bg-opacity-30 p-3 rounded-lg">
                    <i class="fas fa-clock text-2xl"></i>
                </div>
            </div>
        </div>

        <div class="bg-gradient-to-br from-blue-500 to-blue-600 rounded-lg p-6 text-white">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-blue-100 text-sm font-medium">Approuvées</p>
                    <p class="text-3xl font-bold mt-2">{{ $stats['approved'] }}</p>
                </div>
                <div class="bg-blue-400 bg-opacity-30 p-3 rounded-lg">
                    <i class="fas fa-check text-2xl"></i>
                </div>
            </div>
        </div>

        <div class="bg-gradient-to-br from-green-600 to-green-700 rounded-lg p-6 text-white">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-green-100 text-sm font-medium">Payées</p>
                    <p class="text-3xl font-bold mt-2">{{ $stats['paid'] }}</p>
                </div>
                <div class="bg-green-500 bg-opacity-30 p-3 rounded-lg">
                    <i class="fas fa-money-bill-wave text-2xl"></i>
                </div>
            </div>
        </div>

        <div class="bg-gradient-to-br from-red-600 to-red-700 rounded-lg p-6 text-white">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-red-100 text-sm font-medium">Rejetées</p>
                    <p class="text-3xl font-bold mt-2">{{ $stats['rejected'] }}</p>
                </div>
                <div class="bg-red-500 bg-opacity-30 p-3 rounded-lg">
                    <i class="fas fa-times text-2xl"></i>
                </div>
            </div>
        </div>

        <div class="bg-gradient-to-br from-primary-600 to-primary-700 rounded-lg p-6 text-white">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-primary-100 text-sm font-medium">Montant Total</p>
                    <p class="text-2xl font-bold mt-2">{{ number_format($stats['total_amount'], 0, ',', ' ') }}</p>
                    <p class="text-xs text-primary-200">XOF</p>
                </div>
                <div class="bg-primary-500 bg-opacity-30 p-3 rounded-lg">
                    <i class="fas fa-coins text-2xl"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Filtres -->
    <div class="bg-dark-100 border border-dark-200 rounded-lg p-4">
        <form method="GET" class="flex flex-wrap gap-4 items-end">
            <div class="flex-1 min-w-[200px]">
                <label for="search" class="block text-sm font-medium text-gray-300 mb-2">Recherche</label>
                <input type="text" id="search" name="search" value="{{ request('search') }}"
                    placeholder="Nom, email..."
                    class="w-full px-4 py-2 bg-dark-50 border border-dark-200 rounded-lg text-gray-100 focus:ring-2 focus:ring-primary-500">
            </div>

            <div class="flex-1 min-w-[150px]">
                <label for="status" class="block text-sm font-medium text-gray-300 mb-2">Statut</label>
                <select id="status" name="status"
                    class="w-full px-4 py-2 bg-dark-50 border border-dark-200 rounded-lg text-gray-100 focus:ring-2 focus:ring-primary-500">
                    <option value="">Tous</option>
                    <option value="pending" {{ request('status') === 'pending' ? 'selected' : '' }}>En attente</option>
                    <option value="approved" {{ request('status') === 'approved' ? 'selected' : '' }}>Approuvées</option>
                    <option value="paid" {{ request('status') === 'paid' ? 'selected' : '' }}>Payées</option>
                    <option value="rejected" {{ request('status') === 'rejected' ? 'selected' : '' }}>Rejetées</option>
                </select>
            </div>

            <div class="flex-1 min-w-[150px]">
                <label for="level" class="block text-sm font-medium text-gray-300 mb-2">Niveau</label>
                <select id="level" name="level"
                    class="w-full px-4 py-2 bg-dark-50 border border-dark-200 rounded-lg text-gray-100 focus:ring-2 focus:ring-primary-500">
                    <option value="">Tous</option>
                    <option value="1" {{ request('level') == '1' ? 'selected' : '' }}>Niveau 1</option>
                    <option value="2" {{ request('level') == '2' ? 'selected' : '' }}>Niveau 2</option>
                    <option value="3" {{ request('level') == '3' ? 'selected' : '' }}>Niveau 3</option>
                </select>
            </div>

            <button type="submit" class="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition-colors">
                <i class="fas fa-search mr-2"></i>
                Filtrer
            </button>

            @if(request()->hasAny(['search', 'status', 'level']))
                <a href="{{ route('admin.affiliate.commissions') }}"
                    class="px-6 py-2 bg-dark-50 border border-dark-200 hover:bg-dark-100 text-gray-100 rounded-lg transition-colors">
                    Réinitialiser
                </a>
            @endif
        </form>
    </div>

    <!-- Liste des commissions -->
    <div class="bg-dark-100 border border-dark-200 rounded-lg overflow-hidden">
        <table class="w-full">
            <thead class="bg-dark-50 border-b border-dark-200">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase">Affilié</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase">Filleul</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase">Niveau</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase">Montant</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase">Pourcentage</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase">Statut</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase">Date</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-dark-200">
                @forelse($commissions as $commission)
                    <tr class="hover:bg-dark-50 transition-colors">
                        <td class="px-6 py-4">
                            <div class="flex items-center">
                                <div class="w-8 h-8 rounded-full bg-gradient-to-br from-primary-500 to-primary-600 flex items-center justify-center text-white text-xs font-bold mr-3">
                                    {{ strtoupper(substr($commission->affiliate->first_name, 0, 1)) }}
                                </div>
                                <div>
                                    <p class="text-sm font-medium text-gray-100">{{ $commission->affiliate->name }}</p>
                                    <p class="text-xs text-gray-400">{{ $commission->affiliate->email }}</p>
                                </div>
                            </div>
                        </td>
                        <td class="px-6 py-4">
                            <div>
                                <p class="text-sm text-gray-200">{{ $commission->referredUser->name }}</p>
                                <p class="text-xs text-gray-400">{{ $commission->referredUser->email }}</p>
                            </div>
                        </td>
                        <td class="px-6 py-4">
                            <span class="bg-blue-600 text-white px-2 py-1 rounded text-xs font-bold">
                                {{ $commission->level_label }}
                            </span>
                        </td>
                        <td class="px-6 py-4">
                            <span class="text-green-400 font-bold">{{ number_format($commission->amount, 0, ',', ' ') }} XOF</span>
                        </td>
                        <td class="px-6 py-4">
                            <span class="text-gray-300">{{ $commission->percentage }}%</span>
                        </td>
                        <td class="px-6 py-4">
                            <span class="bg-{{ $commission->status_color }}-600 text-white px-2 py-1 rounded text-xs font-bold">
                                {{ $commission->status_label }}
                            </span>
                        </td>
                        <td class="px-6 py-4">
                            <span class="text-sm text-gray-300">{{ $commission->created_at->format('d/m/Y H:i') }}</span>
                        </td>
                        <td class="px-6 py-4">
                            <div class="flex items-center gap-2">
                                @if($commission->status === 'pending')
                                    <form action="{{ route('admin.affiliate.approve-commission', $commission) }}" method="POST" class="inline">
                                        @csrf
                                        <button type="submit" class="text-blue-500 hover:text-blue-400" title="Approuver">
                                            <i class="fas fa-check"></i>
                                        </button>
                                    </form>
                                    <form action="{{ route('admin.affiliate.reject-commission', $commission) }}" method="POST" class="inline"
                                        data-confirm="Rejeter cette commission ?">
                                        @csrf
                                        <button type="submit" class="text-red-500 hover:text-red-400" title="Rejeter">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    </form>
                                @endif

                                @if($commission->status === 'approved')
                                    <form action="{{ route('admin.affiliate.pay-commission', $commission) }}" method="POST" class="inline">
                                        @csrf
                                        <button type="submit" class="text-green-500 hover:text-green-400" title="Marquer comme payée">
                                            <i class="fas fa-money-bill-wave"></i>
                                        </button>
                                    </form>
                                @endif

                                @if($commission->status === 'paid')
                                    <span class="text-gray-500" title="Payée le {{ $commission->paid_at?->format('d/m/Y') ?? 'N/A' }}">
                                        <i class="fas fa-check-circle"></i>
                                    </span>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="px-6 py-12 text-center">
                            <i class="fas fa-inbox text-6xl text-gray-600 mb-4"></i>
                            <p class="text-gray-400 text-lg">Aucune commission trouvée</p>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    @if($commissions->hasPages())
        <div class="flex justify-center">
            {{ $commissions->links() }}
        </div>
    @endif
</div>
@endsection
