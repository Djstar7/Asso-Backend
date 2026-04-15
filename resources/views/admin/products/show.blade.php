@extends('admin.layouts.app')

@section('content')
<div class="p-6">
    <!-- Header -->
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-white">{{ $product->name }}</h1>
            <p class="text-gray-400">Détails du produit</p>
        </div>
        <div class="flex gap-3">
            <a href="{{ route('admin.products.index') }}" class="px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 transition-all">
                <i class="fas fa-arrow-left mr-2"></i>
                Retour
            </a>
            <a href="{{ route('admin.products.edit', $product) }}" class="px-4 py-2 bg-gradient-to-r from-primary-500 to-primary-600 hover:shadow-lg text-white rounded-lg transition-all">
                <i class="fas fa-edit mr-2"></i>
                Modifier
            </a>
            <form action="{{ route('admin.products.destroy', $product) }}" method="POST" onsubmit="return confirm('Êtes-vous sûr de vouloir supprimer ce produit ?');">
                @csrf
                @method('DELETE')
                <button type="submit" class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-lg transition-all">
                    <i class="fas fa-trash mr-2"></i>
                    Supprimer
                </button>
            </form>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Main Content (2 columns) -->
        <div class="lg:col-span-2 space-y-6">
            <!-- Images Gallery -->
            @if($product->images->count() > 0)
            <div class="bg-dark-100 rounded-xl shadow-lg p-6">
                <h2 class="text-lg font-semibold text-white mb-4 flex items-center">
                    <i class="fas fa-images text-primary-500 mr-2"></i>
                    Images du produit
                </h2>

                <!-- Primary Image -->
                @if($product->primaryImage)
                <div class="mb-4">
                    <img src="{{ asset($product->primaryImage->image_path) }}" alt="{{ $product->name }}" class="w-full h-96 object-cover rounded-lg border-2 border-primary-500">
                    <p class="text-sm text-gray-400 text-center mt-2">
                        <i class="fas fa-star text-primary-500 mr-1"></i>Image principale
                    </p>
                </div>
                @endif

                <!-- Other Images -->
                @if($product->images->count() > 1)
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                    @foreach($product->images as $image)
                        @if(!$image->is_primary)
                            <div class="relative">
                                <img src="{{ asset($image->image_path) }}" alt="{{ $product->name }}" class="w-full h-32 object-cover rounded-lg border-2 border-dark-200">
                            </div>
                        @endif
                    @endforeach
                </div>
                @endif
            </div>
            @else
            <div class="bg-dark-100 rounded-xl shadow-lg p-6">
                <div class="text-center py-12 text-gray-400">
                    <i class="fas fa-image text-6xl mb-4"></i>
                    <p class="text-lg">Aucune image disponible</p>
                </div>
            </div>
            @endif

            <!-- Description -->
            <div class="bg-dark-100 rounded-xl shadow-lg p-6">
                <h2 class="text-lg font-semibold text-white mb-4 flex items-center">
                    <i class="fas fa-align-left text-primary-500 mr-2"></i>
                    Description
                </h2>
                <div class="text-white whitespace-pre-wrap">
                    {{ $product->description ?? 'Aucune description disponible.' }}
                </div>
            </div>

            <!-- Product Information -->
            <div class="bg-dark-100 rounded-xl shadow-lg p-6">
                <h2 class="text-lg font-semibold text-white mb-4 flex items-center">
                    <i class="fas fa-info-circle text-primary-500 mr-2"></i>
                    Informations détaillées
                </h2>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <!-- Boutique -->
                    <div class="p-4 bg-dark-50 rounded-lg">
                        <div class="text-sm text-gray-400 mb-1">
                            <i class="fas fa-store mr-2"></i>Boutique
                        </div>
                        <div class="font-semibold text-white">{{ $product->shop->name }}</div>
                    </div>

                    <!-- Propriétaire -->
                    <div class="p-4 bg-dark-50 rounded-lg">
                        <div class="text-sm text-gray-400 mb-1">
                            <i class="fas fa-user mr-2"></i>Propriétaire
                        </div>
                        <div class="font-semibold text-white">{{ $product->user->name }}</div>
                    </div>

                    <!-- Catégorie -->
                    <div class="p-4 bg-dark-50 rounded-lg">
                        <div class="text-sm text-gray-400 mb-1">
                            <i class="fas fa-tag mr-2"></i>Catégorie
                        </div>
                        <div class="font-semibold text-white">{{ $product->category->name }}</div>
                    </div>

                    <!-- Sous-catégorie -->
                    <div class="p-4 bg-dark-50 rounded-lg">
                        <div class="text-sm text-gray-400 mb-1">
                            <i class="fas fa-tags mr-2"></i>Sous-catégorie
                        </div>
                        <div class="font-semibold text-white">{{ $product->subcategory->name ?? 'Aucune' }}</div>
                    </div>

                    <!-- Weight Category -->
                    <div class="p-4 bg-dark-50 rounded-lg">
                        <div class="text-sm text-gray-400 mb-1">
                            <i class="fas fa-weight-hanging mr-2"></i>Catégorie de poids
                        </div>
                        <div class="font-semibold text-white">{{ $product->weight_category ?? 'X-small' }}</div>
                    </div>

                    <!-- Slug -->
                    <div class="p-4 bg-dark-50 rounded-lg">
                        <div class="text-sm text-gray-400 mb-1">
                            <i class="fas fa-link mr-2"></i>Slug
                        </div>
                        <div class="font-semibold text-white text-sm">{{ $product->slug }}</div>
                    </div>

                    <!-- Created At -->
                    <div class="p-4 bg-dark-50 rounded-lg">
                        <div class="text-sm text-gray-400 mb-1">
                            <i class="fas fa-calendar-plus mr-2"></i>Date de création
                        </div>
                        <div class="font-semibold text-white">{{ $product->created_at->format('d/m/Y H:i') }}</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Sidebar (1 column) -->
        <div class="space-y-6">
            <!-- Prix -->
            <div class="bg-dark-100 rounded-xl shadow-lg p-6">
                <h2 class="text-lg font-semibold text-white mb-4 flex items-center">
                    <i class="fas fa-dollar-sign text-primary-500 mr-2"></i>
                    Tarification
                </h2>

                <div class="p-4 bg-primary-500/20 rounded-lg border-2 border-primary-500/50">
                    <div class="text-sm text-primary-300 mb-2">
                        @if($product->price_type === 'fixed')
                            <i class="fas fa-tag mr-2"></i>Prix Fixe
                        @else
                            <i class="fas fa-chart-line mr-2"></i>Prix Variable
                        @endif
                    </div>
                    <div class="text-2xl font-bold text-white">{{ $product->formatted_price }}</div>
                </div>

                @if($product->price_type === 'variable')
                <div class="grid grid-cols-2 gap-3 mt-3">
                    <div class="p-3 bg-dark-50 rounded-lg">
                        <div class="text-xs text-gray-400 mb-1">Minimum</div>
                        <div class="font-semibold text-white">{{ number_format($product->min_price, 0, ',', ' ') }} FCFA</div>
                    </div>
                    <div class="p-3 bg-dark-50 rounded-lg">
                        <div class="text-xs text-gray-400 mb-1">Maximum</div>
                        <div class="font-semibold text-white">{{ number_format($product->max_price, 0, ',', ' ') }} FCFA</div>
                    </div>
                </div>
                @endif
            </div>

            <!-- Type de produit -->
            <div class="bg-dark-100 rounded-xl shadow-lg p-6">
                <h2 class="text-lg font-semibold text-white mb-4 flex items-center">
                    <i class="fas fa-box text-primary-500 mr-2"></i>
                    Type de produit
                </h2>

                @if($product->type === 'article')
                <div class="p-4 bg-blue-500/20 rounded-lg border-2 border-blue-500/50">
                    <div class="flex items-center text-white">
                        <i class="fas fa-shopping-bag text-2xl text-blue-400 mr-3"></i>
                        <div>
                            <div class="font-semibold">Article</div>
                            <div class="text-xs text-gray-400">Produit physique</div>
                        </div>
                    </div>
                </div>
                @else
                <div class="p-4 bg-green-500/20 rounded-lg border-2 border-green-500/50">
                    <div class="flex items-center text-white">
                        <i class="fas fa-concierge-bell text-2xl text-green-400 mr-3"></i>
                        <div>
                            <div class="font-semibold">Service</div>
                            <div class="text-xs text-gray-400">Prestation de service</div>
                        </div>
                    </div>
                </div>
                @endif
            </div>

            <!-- Stock -->
            <div class="bg-dark-100 rounded-xl shadow-lg p-6">
                <h2 class="text-lg font-semibold text-white mb-4 flex items-center">
                    <i class="fas fa-warehouse text-primary-500 mr-2"></i>
                    Stock
                </h2>

                <div class="p-4 bg-dark-50 rounded-lg">
                    <div class="flex items-center justify-between">
                        <div>
                            <div class="text-sm text-gray-400 mb-1">Quantité disponible</div>
                            <div class="text-2xl font-bold text-white">{{ $product->stock }}</div>
                        </div>
                        <div class="text-4xl">
                            @if($product->stock > 50)
                                <i class="fas fa-check-circle text-green-500"></i>
                            @elseif($product->stock > 10)
                                <i class="fas fa-exclamation-circle text-yellow-500"></i>
                            @else
                                <i class="fas fa-times-circle text-red-500"></i>
                            @endif
                        </div>
                    </div>

                    <div class="mt-2 text-xs">
                        @if($product->stock > 50)
                            <span class="px-2 py-1 bg-green-500/20 text-green-300 border border-green-500/50 rounded-full">
                                <i class="fas fa-check mr-1"></i>Stock suffisant
                            </span>
                        @elseif($product->stock > 10)
                            <span class="px-2 py-1 bg-yellow-500/20 text-yellow-300 border border-yellow-500/50 rounded-full">
                                <i class="fas fa-exclamation mr-1"></i>Stock faible
                            </span>
                        @else
                            <span class="px-2 py-1 bg-red-500/20 text-red-300 border border-red-500/50 rounded-full">
                                <i class="fas fa-times mr-1"></i>Stock très faible
                            </span>
                        @endif
                    </div>
                </div>
            </div>

            <!-- Statut -->
            <div class="bg-dark-100 rounded-xl shadow-lg p-6">
                <h2 class="text-lg font-semibold text-white mb-4 flex items-center">
                    <i class="fas fa-toggle-on text-primary-500 mr-2"></i>
                    Statut
                </h2>

                @if($product->status === 'active')
                <div class="p-4 bg-green-500/20 rounded-lg border-2 border-green-500/50">
                    <div class="flex items-center text-white">
                        <i class="fas fa-check-circle text-2xl text-green-400 mr-3"></i>
                        <div>
                            <div class="font-semibold">Actif</div>
                            <div class="text-xs text-gray-400">Visible sur la plateforme</div>
                        </div>
                    </div>
                </div>
                @else
                <div class="p-4 bg-gray-500/20 rounded-lg border-2 border-gray-500/50">
                    <div class="flex items-center text-white">
                        <i class="fas fa-times-circle text-2xl text-gray-400 mr-3"></i>
                        <div>
                            <div class="font-semibold">Inactif</div>
                            <div class="text-xs text-gray-400">Masqué de la plateforme</div>
                        </div>
                    </div>
                </div>
                @endif
            </div>

            <!-- Statistics -->
            <div class="bg-dark-100 rounded-xl shadow-lg p-6">
                <h2 class="text-lg font-semibold text-white mb-4 flex items-center">
                    <i class="fas fa-chart-bar text-primary-500 mr-2"></i>
                    Statistiques
                </h2>

                <div class="space-y-3">
                    <div class="flex items-center justify-between p-3 bg-dark-50 rounded-lg">
                        <div class="flex items-center text-gray-400">
                            <i class="fas fa-images mr-2"></i>
                            <span class="text-sm">Images</span>
                        </div>
                        <span class="font-semibold text-white">{{ $product->images->count() }}</span>
                    </div>

                    <div class="flex items-center justify-between p-3 bg-dark-50 rounded-lg">
                        <div class="flex items-center text-gray-400">
                            <i class="fas fa-eye mr-2"></i>
                            <span class="text-sm">Vues</span>
                        </div>
                        <span class="font-semibold text-white">0</span>
                    </div>

                    <div class="flex items-center justify-between p-3 bg-dark-50 rounded-lg">
                        <div class="flex items-center text-gray-400">
                            <i class="fas fa-shopping-cart mr-2"></i>
                            <span class="text-sm">Ventes</span>
                        </div>
                        <span class="font-semibold text-white">0</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
