@extends('admin.layouts.app')

@section('title', 'Offres DIASPO')
@section('header', 'Offres DIASPO')

@section('content')
<div class="p-6">
    <!-- Header -->
    <div class="flex justify-between items-center mb-6">
        <div>
            <h1 class="text-2xl font-bold text-white">Offres DIASPO Exchange</h1>
            <p class="text-gray-400 mt-1">Gérez les offres de transport de colis</p>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="grid grid-cols-1 md:grid-cols-6 gap-4 mb-6">
        <div class="bg-dark-100 rounded-xl shadow-lg p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-gray-400 text-sm">Total</p>
                    <p class="text-2xl font-bold text-white mt-1">{{ $stats['total'] }}</p>
                </div>
                <div class="w-12 h-12 bg-primary-500/20 rounded-lg flex items-center justify-center">
                    <i class="fas fa-plane-departure text-primary-500 text-xl"></i>
                </div>
            </div>
        </div>

        <div class="bg-dark-100 rounded-xl shadow-lg p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-gray-400 text-sm">En attente</p>
                    <p class="text-2xl font-bold text-yellow-500 mt-1">{{ $stats['pending'] }}</p>
                </div>
                <div class="w-12 h-12 bg-yellow-500/20 rounded-lg flex items-center justify-center">
                    <i class="fas fa-clock text-yellow-500 text-xl"></i>
                </div>
            </div>
        </div>

        <div class="bg-dark-100 rounded-xl shadow-lg p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-gray-400 text-sm">Approuvées</p>
                    <p class="text-2xl font-bold text-green-500 mt-1">{{ $stats['approved'] }}</p>
                </div>
                <div class="w-12 h-12 bg-green-500/20 rounded-lg flex items-center justify-center">
                    <i class="fas fa-check-circle text-green-500 text-xl"></i>
                </div>
            </div>
        </div>

        <div class="bg-dark-100 rounded-xl shadow-lg p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-gray-400 text-sm">Disponibles</p>
                    <p class="text-2xl font-bold text-blue-500 mt-1">{{ $stats['available'] }}</p>
                </div>
                <div class="w-12 h-12 bg-blue-500/20 rounded-lg flex items-center justify-center">
                    <i class="fas fa-box-open text-blue-500 text-xl"></i>
                </div>
            </div>
        </div>

        <div class="bg-dark-100 rounded-xl shadow-lg p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-gray-400 text-sm">Rejetées</p>
                    <p class="text-2xl font-bold text-red-500 mt-1">{{ $stats['rejected'] }}</p>
                </div>
                <div class="w-12 h-12 bg-red-500/20 rounded-lg flex items-center justify-center">
                    <i class="fas fa-times-circle text-red-500 text-xl"></i>
                </div>
            </div>
        </div>

        <div class="bg-dark-100 rounded-xl shadow-lg p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-gray-400 text-sm">Aujourd'hui</p>
                    <p class="text-2xl font-bold text-purple-500 mt-1">{{ $stats['today'] }}</p>
                </div>
                <div class="w-12 h-12 bg-purple-500/20 rounded-lg flex items-center justify-center">
                    <i class="fas fa-calendar-day text-purple-500 text-xl"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="bg-dark-100 rounded-xl shadow-lg p-4 mb-6">
        <form method="GET" action="{{ route('admin.diaspo.offers.index') }}" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-3">
            <!-- Search -->
            <div class="lg:col-span-2">
                <input type="text"
                       name="search"
                       value="{{ request('search') }}"
                       placeholder="Rechercher par pays, ville ou utilisateur..."
                       class="w-full px-4 py-2 bg-dark-50 text-white border border-dark-200 rounded-lg focus:outline-none focus:border-primary-500">
            </div>

            <!-- Status filter -->
            <select name="status" class="px-4 py-2 bg-dark-50 text-white border border-dark-200 rounded-lg focus:outline-none focus:border-primary-500">
                <option value="">Tous les statuts</option>
                <option value="pending" {{ request('status') === 'pending' ? 'selected' : '' }}>En attente</option>
                <option value="approved" {{ request('status') === 'approved' ? 'selected' : '' }}>Approuvées</option>
                <option value="rejected" {{ request('status') === 'rejected' ? 'selected' : '' }}>Rejetées</option>
                <option value="expired" {{ request('status') === 'expired' ? 'selected' : '' }}>Expirées</option>
            </select>

            <!-- Verification status filter -->
            <select name="verification_status" class="px-4 py-2 bg-dark-50 text-white border border-dark-200 rounded-lg focus:outline-none focus:border-primary-500">
                <option value="all">Vérification: Tous</option>
                <option value="pending" {{ request('verification_status') === 'pending' ? 'selected' : '' }}>En attente</option>
                <option value="verified" {{ request('verification_status') === 'verified' ? 'selected' : '' }}>Vérifiées</option>
                <option value="rejected" {{ request('verification_status') === 'rejected' ? 'selected' : '' }}>Rejetées</option>
            </select>

            <!-- Order by -->
            <select name="order_by" class="px-4 py-2 bg-dark-50 text-white border border-dark-200 rounded-lg focus:outline-none focus:border-primary-500">
                <option value="recent" {{ request('order_by') === 'recent' || !request('order_by') ? 'selected' : '' }}>Plus récentes</option>
                <option value="oldest" {{ request('order_by') === 'oldest' ? 'selected' : '' }}>Plus anciennes</option>
                <option value="departure_soon" {{ request('order_by') === 'departure_soon' ? 'selected' : '' }}>Départ proche</option>
                <option value="popular" {{ request('order_by') === 'popular' ? 'selected' : '' }}>Plus réservées</option>
            </select>

            <!-- Buttons -->
            <button type="submit" class="px-6 py-2 bg-primary-500 text-white rounded-lg hover:bg-primary-600 transition-all">
                <i class="fas fa-search mr-2"></i>Filtrer
            </button>
            <a href="{{ route('admin.diaspo.offers.index') }}" class="px-6 py-2 bg-dark-200 text-white rounded-lg hover:bg-dark-300 transition-all text-center">
                <i class="fas fa-redo mr-2"></i>Réinitialiser
            </a>
        </form>
    </div>

    <!-- Offers List -->
    @if($offers->isEmpty())
        <div class="bg-dark-100 rounded-xl shadow-lg p-12 text-center">
            <i class="fas fa-plane-departure text-6xl text-gray-600 mb-4"></i>
            <h3 class="text-xl font-semibold text-white mb-2">Aucune offre</h3>
            <p class="text-gray-400">Aucune offre trouvée avec ces filtres.</p>
        </div>
    @else
        <div class="grid gap-4">
            @foreach($offers as $offer)
                <div class="bg-dark-100 rounded-xl shadow-lg hover:shadow-xl transition-all">
                    <div class="p-6">
                        <div class="flex items-start justify-between">
                            <div class="flex items-start space-x-4 flex-1">
                                <!-- User Avatar -->
                                <div class="w-14 h-14 rounded-full bg-gradient-to-br from-primary-500 to-primary-600 flex items-center justify-center text-white font-bold flex-shrink-0">
                                    {{ strtoupper(substr($offer->user->first_name ?? 'U', 0, 1)) }}{{ strtoupper(substr($offer->user->last_name ?? '', 0, 1)) }}
                                </div>

                                <!-- Offer Info -->
                                <div class="flex-1">
                                    <!-- User -->
                                    <div class="flex items-center gap-3 mb-2">
                                        <h3 class="text-lg font-semibold text-white">
                                            {{ $offer->user->first_name }} {{ $offer->user->last_name }}
                                        </h3>
                                        <span class="text-xs text-gray-500">
                                            <i class="fas fa-phone mr-1"></i>{{ $offer->user->phone }}
                                        </span>
                                    </div>

                                    <!-- Route -->
                                    <div class="flex items-center gap-2 mb-3">
                                        <div class="flex items-center gap-2">
                                            <i class="fas fa-plane-departure text-green-500"></i>
                                            <span class="text-gray-300 font-medium">{{ $offer->departure_city }}, {{ $offer->departure_country }}</span>
                                        </div>
                                        <i class="fas fa-arrow-right text-gray-500"></i>
                                        <div class="flex items-center gap-2">
                                            <i class="fas fa-plane-arrival text-red-500"></i>
                                            <span class="text-gray-300 font-medium">{{ $offer->arrival_city }}, {{ $offer->arrival_country }}</span>
                                        </div>
                                    </div>

                                    <!-- Details -->
                                    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-3">
                                        <div class="text-sm">
                                            <span class="text-gray-400">Départ:</span>
                                            <p class="text-white font-medium">{{ $offer->departure_datetime->format('d/m/Y H:i') }}</p>
                                        </div>
                                        <div class="text-sm">
                                            <span class="text-gray-400">Prix/kg:</span>
                                            <p class="text-white font-medium">{{ $offer->formatted_price }}</p>
                                        </div>
                                        <div class="text-sm">
                                            <span class="text-gray-400">Disponible:</span>
                                            <p class="text-white font-medium">{{ $offer->remaining_kg }} / {{ $offer->available_kg }} kg</p>
                                        </div>
                                        <div class="text-sm">
                                            <span class="text-gray-400">Réservations:</span>
                                            <p class="text-white font-medium">{{ $offer->bookings_count }}</p>
                                        </div>
                                    </div>

                                    <!-- Status Badges -->
                                    <div class="flex items-center gap-2 flex-wrap">
                                        @if($offer->status === 'pending' || $offer->verification_status === 'pending')
                                            <span class="px-3 py-1 bg-yellow-500/20 text-yellow-400 text-xs font-semibold rounded-full">
                                                <i class="fas fa-clock mr-1"></i>En attente
                                            </span>
                                        @elseif($offer->status === 'approved' && $offer->verification_status === 'verified')
                                            <span class="px-3 py-1 bg-green-500/20 text-green-400 text-xs font-semibold rounded-full">
                                                <i class="fas fa-check-circle mr-1"></i>Approuvée
                                            </span>
                                        @elseif($offer->status === 'rejected' || $offer->verification_status === 'rejected')
                                            <span class="px-3 py-1 bg-red-500/20 text-red-400 text-xs font-semibold rounded-full">
                                                <i class="fas fa-times-circle mr-1"></i>Rejetée
                                            </span>
                                        @elseif($offer->status === 'expired')
                                            <span class="px-3 py-1 bg-gray-500/20 text-gray-400 text-xs font-semibold rounded-full">
                                                <i class="fas fa-calendar-times mr-1"></i>Expirée
                                            </span>
                                        @endif

                                        @if($offer->is_available)
                                            <span class="px-3 py-1 bg-blue-500/20 text-blue-400 text-xs font-semibold rounded-full">
                                                <i class="fas fa-box-open mr-1"></i>Disponible
                                            </span>
                                        @endif

                                        <span class="text-xs text-gray-500 ml-auto">
                                            <i class="fas fa-calendar mr-1"></i>Créée le {{ $offer->created_at->format('d/m/Y') }}
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <!-- Actions -->
                            <div class="flex items-center gap-2 ml-4 flex-shrink-0">
                                <a href="{{ route('admin.diaspo.offers.show', $offer->id) }}"
                                   class="px-4 py-2 bg-gradient-to-r from-primary-500 to-primary-600 text-white rounded-lg hover:shadow-lg transition-all">
                                    <i class="fas fa-eye mr-1"></i>Voir
                                </a>
                                <form action="{{ route('admin.diaspo.offers.destroy', $offer->id) }}"
                                      method="POST"
                                      class="inline"
                                      onsubmit="return confirm('Êtes-vous sûr de vouloir supprimer cette offre ?')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="px-4 py-2 bg-red-500 text-white rounded-lg hover:bg-red-600 transition-all">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
                            </div>
                        </div>

                        @if(($offer->status === 'rejected' || $offer->verification_status === 'rejected') && $offer->rejection_reason)
                            <div class="mt-4 p-3 bg-red-900/20 border-l-4 border-red-500 rounded">
                                <p class="text-sm text-red-400">
                                    <i class="fas fa-exclamation-triangle mr-2"></i>
                                    <strong>Raison du rejet:</strong> {{ $offer->rejection_reason }}
                                </p>
                            </div>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>

        <!-- Pagination -->
        <div class="mt-6">
            {{ $offers->links() }}
        </div>
    @endif
</div>
@endsection
