@extends('admin.layouts.app')

@section('content')
<div class="p-6" x-data="{ viewMode: localStorage.getItem('shopsViewMode') || 'grid' }" x-init="$watch('viewMode', value => localStorage.setItem('shopsViewMode', value))">
    <!-- Header -->
    <div class="flex justify-between items-center mb-6">
        <div>
            <h1 class="text-2xl font-bold text-white">Gestion des Boutiques</h1>
            <p class="text-gray-400 mt-1">Gérez toutes les boutiques de la plateforme</p>
        </div>
        <a href="{{ route('admin.shops.create') }}"
           class="px-6 py-3 bg-gradient-to-r from-primary-500 to-primary-600 text-white rounded-lg hover:shadow-lg transition-all duration-200 shadow-lg hover:shadow-xl flex items-center gap-2">
            <i class="fas fa-plus"></i>
            Nouvelle Boutique
        </a>
    </div>

    @if(session('success'))
        <div class="mb-6 p-4 bg-green-900/20 border-l-4 border-green-500 rounded-lg">
            <div class="flex items-center">
                <i class="fas fa-check-circle text-green-500 mr-3"></i>
                <p class="text-green-700">{{ session('success') }}</p>
            </div>
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

    <!-- View Toggle & Filters -->
    <div class="bg-dark-100 rounded-xl shadow-lg p-6 mb-6">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-semibold text-white">
                <i class="fas fa-filter text-primary-500 mr-2"></i>
                Filtres
            </h3>
            <!-- View Mode Toggle -->
            <div class="flex gap-2 bg-dark-50 p-1 rounded-lg">
                <button type="button"
                        @click="viewMode = 'grid'"
                        :class="viewMode === 'grid' ? 'bg-primary-500 text-white' : 'text-gray-400 hover:text-white'"
                        class="px-4 py-2 rounded transition-all">
                    <i class="fas fa-th"></i>
                    <span class="ml-2 hidden sm:inline">Grille</span>
                </button>
                <button type="button"
                        @click="viewMode = 'list'"
                        :class="viewMode === 'list' ? 'bg-primary-500 text-white' : 'text-gray-400 hover:text-white'"
                        class="px-4 py-2 rounded transition-all">
                    <i class="fas fa-list"></i>
                    <span class="ml-2 hidden sm:inline">Liste</span>
                </button>
            </div>
        </div>

        <form method="GET" action="{{ route('admin.shops.index') }}" class="space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <!-- Search -->
                <div>
                    <label class="block text-sm font-medium text-white mb-2">
                        <i class="fas fa-search text-primary-500 mr-1"></i>
                        Recherche
                    </label>
                    <input type="text"
                           name="search"
                           value="{{ request('search') }}"
                           placeholder="Nom de boutique, description..."
                           class="w-full px-4 py-2 bg-dark-50 border border-dark-300 text-white placeholder-gray-400 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500">
                </div>

                <!-- Status Filter -->
                <div>
                    <label class="block text-sm font-medium text-white mb-2">
                        <i class="fas fa-toggle-on text-primary-500 mr-1"></i>
                        Statut
                    </label>
                    <select name="status" class="w-full px-4 py-2 bg-dark-50 border border-dark-300 text-white rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500">
                        <option value="">Tous les statuts</option>
                        <option value="active" {{ request('status') == 'active' ? 'selected' : '' }}>Active</option>
                        <option value="inactive" {{ request('status') == 'inactive' ? 'selected' : '' }}>Inactive</option>
                    </select>
                </div>

                <!-- User Filter -->
                <div>
                    <label class="block text-sm font-medium text-white mb-2">
                        <i class="fas fa-user text-primary-500 mr-1"></i>
                        Propriétaire
                    </label>
                    <select name="user_id" class="w-full px-4 py-2 bg-dark-50 border border-dark-300 text-white rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500">
                        <option value="">Tous les propriétaires</option>
                        @foreach($users as $owner)
                            <option value="{{ $owner->id }}" {{ request('user_id') == $owner->id ? 'selected' : '' }}>
                                {{ $owner->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="flex gap-3">
                <button type="submit"
                        class="px-6 py-2 bg-gradient-to-r from-primary-500 to-primary-600 text-white rounded-lg hover:shadow-lg transition-all">
                    <i class="fas fa-filter mr-2"></i>
                    Filtrer
                </button>
                <a href="{{ route('admin.shops.index') }}"
                   class="px-6 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 transition-all">
                    <i class="fas fa-times mr-2"></i>
                    Réinitialiser
                </a>
            </div>
        </form>
    </div>

    <!-- Shops Display -->
    <div>
        <!-- Grid View -->
        <div x-show="viewMode === 'grid'" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            @forelse($shops as $shop)
            <div class="bg-dark-100 rounded-xl shadow-lg overflow-hidden hover:shadow-xl transition-all duration-300 border border-dark-200 hover:border-orange-500">
                <!-- Shop Header -->
                <div class="bg-gradient-to-r from-primary-500 to-primary-600 p-4">
                    <div class="flex items-start justify-between gap-3">
                        <!-- Logo -->
                        @if($shop->logo_url)
                            <div class="flex-shrink-0">
                                <img src="{{ $shop->logo_url }}"
                                     alt="Logo {{ $shop->name }}"
                                     class="w-16 h-16 object-cover rounded-lg border-2 border-white shadow-md"
                                     onerror="this.onerror=null; this.parentElement.innerHTML='<div class=\'w-16 h-16 bg-white/20 rounded-lg flex items-center justify-center border-2 border-white shadow-md\'><i class=\'fas fa-store text-white text-2xl\'></i></div>';">
                            </div>
                        @else
                            <div class="flex-shrink-0 w-16 h-16 bg-white/20 rounded-lg flex items-center justify-center border-2 border-white shadow-md">
                                <i class="fas fa-store text-white text-2xl"></i>
                            </div>
                        @endif

                        <div class="flex-1 min-w-0">
                            <h3 class="text-white font-bold text-lg mb-1 truncate">{{ $shop->name }}</h3>
                            <p class="text-orange-100 text-sm truncate">
                                <i class="fas fa-user mr-1"></i>
                                {{ $shop->user->name }}
                            </p>
                        </div>
                        @if($shop->status == 'active')
                            <span class="px-2 py-1 text-xs rounded-full bg-green-500/20 text-green-300 border border-green-500/50 font-semibold flex-shrink-0 self-start">
                                <i class="fas fa-check-circle"></i> Active
                            </span>
                        @else
                            <span class="px-2 py-1 text-xs rounded-full bg-dark-50 text-white font-semibold flex-shrink-0 self-start">
                                <i class="fas fa-times-circle"></i> Inactive
                            </span>
                        @endif
                    </div>
                </div>

                <!-- Shop Body -->
                <div class="p-4">
                    @if($shop->description)
                        <p class="text-gray-400 text-sm mb-4 line-clamp-2">{{ $shop->description }}</p>
                    @else
                        <p class="text-gray-400 text-sm italic mb-4">Aucune description</p>
                    @endif

                    <!-- Shop Stats -->
                    <div class="grid grid-cols-2 gap-3 mb-4">
                        <div class="bg-dark-50 border border-dark-300 rounded-lg p-3 text-center">
                            <i class="fas fa-box text-blue-400 text-xl mb-1"></i>
                            <p class="text-xs text-gray-400">Produits</p>
                            <p class="text-lg font-bold text-white">{{ $shop->products->count() }}</p>
                        </div>
                        <div class="bg-dark-50 border border-dark-300 rounded-lg p-3 text-center">
                            <i class="fas fa-calendar text-primary-500 text-xl mb-1"></i>
                            <p class="text-xs text-gray-400">Créée le</p>
                            <p class="text-sm font-semibold text-white">{{ $shop->created_at->format('d/m/Y') }}</p>
                        </div>
                    </div>

                    <!-- Location Request Alert -->
                    @if($shop->locationRequests->count() > 0)
                    <div class="mb-3 p-3 bg-orange-500/20 border border-orange-500/50 rounded-lg">
                        <div class="flex items-center gap-2">
                            <i class="fas fa-map-marker-alt text-orange-400 text-lg animate-pulse"></i>
                            <div class="flex-1">
                                <p class="text-orange-300 font-semibold text-sm">
                                    {{ $shop->locationRequests->count() }} demande(s) de changement de localisation
                                </p>
                                <p class="text-orange-400 text-xs">Cliquez sur "Voir" pour traiter</p>
                            </div>
                        </div>
                    </div>
                    @endif

                    <!-- Verification Status & Action -->
                    <div class="mb-3">
                        @if($shop->isVerified())
                            <!-- Verified Badge -->
                            <div class="p-3 bg-green-500/20 border border-green-500/50 rounded-lg">
                                <div class="flex items-center gap-2">
                                    <i class="fas fa-check-circle text-green-400"></i>
                                    <div>
                                        <p class="text-green-300 font-semibold text-xs">Vérifiée</p>
                                        <p class="text-green-400 text-xs">{{ $shop->verified_at->format('d/m/Y') }}</p>
                                    </div>
                                </div>
                            </div>
                        @elseif($shop->isRejected())
                            <!-- Rejected Badge -->
                            <div class="p-3 bg-red-500/20 border border-red-500/50 rounded-lg">
                                <div class="flex items-center gap-2">
                                    <i class="fas fa-times-circle text-red-400"></i>
                                    <div class="flex-1">
                                        <p class="text-red-300 font-semibold text-xs">Rejetée</p>
                                        @if($shop->rejection_reason)
                                            <p class="text-red-400 text-xs mt-1 line-clamp-1">{{ $shop->rejection_reason }}</p>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        @else
                            <!-- Pending - Show Switch to Verify -->
                            <div class="p-3 bg-orange-500/20 border border-orange-500/50 rounded-lg">
                                <div class="flex items-center justify-between mb-2">
                                    <div class="flex items-center gap-2">
                                        <i class="fas fa-clock text-orange-400"></i>
                                        <p class="text-orange-300 font-semibold text-xs">En attente</p>
                                    </div>
                                    <!-- Switch Toggle -->
                                    <form action="{{ route('admin.shops.verify', $shop) }}"
                                          method="POST"
                                          data-confirm="Voulez-vous vérifier cette boutique ?"
                                          class="inline">
                                        @csrf
                                        <button type="submit"
                                                class="relative inline-flex h-6 w-11 items-center rounded-full bg-dark-300 hover:bg-primary-500 transition-colors duration-200"
                                                title="Cliquez pour vérifier">
                                            <span class="inline-block h-4 w-4 transform rounded-full bg-white transition-transform duration-200 translate-x-1"></span>
                                        </button>
                                    </form>
                                </div>
                                <p class="text-orange-400 text-xs">
                                    <i class="fas fa-info-circle mr-1"></i>
                                    Cliquez sur le switch pour approuver
                                </p>
                            </div>
                        @endif
                    </div>

                    <!-- Action Buttons -->
                    <div class="flex gap-2">
                        <a href="{{ route('admin.shops.show', $shop) }}"
                           class="flex-1 text-center px-3 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition-all text-sm">
                            <i class="fas fa-eye"></i>
                            Voir
                        </a>
                        <a href="{{ route('admin.shops.edit', $shop) }}"
                           class="flex-1 text-center px-3 py-2 bg-gradient-to-r from-primary-500 to-primary-600 hover:shadow-lg text-white rounded-lg transition-all text-sm">
                            <i class="fas fa-edit"></i>
                            Modifier
                        </a>
                        <form action="{{ route('admin.shops.destroy', $shop) }}"
                              method="POST"
                              class="flex-1"
                              data-confirm="Êtes-vous sûr de vouloir supprimer cette boutique ?">
                            @csrf
                            @method('DELETE')
                            <button type="submit"
                                    class="w-full px-3 py-2 bg-red-600 hover:bg-red-700 text-white rounded-lg transition-all text-sm">
                                <i class="fas fa-trash"></i>
                                Supprimer
                            </button>
                        </form>
                    </div>
                </div>
            </div>
            @empty
                <div class="col-span-full text-center py-12 bg-dark-100 rounded-xl shadow-lg">
                    <i class="fas fa-store text-6xl text-gray-300 mb-4"></i>
                    <p class="text-xl text-gray-500 mb-2">Aucune boutique trouvée</p>
                    <p class="text-gray-400">Créez votre première boutique pour commencer</p>
                </div>
            @endforelse
        </div>

        <!-- List View -->
        <div x-show="viewMode === 'list'" class="space-y-4">
            @forelse($shops as $shop)
                <div class="bg-dark-100 rounded-xl shadow-lg overflow-hidden hover:shadow-xl transition-all duration-300 border border-dark-200 hover:border-orange-500">
                    <div class="flex flex-col md:flex-row">
                        <!-- Left: Logo & Main Info -->
                        <div class="md:w-1/3 bg-gradient-to-r from-primary-500 to-primary-600 p-6">
                            <div class="flex items-start gap-4">
                                @if($shop->logo_url)
                                    <img src="{{ $shop->logo_url }}"
                                         alt="Logo {{ $shop->name }}"
                                         class="w-20 h-20 object-cover rounded-lg border-2 border-white shadow-md flex-shrink-0"
                                         onerror="this.onerror=null; this.parentElement.innerHTML='<div class=\'w-20 h-20 bg-white/20 rounded-lg flex items-center justify-center border-2 border-white shadow-md flex-shrink-0\'><i class=\'fas fa-store text-white text-3xl\'></i></div>';">
                                @else
                                    <div class="w-20 h-20 bg-white/20 rounded-lg flex items-center justify-center border-2 border-white shadow-md flex-shrink-0">
                                        <i class="fas fa-store text-white text-3xl"></i>
                                    </div>
                                @endif
                                <div class="flex-1 min-w-0">
                                    <h3 class="text-white font-bold text-xl mb-2">{{ $shop->name }}</h3>
                                    <p class="text-orange-100 text-sm mb-2">
                                        <i class="fas fa-user mr-1"></i>
                                        {{ $shop->user->name }}
                                    </p>
                                    @if($shop->status == 'active')
                                        <span class="inline-block px-3 py-1 text-xs rounded-full bg-green-500/20 text-green-300 border border-green-500/50 font-semibold">
                                            <i class="fas fa-check-circle"></i> Active
                                        </span>
                                    @else
                                        <span class="inline-block px-3 py-1 text-xs rounded-full bg-dark-50 text-white font-semibold">
                                            <i class="fas fa-times-circle"></i> Inactive
                                        </span>
                                    @endif
                                </div>
                            </div>
                        </div>

                        <!-- Middle: Description & Stats -->
                        <div class="md:w-1/3 p-6 border-r border-dark-200">
                            <h4 class="text-white font-semibold mb-2">Description</h4>
                            @if($shop->description)
                                <p class="text-gray-400 text-sm mb-4 line-clamp-3">{{ $shop->description }}</p>
                            @else
                                <p class="text-gray-400 text-sm italic mb-4">Aucune description</p>
                            @endif

                            <div class="flex gap-4 mb-3">
                                <div class="flex items-center gap-2">
                                    <div class="w-10 h-10 bg-blue-500/20 rounded-lg flex items-center justify-center">
                                        <i class="fas fa-box text-blue-400"></i>
                                    </div>
                                    <div>
                                        <p class="text-xs text-gray-400">Produits</p>
                                        <p class="text-lg font-bold text-white">{{ $shop->products->count() }}</p>
                                    </div>
                                </div>
                                <div class="flex items-center gap-2">
                                    <div class="w-10 h-10 bg-primary-500/20 rounded-lg flex items-center justify-center">
                                        <i class="fas fa-calendar text-primary-400"></i>
                                    </div>
                                    <div>
                                        <p class="text-xs text-gray-400">Créée le</p>
                                        <p class="text-sm font-semibold text-white">{{ $shop->created_at->format('d/m/Y') }}</p>
                                    </div>
                                </div>
                            </div>

                            <!-- Location Request Alert -->
                            @if($shop->locationRequests->count() > 0)
                            <div class="p-3 bg-orange-500/20 border border-orange-500/50 rounded-lg">
                                <div class="flex items-center gap-2">
                                    <i class="fas fa-map-marker-alt text-orange-400 animate-pulse"></i>
                                    <div>
                                        <p class="text-orange-300 font-semibold text-xs">
                                            {{ $shop->locationRequests->count() }} demande(s) de localisation
                                        </p>
                                        <p class="text-orange-400 text-xs">Cliquez sur "Voir" pour traiter</p>
                                    </div>
                                </div>
                            </div>
                            @endif
                        </div>

                        <!-- Right: Actions -->
                        <div class="md:w-1/3 p-6 flex flex-col justify-center gap-3">
                            <!-- Verification Status & Action -->
                            <div class="mb-2">
                                @if($shop->isVerified())
                                    <!-- Verified Badge -->
                                    <div class="flex items-center justify-between p-3 bg-green-500/20 border border-green-500/50 rounded-lg">
                                        <div class="flex items-center gap-2">
                                            <i class="fas fa-check-circle text-green-400 text-lg"></i>
                                            <div>
                                                <p class="text-green-300 font-semibold text-sm">Vérifiée</p>
                                                <p class="text-green-400 text-xs">{{ $shop->verified_at->format('d/m/Y') }}</p>
                                            </div>
                                        </div>
                                    </div>
                                @elseif($shop->isRejected())
                                    <!-- Rejected Badge -->
                                    <div class="p-3 bg-red-500/20 border border-red-500/50 rounded-lg">
                                        <div class="flex items-center gap-2 mb-1">
                                            <i class="fas fa-times-circle text-red-400 text-lg"></i>
                                            <p class="text-red-300 font-semibold text-sm">Rejetée</p>
                                        </div>
                                        @if($shop->rejection_reason)
                                            <p class="text-red-400 text-xs mt-1">{{ Str::limit($shop->rejection_reason, 50) }}</p>
                                        @endif
                                    </div>
                                @else
                                    <!-- Pending - Show Switch to Verify -->
                                    <div class="p-3 bg-orange-500/20 border border-orange-500/50 rounded-lg">
                                        <div class="flex items-center justify-between">
                                            <div class="flex items-center gap-2">
                                                <i class="fas fa-clock text-orange-400 text-lg"></i>
                                                <p class="text-orange-300 font-semibold text-sm">En attente</p>
                                            </div>
                                            <!-- Switch Toggle -->
                                            <form action="{{ route('admin.shops.verify', $shop) }}"
                                                  method="POST"
                                                  data-confirm="Voulez-vous vérifier cette boutique ?"
                                                  class="inline">
                                                @csrf
                                                <button type="submit"
                                                        class="relative inline-flex h-6 w-11 items-center rounded-full bg-dark-300 hover:bg-primary-500 transition-colors duration-200"
                                                        title="Cliquez pour vérifier">
                                                    <span class="inline-block h-4 w-4 transform rounded-full bg-white transition-transform duration-200 translate-x-1"></span>
                                                </button>
                                            </form>
                                        </div>
                                        <p class="text-orange-400 text-xs mt-2">
                                            <i class="fas fa-info-circle mr-1"></i>
                                            Cliquez sur le switch pour approuver
                                        </p>
                                    </div>
                                @endif
                            </div>

                            <div class="flex gap-2">
                                <a href="{{ route('admin.shops.show', $shop) }}"
                                   class="flex-1 text-center px-3 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition-all text-sm">
                                    <i class="fas fa-eye"></i>
                                    Voir
                                </a>
                                <a href="{{ route('admin.shops.edit', $shop) }}"
                                   class="flex-1 text-center px-3 py-2 bg-gradient-to-r from-primary-500 to-primary-600 hover:shadow-lg text-white rounded-lg transition-all text-sm">
                                    <i class="fas fa-edit"></i>
                                    Modifier
                                </a>
                            </div>
                            <form action="{{ route('admin.shops.destroy', $shop) }}"
                                  method="POST"
                                  data-confirm="Êtes-vous sûr de vouloir supprimer cette boutique ?">
                                @csrf
                                @method('DELETE')
                                <button type="submit"
                                        class="w-full px-3 py-2 bg-red-600 hover:bg-red-700 text-white rounded-lg transition-all text-sm">
                                    <i class="fas fa-trash mr-2"></i>
                                    Supprimer
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            @empty
                <div class="text-center py-12 bg-dark-100 rounded-xl shadow-lg">
                    <i class="fas fa-store text-6xl text-gray-300 mb-4"></i>
                    <p class="text-xl text-gray-500 mb-2">Aucune boutique trouvée</p>
                    <p class="text-gray-400">Créez votre première boutique pour commencer</p>
                </div>
            @endforelse
        </div>
    </div>

    <!-- Pagination -->
    @if($shops->hasPages())
        <div class="mt-6">
            {{ $shops->links() }}
        </div>
    @endif

    <!-- Stats Summary -->
    <div class="mt-6 grid grid-cols-1 md:grid-cols-3 gap-4">
        <div class="bg-gradient-to-br from-green-500 to-green-600 rounded-xl p-4 text-white shadow-lg">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-green-100 text-sm">Total Boutiques</p>
                    <p class="text-2xl font-bold">{{ $shops->total() }}</p>
                </div>
                <i class="fas fa-store text-3xl text-green-200"></i>
            </div>
        </div>
        <div class="bg-gradient-to-br from-blue-500 to-blue-600 rounded-xl p-4 text-white shadow-lg">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-blue-100 text-sm">Boutiques Actives</p>
                    <p class="text-2xl font-bold">{{ \App\Models\Shop::where('status', 'active')->count() }}</p>
                </div>
                <i class="fas fa-check-circle text-3xl text-blue-200"></i>
            </div>
        </div>
        <div class="bg-gradient-to-br from-primary-500 to-primary-600 rounded-xl p-4 text-white shadow-lg">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-orange-100 text-sm">Produits Total</p>
                    <p class="text-2xl font-bold">{{ \App\Models\Product::count() }}</p>
                </div>
                <i class="fas fa-box text-3xl text-orange-200"></i>
            </div>
        </div>
    </div>
</div>
@endsection
