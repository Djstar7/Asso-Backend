@extends('admin.layouts.app')

@section('content')
<div class="p-6" x-data="{ view: localStorage.getItem('productsView') || 'grid' }">
    <!-- Header -->
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-white">Produits</h1>
            <p class="text-gray-400">Gérez tous les produits de la plateforme</p>
        </div>
        <div class="flex gap-3">
            <!-- View Toggle -->
            <div class="flex bg-dark-100 rounded-lg border border-dark-200 p-1">
                <button @click="view = 'grid'; localStorage.setItem('productsView', 'grid')"
                        :class="view === 'grid' ? 'bg-primary-500 text-white' : 'text-gray-400 hover:text-white'"
                        class="px-3 py-1.5 rounded transition-all">
                    <i class="fas fa-th"></i>
                </button>
                <button @click="view = 'list'; localStorage.setItem('productsView', 'list')"
                        :class="view === 'list' ? 'bg-primary-500 text-white' : 'text-gray-400 hover:text-white'"
                        class="px-3 py-1.5 rounded transition-all">
                    <i class="fas fa-list"></i>
                </button>
            </div>
            <a href="{{ route('admin.products.create') }}"
               class="px-4 py-2 bg-gradient-to-r from-primary-500 to-primary-600 text-white rounded-lg hover:shadow-lg transition-all">
                <i class="fas fa-plus mr-2"></i>
                Nouveau Produit
            </a>
        </div>
    </div>

    @if(session('success'))
        <div class="mb-6 p-4 bg-green-900/20 border-l-4 border-green-500 rounded">
            <p class="text-green-300"><i class="fas fa-check-circle mr-2"></i>{{ session('success') }}</p>
        </div>
    @endif

    <!-- Filters -->
    <div class="bg-dark-100 rounded-xl shadow-lg p-6 mb-6 border border-dark-200">
        <form method="GET" class="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-6 gap-4">
            <input type="text" name="search" value="{{ request('search') }}" placeholder="Rechercher..." class="px-4 py-2 bg-dark-50 border border-dark-300 text-white placeholder-gray-400 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500">
            <select name="shop_id" class="px-4 py-2 bg-dark-50 border border-dark-300 text-white rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500">
                <option value="">Toutes les boutiques</option>
                @foreach($shops as $shop)
                    <option value="{{ $shop->id }}" {{ request('shop_id') == $shop->id ? 'selected' : '' }}>{{ $shop->name }}</option>
                @endforeach
            </select>
            <select name="category_id" class="px-4 py-2 bg-dark-50 border border-dark-300 text-white rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500">
                <option value="">Toutes catégories</option>
                @foreach($categories as $category)
                    <option value="{{ $category->id }}" {{ request('category_id') == $category->id ? 'selected' : '' }}>{{ $category->name }}</option>
                @endforeach
            </select>
            <select name="type" class="px-4 py-2 bg-dark-50 border border-dark-300 text-white rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500">
                <option value="">Tous types</option>
                <option value="article" {{ request('type') == 'article' ? 'selected' : '' }}>Article</option>
                <option value="service" {{ request('type') == 'service' ? 'selected' : '' }}>Service</option>
            </select>
            <select name="status" class="px-4 py-2 bg-dark-50 border border-dark-300 text-white rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500">
                <option value="">Tous statuts</option>
                <option value="active" {{ request('status') == 'active' ? 'selected' : '' }}>Actif</option>
                <option value="inactive" {{ request('status') == 'inactive' ? 'selected' : '' }}>Inactif</option>
            </select>
            <button type="submit" class="px-4 py-2 bg-gradient-to-r from-primary-500 to-primary-600 text-white rounded-lg hover:shadow-lg transition-all"><i class="fas fa-filter mr-2"></i>Filtrer</button>
        </form>
    </div>

    <!-- Products Container -->
    <div>
        <!-- Grid View -->
        <div x-show="view === 'grid'" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
        @forelse($products as $product)
            <div class="bg-dark-100 rounded-xl shadow-lg overflow-hidden hover:shadow-2xl hover:shadow-primary-500/20 transition-all border border-dark-200 hover:border-primary-500/50">
                <div class="relative h-48 bg-dark-200">
                    @if($product->primaryImage)
                        <img src="{{ asset($product->primaryImage->image_path) }}" alt="{{ $product->name }}" class="w-full h-full object-cover">
                    @else
                        <div class="w-full h-full flex items-center justify-center text-gray-500">
                            <i class="fas fa-image text-6xl"></i>
                        </div>
                    @endif
                    <div class="absolute top-2 right-2 flex gap-2">
                        <span class="px-2 py-1 text-xs rounded-full backdrop-blur-sm {{ $product->status == 'active' ? 'bg-green-500/20 text-green-300 border border-green-500/50' : 'bg-gray-500/20 text-gray-300 border border-gray-500/50' }}">
                            {{ $product->status == 'active' ? 'Actif' : 'Inactif' }}
                        </span>
                        <span class="px-2 py-1 text-xs rounded-full backdrop-blur-sm bg-blue-500/20 text-blue-300 border border-blue-500/50">
                            {{ $product->type == 'article' ? 'Article' : 'Service' }}
                        </span>
                    </div>
                </div>
                <div class="p-4">
                    <h3 class="font-semibold text-white mb-1 truncate">{{ $product->name }}</h3>
                    <p class="text-xs text-gray-400 mb-2">{{ $product->shop->name }}</p>

                    <!-- Rating Stars -->
                    <div class="flex items-center gap-2 mb-3">
                        <div class="flex items-center">
                            @php
                                $rating = $product->average_rating;
                                $fullStars = floor($rating);
                                $halfStar = ($rating - $fullStars) >= 0.5;
                                $emptyStars = 5 - $fullStars - ($halfStar ? 1 : 0);
                            @endphp
                            @for($i = 0; $i < $fullStars; $i++)
                                <i class="fas fa-star text-yellow-400 text-sm"></i>
                            @endfor
                            @if($halfStar)
                                <i class="fas fa-star-half-alt text-yellow-400 text-sm"></i>
                            @endif
                            @for($i = 0; $i < $emptyStars; $i++)
                                <i class="far fa-star text-gray-500 text-sm"></i>
                            @endfor
                        </div>
                        <span class="text-xs text-gray-400">({{ $product->reviews_count }})</span>
                    </div>

                    <div class="flex items-center gap-2 mb-2">
                        <span class="px-2 py-0.5 text-xs rounded-full bg-purple-500/20 text-purple-300 border border-purple-500/50">
                            <i class="fas fa-weight-hanging mr-1"></i>{{ $product->weight_category ?? 'X-small' }}
                        </span>
                    </div>

                    <div class="flex items-center justify-between mb-3">
                        <span class="text-primary-500 font-bold">{{ $product->formatted_price }}</span>
                        <span class="text-xs text-gray-400">Stock: {{ $product->stock }}</span>
                    </div>
                    <div class="flex gap-2">
                        <a href="{{ route('admin.products.show', $product) }}" class="flex-1 px-3 py-2 bg-blue-600 text-white text-sm rounded-lg hover:bg-blue-700 text-center transition-colors">
                            <i class="fas fa-eye"></i>
                        </a>
                        <a href="{{ route('admin.products.edit', $product) }}" class="flex-1 px-3 py-2 bg-gradient-to-r from-primary-500 to-primary-600 text-white text-sm rounded-lg hover:shadow-lg text-center transition-all">
                            <i class="fas fa-edit"></i>
                        </a>
                        <form action="{{ route('admin.products.destroy', $product) }}" method="POST" onsubmit="return confirm('Supprimer ce produit ?');" class="flex-1">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="w-full px-3 py-2 bg-red-600 text-white text-sm rounded-lg hover:bg-red-700 transition-colors">
                                <i class="fas fa-trash"></i>
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        @empty
            <div class="col-span-full text-center py-12 text-gray-400">
                <i class="fas fa-box-open text-6xl text-gray-600 mb-4"></i>
                <p class="text-lg">Aucun produit trouvé</p>
            </div>
        @endforelse
        </div>

        <!-- List View -->
        <div x-show="view === 'list'" class="space-y-4">
        @forelse($products as $product)
            <div class="bg-dark-100 rounded-xl shadow-lg overflow-hidden hover:shadow-xl transition-all border border-dark-200 hover:border-primary-500/50">
                <div class="flex items-center p-4 gap-4">
                    <!-- Image -->
                    <div class="flex-shrink-0 w-24 h-24 bg-dark-200 rounded-lg overflow-hidden">
                        @if($product->primaryImage)
                            <img src="{{ asset($product->primaryImage->image_path) }}" alt="{{ $product->name }}" class="w-full h-full object-cover">
                        @else
                            <div class="w-full h-full flex items-center justify-center text-gray-500">
                                <i class="fas fa-image text-3xl"></i>
                            </div>
                        @endif
                    </div>

                    <!-- Product Info -->
                    <div class="flex-1 min-w-0">
                        <div class="flex items-start justify-between gap-4">
                            <div class="flex-1">
                                <h3 class="font-semibold text-white text-lg mb-1">{{ $product->name }}</h3>
                                <p class="text-sm text-gray-400 mb-2">{{ $product->shop->name }}</p>

                                <!-- Rating Stars -->
                                <div class="flex items-center gap-2 mb-2">
                                    <div class="flex items-center">
                                        @php
                                            $rating = $product->average_rating;
                                            $fullStars = floor($rating);
                                            $halfStar = ($rating - $fullStars) >= 0.5;
                                            $emptyStars = 5 - $fullStars - ($halfStar ? 1 : 0);
                                        @endphp
                                        @for($i = 0; $i < $fullStars; $i++)
                                            <i class="fas fa-star text-yellow-400"></i>
                                        @endfor
                                        @if($halfStar)
                                            <i class="fas fa-star-half-alt text-yellow-400"></i>
                                        @endif
                                        @for($i = 0; $i < $emptyStars; $i++)
                                            <i class="far fa-star text-gray-500"></i>
                                        @endfor
                                    </div>
                                    <span class="text-sm text-gray-400">({{ $product->reviews_count }} avis)</span>
                                </div>

                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="px-2 py-1 text-xs rounded-full {{ $product->status == 'active' ? 'bg-green-500/20 text-green-300 border border-green-500/50' : 'bg-gray-500/20 text-gray-300 border border-gray-500/50' }}">
                                        {{ $product->status == 'active' ? 'Actif' : 'Inactif' }}
                                    </span>
                                    <span class="px-2 py-1 text-xs rounded-full bg-blue-500/20 text-blue-300 border border-blue-500/50">
                                        {{ $product->type == 'article' ? 'Article' : 'Service' }}
                                    </span>
                                    <span class="px-2 py-1 text-xs rounded-full bg-purple-500/20 text-purple-300 border border-purple-500/50">
                                        <i class="fas fa-weight-hanging mr-1"></i>{{ $product->weight_category ?? 'X-small' }}
                                    </span>
                                    <span class="text-xs text-gray-400">
                                        <i class="fas fa-box mr-1"></i>Stock: {{ $product->stock }}
                                    </span>
                                </div>
                            </div>

                            <!-- Price -->
                            <div class="text-right flex-shrink-0">
                                <div class="text-2xl font-bold text-primary-500 mb-2">{{ $product->formatted_price }}</div>
                                <div class="flex gap-2">
                                    <a href="{{ route('admin.products.show', $product) }}" class="px-3 py-1.5 bg-blue-600 text-white text-sm rounded-lg hover:bg-blue-700 transition-colors" title="Voir">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <a href="{{ route('admin.products.edit', $product) }}" class="px-3 py-1.5 bg-gradient-to-r from-primary-500 to-primary-600 text-white text-sm rounded-lg hover:shadow-lg transition-all" title="Modifier">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <form action="{{ route('admin.products.destroy', $product) }}" method="POST" onsubmit="return confirm('Supprimer ce produit ?');" class="inline">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="px-3 py-1.5 bg-red-600 text-white text-sm rounded-lg hover:bg-red-700 transition-colors" title="Supprimer">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        @empty
            <div class="text-center py-12 text-gray-400 bg-dark-100 rounded-xl">
                <i class="fas fa-box-open text-6xl text-gray-600 mb-4"></i>
                <p class="text-lg">Aucun produit trouvé</p>
            </div>
        @endforelse
        </div>
    </div>

    <!-- Pagination -->
    <div class="mt-6">
        {{ $products->links() }}
    </div>
</div>
@endsection
