@extends('admin.layouts.app')

@section('content')
<div class="p-6">
    <!-- Header -->
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-white">{{ $libraryMode ? 'Modifier le livre' : 'Modifier le Produit' }}</h1>
            <p class="text-gray-400">Mettez à jour les informations du produit</p>
        </div>
        <a href="{{ $libraryMode ? route('admin.library.books.index') : route('admin.products.index') }}" class="px-4 py-2 bg-gray-500 text-white rounded-lg hover:bg-gray-600 transition-all">
            <i class="fas fa-arrow-left mr-2"></i>
            Retour
        </a>
    </div>

    <form action="{{ route('admin.products.update', $product) }}" method="POST" enctype="multipart/form-data">
        @csrf
        @method('PUT')

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Main Form (2 columns) -->
            <div class="lg:col-span-2 space-y-6">
                <!-- Informations de base -->
                <div class="bg-dark-100 rounded-xl shadow-lg p-6">
                    <h2 class="text-lg font-semibold text-white mb-4 flex items-center">
                        <i class="fas fa-info-circle text-primary-500 mr-2"></i>
                        Informations de base
                    </h2>

                    <!-- Shop Selection -->
                    <div class="mb-4">
                        <label for="shop_id" class="block text-sm font-medium text-white mb-2">
                            Boutique <span class="text-red-500">*</span>
                        </label>
                        <select name="shop_id" id="shop_id" required class="w-full px-4 py-2 bg-dark-50 border border-dark-300 rounded-lg text-white focus:ring-2 focus:ring-primary-500 focus:border-transparent">
                            <option value="">Sélectionnez une boutique</option>
                            @foreach($shops as $shop)
                                <option value="{{ $shop->id }}" {{ old('shop_id', $product->shop_id) == $shop->id ? 'selected' : '' }}>
                                    {{ $shop->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    @if(auth()->user()->isAdmin() && $managers->isNotEmpty())
                        <div class="mb-4">
                            <label for="created_by" class="block text-sm font-medium text-white mb-2">Gestionnaire responsable</label>
                            <select name="created_by" id="created_by" class="w-full px-4 py-2 bg-dark-50 border border-dark-300 rounded-lg text-white focus:ring-2 focus:ring-primary-500 focus:border-transparent">
                                <option value="">Aucun (réservé à l'admin)</option>
                                @foreach($managers as $manager)
                                    <option value="{{ $manager->id }}" {{ (string) old('created_by', $product->created_by) === (string) $manager->id ? 'selected' : '' }}>
                                        {{ $manager->first_name }} {{ $manager->last_name }} ({{ $manager->email }})
                                    </option>
                                @endforeach
                            </select>
                            <p class="text-xs text-gray-400 mt-1">Un gestionnaire ne voit, ne modifie et ne supprime que les produits qui lui sont rattachés.</p>
                        </div>
                    @endif

                    <!-- Product Name / Description (FR + EN) -->
                    <x-admin.translatable class="mb-4" name="name" label="Nom du produit" :model="$product" required placeholder="Ex: iPhone 15 Pro" />
                    <x-admin.translatable class="mb-4" name="description" label="Description" :model="$product" type="textarea" placeholder="Décrivez le produit..." />
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <x-admin.translatable name="characteristics" label="Caractéristiques" :model="$product" type="textarea" placeholder="Matière, puissance, dimensions…" />
                        <x-admin.translatable name="commercial_information" label="Informations commerciales" :model="$product" type="textarea" placeholder="Garantie, délai, conditionnement…" />
                    </div>
                </div>

                <!-- Categories -->
                <div class="bg-dark-100 rounded-xl shadow-lg p-6">
                    <h2 class="text-lg font-semibold text-white mb-4 flex items-center">
                        <i class="fas fa-tags text-primary-500 mr-2"></i>
                        Catégories
                    </h2>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <!-- Category -->
                        <div>
                            <label for="category_id" class="block text-sm font-medium text-white mb-2">
                                Catégorie <span class="text-red-500">*</span>
                            </label>
                            <select name="category_id" id="category_id" required onchange="loadSubcategories(this.value)"
                                    class="w-full px-4 py-2 bg-dark-50 border border-dark-300 rounded-lg text-white focus:ring-2 focus:ring-primary-500 focus:border-transparent">
                                <option value="">Sélectionnez une catégorie</option>
                                @foreach($categories as $category)
                                    <option value="{{ $category->id }}" {{ old('category_id', $product->category_id) == $category->id ? 'selected' : '' }}>
                                        {{ $category->name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <!-- Subcategory -->
                        <div>
                            <label for="subcategory_id" class="block text-sm font-medium text-white mb-2">
                                Sous-catégorie
                            </label>
                            <select name="subcategory_id" id="subcategory_id"
                                    class="w-full px-4 py-2 bg-dark-50 border border-dark-300 rounded-lg text-white focus:ring-2 focus:ring-primary-500 focus:border-transparent">
                                <option value="">Aucune sous-catégorie</option>
                                @foreach($subcategories as $subcategory)
                                    @if($subcategory->category_id == old('category_id', $product->category_id))
                                        <option value="{{ $subcategory->id }}" {{ old('subcategory_id', $product->subcategory_id) == $subcategory->id ? 'selected' : '' }}>
                                            {{ $subcategory->name }}
                                        </option>
                                    @endif
                                @endforeach
                            </select>
                        </div>
                    </div>
                </div>

                @if($libraryMode)
                    @include('admin.products._book')
                @endif

                @include('admin.products._wholesale')

                <!-- Prix -->
                <div class="bg-dark-100 rounded-xl shadow-lg p-6">
                    <h2 class="text-lg font-semibold text-white mb-4 flex items-center">
                        <i class="fas fa-dollar-sign text-primary-500 mr-2"></i>
                        Tarification
                    </h2>

                    <div class="mb-4">
                        <label class="block text-sm font-medium text-white mb-2">Devise du prix <span class="text-red-500">*</span></label>
                        <select name="currency" required class="w-full px-4 py-2 bg-dark-50 border border-dark-300 rounded-lg">
                            @foreach($currencies as $currency)<option value="{{ $currency->code }}" {{ old('currency', $product->currency ?? 'XAF') === $currency->code ? 'selected' : '' }}>{{ $currency->code }} — {{ $currency->name }}</option>@endforeach
                        </select>
                    </div>

                    <!-- Price Type -->
                    <div class="mb-4" id="price_type_field">
                        <label class="block text-sm font-medium text-white mb-3">
                            Type de prix <span class="text-red-500">*</span>
                        </label>
                        <div class="flex gap-6">
                            <label class="flex items-center cursor-pointer">
                                <input type="radio" name="price_type" value="fixed" {{ old('price_type', $product->price_type) == 'fixed' ? 'checked' : '' }}
                                       onchange="togglePriceFields()" class="mr-2 text-primary-500 focus:ring-primary-500">
                                <span class="text-white"><i class="fas fa-tag mr-2 text-primary-500"></i>Prix Fixe</span>
                            </label>
                            <label class="flex items-center cursor-pointer">
                                <input type="radio" name="price_type" value="variable" {{ old('price_type', $product->price_type) == 'variable' ? 'checked' : '' }}
                                       onchange="togglePriceFields()" class="mr-2 text-primary-500 focus:ring-primary-500">
                                <span class="text-white"><i class="fas fa-chart-line mr-2 text-primary-500"></i>Prix Variable</span>
                            </label>
                        </div>
                    </div>

                    <!-- Fixed Price Field -->
                    <div id="fixed_price_field" class="{{ old('price_type', $product->price_type) == 'variable' ? 'hidden' : '' }}">
                        <label for="price" class="block text-sm font-medium text-white mb-2">
                            Prix (FCFA) <span class="text-red-500">*</span>
                        </label>
                        <input type="number" name="price" id="price" step="0.01" min="0" value="{{ old('price', $product->price) }}"
                               class="w-full px-4 py-2 bg-dark-50 border border-dark-300 rounded-lg text-white focus:ring-2 focus:ring-primary-500 focus:border-transparent"
                               placeholder="Ex: 10000">
                    </div>

                    <!-- Variable Price Fields -->
                    <div id="variable_price_fields" class="{{ old('price_type', $product->price_type) == 'fixed' ? 'hidden' : '' }}">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label for="min_price" class="block text-sm font-medium text-white mb-2">
                                    Prix Minimum (FCFA) <span class="text-red-500">*</span>
                                </label>
                                <input type="number" name="min_price" id="min_price" step="0.01" min="0" value="{{ old('min_price', $product->min_price) }}"
                                       class="w-full px-4 py-2 bg-dark-50 border border-dark-300 rounded-lg text-white focus:ring-2 focus:ring-primary-500 focus:border-transparent"
                                       placeholder="Ex: 5000">
                            </div>
                            <div>
                                <label for="max_price" class="block text-sm font-medium text-white mb-2">
                                    Prix Maximum (FCFA) <span class="text-red-500">*</span>
                                </label>
                                <input type="number" name="max_price" id="max_price" step="0.01" min="0" value="{{ old('max_price', $product->max_price) }}"
                                       class="w-full px-4 py-2 bg-dark-50 border border-dark-300 rounded-lg text-white focus:ring-2 focus:ring-primary-500 focus:border-transparent"
                                       placeholder="Ex: 15000">
                            </div>
                        </div>
                    </div>
                </div>

                @include('admin.products._variants')

                @include('admin.products._images')

                @include('admin.products._video')
            </div>

            <!-- Sidebar (1 column) -->
            <div class="space-y-6">
                <!-- Product Type -->
                <div class="bg-dark-100 rounded-xl shadow-lg p-6">
                    <h2 class="text-lg font-semibold text-white mb-4 flex items-center">
                        <i class="fas fa-box text-primary-500 mr-2"></i>
                        Type de produit
                    </h2>

                    <div class="space-y-3">
                        <label class="flex items-center p-3 border border-dark-200 rounded-lg cursor-pointer hover:bg-dark-50">
                            <input type="radio" name="type" value="article" {{ old('type', $product->type) == 'article' ? 'checked' : '' }}
                                   class="mr-3 text-primary-500 focus:ring-primary-500">
                            <div>
                                <div class="font-medium text-white"><i class="fas fa-shopping-bag mr-2 text-blue-500"></i>Article</div>
                                <div class="text-xs text-gray-400">Produit physique</div>
                            </div>
                        </label>

                        <label class="flex items-center p-3 border border-dark-200 rounded-lg cursor-pointer hover:bg-dark-50">
                            <input type="radio" name="type" value="service" {{ old('type', $product->type) == 'service' ? 'checked' : '' }}
                                   class="mr-3 text-primary-500 focus:ring-primary-500">
                            <div>
                                <div class="font-medium text-white"><i class="fas fa-concierge-bell mr-2 text-green-500"></i>Service</div>
                                <div class="text-xs text-gray-400">Prestation de service</div>
                            </div>
                        </label>
                    </div>
                </div>

                <!-- Poids et origine -->
                <div class="bg-dark-100 rounded-xl shadow-lg p-6">
                    <h2 class="text-lg font-semibold text-white mb-4 flex items-center">
                        <i class="fas fa-weight-hanging text-primary-500 mr-2"></i>
                        Poids et origine
                    </h2>

                    @include('admin.products._dimensions')

                    <div class="mb-4" id="product_weight_field">
                        <label for="weight" class="block text-sm font-medium text-white mb-2">
                            Poids unitaire (kg) <span class="text-red-500">*</span>
                        </label>
                        <input type="number" name="weight" id="weight" value="{{ old('weight', $product->weight) }}"
                               min="0.001" max="999999" step="0.001" inputmode="decimal"
                               {{ old('type', $product->type) === 'article' ? 'required' : '' }}
                               class="w-full px-4 py-2 bg-dark-50 border border-dark-300 rounded-lg text-white focus:ring-2 focus:ring-primary-500 focus:border-transparent @error('weight') border-red-500 @enderror"
                               placeholder="Ex : 2.5">
                        <p class="text-xs text-gray-400 mt-2">
                            Saisie en kilogrammes : 1 = 1 kg, 0,001 = 1 g. Le poids livré est le poids unitaire × la quantité (2 articles de 1 kg = 2 kg).
                        </p>
                        @error('weight')<p class="mt-1 text-sm text-red-400">{{ $message }}</p>@enderror
                    </div>





                    <!-- Origine du produit -->
                    <div class="mt-4">
                        <label class="block text-sm font-medium text-white mb-2">
                            <i class="fas fa-globe text-primary-500 mr-1"></i>
                            Origine du produit
                        </label>
                        @php $currentOrigin = old('origin_country') !== null ? \App\Models\Product::originChoice(old('origin_country')) : $product->origin(); @endphp
                        <select name="origin_country" class="w-full px-4 py-2 bg-dark-50 border border-dark-300 rounded-lg focus:ring-2 focus:ring-primary-500 @error('origin_country') border-red-500 @enderror">
                            <option value="{{ \App\Models\Product::ORIGIN_IMPORTED }}" @selected($currentOrigin === \App\Models\Product::ORIGIN_IMPORTED)>Produit importé</option>
                            <option value="{{ \App\Models\Product::ORIGIN_LOCAL }}" @selected($currentOrigin === \App\Models\Product::ORIGIN_LOCAL)>Produit local</option>
                            @foreach($importCountries as $country)
                                <option value="{{ $country->code }}" @selected($currentOrigin === $country->code)>
                                    {{ $country->flag }} Grossiste {{ $country->name }}
                                </option>
                            @endforeach
                        </select>
                        <p class="text-xs text-gray-400 mt-2">Produit importé et produit local restent dans le menu principal de l'app. Un grossiste (Chine, Dubaï, Turquie…) fait passer le produit dans l'onglet « Import ».</p>
                        @error('origin_country')<p class="mt-1 text-sm text-red-400">{{ $message }}</p>@enderror
                    </div>
                </div>

                <!-- Stock -->
                <div class="bg-dark-100 rounded-xl shadow-lg p-6">
                    <h2 class="text-lg font-semibold text-white mb-4 flex items-center">
                        <i class="fas fa-warehouse text-primary-500 mr-2"></i>
                        Stock
                    </h2>

                    <div>
                        <label for="stock" class="block text-sm font-medium text-white mb-2">
                            Quantité en stock <span class="text-red-500">*</span>
                        </label>
                        <input type="number" name="stock" id="stock" min="0" value="{{ old('stock', $product->stock) }}" required
                               class="w-full px-4 py-2 bg-dark-50 border border-dark-300 rounded-lg text-white focus:ring-2 focus:ring-primary-500 focus:border-transparent"
                               placeholder="Ex: 100">
                    </div>
                </div>

                @include('admin.products._free_delivery')
                @include('admin.products._delivery_delay')
                @include('admin.products._deposit')

                <!-- Status -->
                <div class="bg-dark-100 rounded-xl shadow-lg p-6">
                    <h2 class="text-lg font-semibold text-white mb-4 flex items-center">
                        <i class="fas fa-toggle-on text-primary-500 mr-2"></i>
                        Statut
                    </h2>

                    <div class="space-y-3">
                        <label class="flex items-center p-3 border border-dark-200 rounded-lg cursor-pointer hover:bg-dark-50">
                            <input type="radio" name="status" value="active" {{ old('status', $product->status) == 'active' ? 'checked' : '' }}
                                   class="mr-3 text-primary-500 focus:ring-primary-500">
                            <div>
                                <div class="font-medium text-white"><i class="fas fa-check-circle mr-2 text-green-500"></i>Actif</div>
                                <div class="text-xs text-gray-400">Visible sur la plateforme</div>
                            </div>
                        </label>

                        <label class="flex items-center p-3 border border-dark-200 rounded-lg cursor-pointer hover:bg-dark-50">
                            <input type="radio" name="status" value="inactive" {{ old('status', $product->status) == 'inactive' ? 'checked' : '' }}
                                   class="mr-3 text-primary-500 focus:ring-primary-500">
                            <div>
                                <div class="font-medium text-white"><i class="fas fa-times-circle mr-2 text-gray-500"></i>Inactif</div>
                                <div class="text-xs text-gray-400">Masqué de la plateforme</div>
                            </div>
                        </label>
                    </div>
                </div>

                <!-- Submit Button -->
                <button type="submit" class="w-full px-6 py-3 bg-orange-500 text-white font-semibold rounded-lg hover:bg-orange-600 transition-all shadow-lg">
                    <i class="fas fa-save mr-2"></i>
                    Mettre à jour le produit
                </button>
            </div>
        </div>
    </form>
</div>

<script>
(function() {
    'use strict';

    // Toggle price fields based on price type
    window.togglePriceFields = function() {
        const priceType = document.querySelector('input[name="price_type"]:checked').value;
        const fixedField = document.getElementById('fixed_price_field');
        const variableFields = document.getElementById('variable_price_fields');

        if (priceType === 'fixed') {
            fixedField.classList.remove('hidden');
            variableFields.classList.add('hidden');
            document.getElementById('price').required = true;
            document.getElementById('min_price').required = false;
            document.getElementById('max_price').required = false;
        } else {
            fixedField.classList.add('hidden');
            variableFields.classList.remove('hidden');
            document.getElementById('price').required = false;
            document.getElementById('min_price').required = true;
            document.getElementById('max_price').required = true;
        }
    };

    // Load subcategories based on category
    window.loadSubcategories = function(categoryId) {
        const subcategorySelect = document.getElementById('subcategory_id');

        if (!categoryId) {
            subcategorySelect.innerHTML = '<option value="">Aucune sous-catégorie</option>';
            return;
        }

        fetch(`/admin/categories/${categoryId}/subcategories`)
            .then(response => response.json())
            .then(data => {
                subcategorySelect.innerHTML = '<option value="">Aucune sous-catégorie</option>';
                data.forEach(subcategory => {
                    const option = document.createElement('option');
                    option.value = subcategory.id;
                    option.textContent = subcategory.name;
                    subcategorySelect.appendChild(option);
                });

                    })
            .catch(error => console.error('Error loading subcategories:', error));
    };

    // P4 : poids réel obligatoire pour tout article, masqué pour un service. En gros, le
    // poids d'une pièce se déduit du carton (_wholesale).
    function syncImportWeightSection() {
        const field = document.getElementById('product_weight_field');
        const isArticle = document.querySelector('input[name="type"]:checked')?.value === 'article';
        const wholesale = document.getElementById('is_wholesale')?.checked
            && document.getElementById('wholesale_section')?.style.display !== 'none';
        if (!field) return;
        const needed = isArticle && !wholesale;
        field.classList.toggle('hidden', !needed);
        const weight = document.getElementById('weight');
        if (weight) {
            weight.disabled = !needed;
            weight.required = needed;
        }
    }
    window.syncImportWeightSection = syncImportWeightSection;

    // Initialize on page load
    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('input[name="type"]').forEach(input => input.addEventListener('change', syncImportWeightSection));
        togglePriceFields();

        syncImportWeightSection();
    });
})();
</script>
@endsection
