@extends('admin.layouts.app')

@section('content')
<div class="p-6">
    <!-- Header -->
    <div class="flex justify-between items-center mb-6">
        <div>
            <h1 class="text-2xl font-bold text-white">Entreprises de Livraison</h1>
            <p class="text-gray-400 mt-1">Gérez vos partenaires de livraison et leurs zones</p>
        </div>
        <a href="{{ route('admin.deliverers.create') }}"
           class="px-6 py-3 bg-gradient-to-r from-primary-500 to-primary-600 text-white rounded-lg hover:shadow-lg transition-all duration-200 shadow-lg hover:shadow-xl flex items-center gap-2">
            <i class="fas fa-plus"></i>
            Nouvelle Entreprise
        </a>
    </div>

    @if(session('success'))
        <div class="mb-6 bg-dark-100 rounded-xl shadow-lg border border-green-500/50 overflow-hidden">
            {!! session('success') !!}
        </div>
    @endif

    @if(session('error'))
        <div class="mb-6 p-4 bg-red-900/20 border-l-4 border-red-500 rounded-lg">
            <div class="flex items-center">
                <i class="fas fa-exclamation-circle text-red-500 mr-3"></i>
                <p class="text-red-400">{{ session('error') }}</p>
            </div>
        </div>
    @endif

    <!-- Filters -->
    <div class="bg-dark-100 rounded-xl shadow-lg p-6 mb-6 border border-dark-200">
        <form method="GET" action="{{ route('admin.deliverers.index') }}" class="space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                <div>
                    <label class="block text-sm font-medium text-white mb-2">
                        <i class="fas fa-search text-primary-500 mr-1"></i> Recherche
                    </label>
                    <input type="text" name="search" value="{{ request('search') }}"
                           placeholder="Nom entreprise, email, téléphone..."
                           class="w-full px-4 py-2 bg-dark-50 border border-dark-300 text-white placeholder-gray-400 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-white mb-2">
                        <i class="fas fa-sync text-primary-500 mr-1"></i> Statut
                    </label>
                    <select name="status"
                            class="w-full px-4 py-2 bg-dark-50 border border-dark-300 text-white rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500">
                        <option value="">Tous les statuts</option>
                        <option value="synced" {{ request('status') == 'synced' ? 'selected' : '' }}>Synchronisé</option>
                        <option value="pending" {{ request('status') == 'pending' ? 'selected' : '' }}>En attente</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-white mb-2">
                        <i class="fas fa-calendar-alt text-primary-500 mr-1"></i> Date de début
                    </label>
                    <input type="date" name="date_from" value="{{ request('date_from') }}"
                           class="w-full px-4 py-2 bg-dark-50 border border-dark-300 text-white rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-white mb-2">
                        <i class="fas fa-calendar-alt text-primary-500 mr-1"></i> Date de fin
                    </label>
                    <input type="date" name="date_to" value="{{ request('date_to') }}"
                           class="w-full px-4 py-2 bg-dark-50 border border-dark-300 text-white rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500">
                </div>
            </div>
            <div class="flex gap-3">
                <button type="submit" class="px-6 py-2 bg-gradient-to-r from-primary-500 to-primary-600 text-white rounded-lg hover:shadow-lg transition-all">
                    <i class="fas fa-filter mr-2"></i> Filtrer
                </button>
                <a href="{{ route('admin.deliverers.index') }}" class="px-6 py-2 bg-gray-600 hover:bg-gray-700 text-white rounded-lg transition-all">
                    <i class="fas fa-times mr-2"></i> Réinitialiser
                </a>
            </div>
        </form>
    </div>

    <!-- Stats -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
        <div class="bg-gradient-to-br from-primary-500 to-primary-600 rounded-xl p-4 text-white shadow-lg">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-orange-100 text-sm">Total Entreprises</p>
                    <p class="text-2xl font-bold">{{ \App\Models\DelivererCompany::count() }}</p>
                </div>
                <i class="fas fa-building text-3xl text-orange-200"></i>
            </div>
        </div>
        <div class="bg-gradient-to-br from-green-500 to-green-600 rounded-xl p-4 text-white shadow-lg">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-green-100 text-sm">Synchronisées</p>
                    <p class="text-2xl font-bold">{{ \App\Models\DelivererCompany::whereNotNull('user_id')->count() }}</p>
                </div>
                <i class="fas fa-check-circle text-3xl text-green-200"></i>
            </div>
        </div>
        <div class="bg-gradient-to-br from-yellow-500 to-yellow-600 rounded-xl p-4 text-white shadow-lg">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-yellow-100 text-sm">En attente</p>
                    <p class="text-2xl font-bold">{{ \App\Models\DelivererCompany::whereNull('user_id')->count() }}</p>
                </div>
                <i class="fas fa-clock text-3xl text-yellow-200"></i>
            </div>
        </div>
        <div class="bg-gradient-to-br from-blue-500 to-blue-600 rounded-xl p-4 text-white shadow-lg">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-blue-100 text-sm">Zones Totales</p>
                    <p class="text-2xl font-bold">{{ \App\Models\DeliveryZone::count() }}</p>
                </div>
                <i class="fas fa-map-marked-alt text-3xl text-blue-200"></i>
            </div>
        </div>
    </div>

    <!-- Deliverers Grid -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        @forelse($deliverers as $deliverer)
            <div class="bg-dark-100 rounded-xl shadow-lg border border-dark-200 overflow-hidden hover:border-primary-500/50 transition-all">
                <!-- Company Header -->
                <div class="bg-dark-50 p-5 flex items-center gap-4 border-b border-dark-200">
                    @if($deliverer->logo)
                        <img src="{{ Storage::url($deliverer->logo) }}" alt="{{ $deliverer->name }}"
                             class="w-16 h-16 rounded-xl object-cover border-2 border-primary-500/30">
                    @else
                        <div class="w-16 h-16 rounded-xl bg-gradient-to-br from-primary-500 to-primary-600 flex items-center justify-center text-white text-2xl font-bold shadow-lg">
                            {{ strtoupper(substr($deliverer->name, 0, 1)) }}
                        </div>
                    @endif
                    <div class="flex-1 min-w-0">
                        <h3 class="text-white font-bold text-lg truncate">{{ $deliverer->name }}</h3>
                        <div class="flex items-center gap-2 mt-1">
                            @if($deliverer->user_id)
                                <span class="inline-flex items-center gap-1 px-2 py-1 bg-green-900/30 border border-green-500/50 rounded-full text-green-400 text-xs font-semibold">
                                    <i class="fas fa-check-circle"></i>
                                    Synchronisé
                                </span>
                            @else
                                <span class="inline-flex items-center gap-1 px-2 py-1 bg-yellow-900/30 border border-yellow-500/50 rounded-full text-yellow-400 text-xs font-semibold">
                                    <i class="fas fa-clock"></i>
                                    En attente
                                </span>
                            @endif
                            <span class="inline-flex items-center gap-1 px-2 py-1 bg-blue-900/30 border border-blue-500/50 rounded-full text-blue-400 text-xs">
                                <i class="fas fa-map-marker-alt"></i>
                                {{ $deliverer->deliveryZones->count() }} zone(s)
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Company Info -->
                <div class="p-5 space-y-3">
                    <div class="flex items-center gap-3 text-sm">
                        <i class="fas fa-envelope text-primary-500 w-5"></i>
                        <span class="text-gray-400">{{ $deliverer->email }}</span>
                    </div>
                    <div class="flex items-center gap-3 text-sm">
                        <i class="fas fa-phone text-primary-500 w-5"></i>
                        <span class="text-gray-400">{{ $deliverer->phone }}</span>
                    </div>

                    <!-- Delivery Zones -->
                    @if($deliverer->deliveryZones->count() > 0)
                        <div class="mt-3">
                            <p class="text-xs text-gray-500 mb-2 flex items-center gap-1">
                                <i class="fas fa-map-marked-alt text-primary-500"></i>
                                Zones de livraison :
                            </p>
                            <div class="flex flex-wrap gap-1.5">
                                @foreach($deliverer->deliveryZones as $zone)
                                    <span class="inline-flex items-center gap-1 px-2 py-1 bg-blue-900/30 border border-blue-500/40 rounded-full text-blue-300 text-xs">
                                        <i class="fas fa-map-pin text-[10px]"></i>
                                        {{ $zone->name }}
                                    </span>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    @if(!$deliverer->user_id && $deliverer->syncCodes->first())
                        @php $latestCode = $deliverer->syncCodes->first(); @endphp
                        <div class="mt-4 p-3 bg-yellow-900/10 border border-yellow-500/30 rounded-lg">
                            <p class="text-xs text-yellow-400 mb-2 flex items-center gap-1">
                                <i class="fas fa-key"></i>
                                Code de synchronisation :
                            </p>
                            <code class="text-sm font-mono text-white bg-dark-50 px-3 py-1.5 rounded">{{ $latestCode->sync_code }}</code>
                            <p class="text-xs text-gray-500 mt-2">
                                Expire le {{ $latestCode->expires_at->format('d/m/Y') }}
                            </p>
                        </div>
                    @endif

                    @if($deliverer->user_id)
                        <div class="mt-4 p-3 bg-green-900/10 border border-green-500/30 rounded-lg">
                            <p class="text-xs text-green-400 mb-1 flex items-center gap-1">
                                <i class="fas fa-user-check"></i>
                                Livreur synchronisé :
                            </p>
                            <p class="text-sm text-white">{{ $deliverer->user->name ?? 'N/A' }}</p>
                            <p class="text-xs text-gray-500">{{ $deliverer->user->email ?? '' }}</p>
                        </div>
                    @endif

                    @if($deliverer->description)
                        <p class="text-sm text-gray-400 mt-3 line-clamp-2">{{ $deliverer->description }}</p>
                    @endif
                </div>

                <!-- Actions Footer -->
                <div class="bg-dark-50 px-5 py-3 border-t border-dark-200 flex items-center justify-between">
                    <span class="text-xs text-gray-500">
                        <i class="fas fa-calendar-alt mr-1"></i>
                        Créé le {{ $deliverer->created_at->format('d/m/Y') }}
                    </span>
                    <div class="flex items-center gap-2">
                        <a href="{{ route('admin.deliverers.show', $deliverer->id) }}" class="px-3 py-1.5 bg-primary-500/20 hover:bg-primary-500/30 text-primary-400 rounded-lg text-xs font-semibold transition-all">
                            <i class="fas fa-eye mr-1"></i> Voir
                        </a>
                        <a href="{{ route('admin.deliverers.edit', $deliverer->id) }}" class="px-3 py-1.5 bg-blue-600/50 hover:bg-blue-600 text-white rounded-lg text-xs font-semibold transition-all">
                            <i class="fas fa-edit mr-1"></i> Éditer
                        </a>
                        <form action="{{ route('admin.deliverers.destroy', $deliverer->id) }}" method="POST" class="inline" data-confirm="Supprimer {{ $deliverer->name }} ? Toutes les zones et tarifs seront également supprimés.">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="px-3 py-1.5 bg-red-600/50 hover:bg-red-600 text-white rounded-lg text-xs font-semibold transition-all">
                                <i class="fas fa-trash mr-1"></i> Supprimer
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        @empty
            <!-- Empty State -->
            <div class="col-span-full bg-dark-100 rounded-xl shadow-lg p-12 text-center border border-dark-200">
                <i class="fas fa-truck text-6xl text-gray-600 mb-4"></i>
                <h2 class="text-xl font-bold text-gray-300 mb-2">Aucune entreprise de livraison</h2>
                <p class="text-gray-500 mb-6">Commencez par créer votre première entreprise partenaire</p>
                <a href="{{ route('admin.deliverers.create') }}"
                   class="inline-flex items-center gap-2 px-6 py-3 bg-gradient-to-r from-primary-500 to-primary-600 text-white rounded-lg hover:shadow-lg transition-all">
                    <i class="fas fa-plus"></i>
                    Créer une entreprise
                </a>
            </div>
        @endforelse
    </div>

    <!-- Pagination -->
    @if($deliverers->hasPages())
        <div class="mt-6">
            {{ $deliverers->links() }}
        </div>
    @endif
</div>
@endsection
