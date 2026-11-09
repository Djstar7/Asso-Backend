@extends('admin.layouts.app')

@section('content')
<div class="p-6">
    <!-- Header -->
    <div class="mb-6">
        <div class="flex items-center gap-3">
            <a href="{{ $libraryMode ? route('admin.library.books.index') : route('admin.products.index') }}" class="text-gray-400 hover:text-primary-600 transition-colors">
                <i class="fas fa-arrow-left"></i>
            </a>
            <div>
                <h1 class="text-2xl font-bold text-white">{{ $libraryMode ? 'Ajouter un livre' : 'Créer un Produit' }}</h1>
                <p class="text-gray-400">Ajoutez un nouveau produit à la plateforme</p>
            </div>
        </div>
    </div>
    <!-- Form -->
    <form action="{{ route('admin.products.store') }}" method="POST" enctype="multipart/form-data">
        @csrf

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Main Form -->
            <div class="lg:col-span-2 space-y-6">
                <!-- Basic Info Card -->
                <div class="bg-dark-100 rounded-xl shadow-lg p-6">
                    <h3 class="text-lg font-bold text-white mb-4 flex items-center">
                        <i class="fas fa-info-circle text-primary-500 mr-2"></i>
                        Informations de Base
                    </h3>

                    <div class="space-y-4">
                        <!-- Shop Selection -->
                        <div>
                            <label class="block text-sm font-medium text-white mb-2">
                                <i class="fas fa-store text-primary-500 mr-1"></i>
                                Boutique <span class="text-red-500">*</span>
                            </label>
                            <select name="shop_id" required class="w-full px-4 py-2 bg-dark-50 border border-dark-300 rounded-lg focus:ring-2 focus:ring-primary-500 @error('shop_id') border-red-500 @enderror">
                                <option value="">Sélectionnez une boutique</option>
                                @foreach($shops as $shop)
                                    <option value="{{ $shop->id }}" {{ old('shop_id') == $shop->id ? 'selected' : '' }}>{{ $shop->name }}</option>
                                @endforeach
                            </select>
                            @error('shop_id')<p class="mt-1 text-sm text-red-400">{{ $message }}</p>@enderror
                        </div>

                        <!-- Product Name / Description (FR + EN) -->
                        <x-admin.translatable class="mb-4" name="name" label="Nom du produit" :model="null" required placeholder="Ex: iPhone 15 Pro" />
                        <x-admin.translatable class="mb-4" name="description" label="Description" :model="null" type="textarea" placeholder="Décrivez le produit..." />
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <x-admin.translatable name="characteristics" label="Caractéristiques" :model="null" type="textarea" placeholder="Matière, puissance, dimensions…" />
                            <x-admin.translatable name="commercial_information" label="Informations commerciales" :model="null" type="textarea" placeholder="Garantie, délai, conditionnement…" />
                        </div>
                    </div>
                </div>

                <!-- Category Card -->
                <div class="bg-dark-100 rounded-xl shadow-lg p-6">
                    <h3 class="text-lg font-bold text-white mb-4 flex items-center">
                        <i class="fas fa-th-large text-primary-500 mr-2"></i>
                        Catégorisation
                    </h3>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <!-- Category -->
                        <div>
                            <label class="block text-sm font-medium text-white mb-2">
                                Catégorie <span class="text-red-500">*</span>
                            </label>
                            <select name="category_id" id="category_id" required onchange="loadSubcategories(this.value)"
                                    class="w-full px-4 py-2 bg-dark-50 border border-dark-300 rounded-lg focus:ring-2 focus:ring-primary-500 @error('category_id') border-red-500 @enderror">
                                <option value="">Sélectionnez</option>
                                @foreach($categories as $category)
                                    <option value="{{ $category->id }}" {{ old('category_id') == $category->id ? 'selected' : '' }}>{{ $category->name }}</option>
                                @endforeach
                            </select>
                            @error('category_id')<p class="mt-1 text-sm text-red-400">{{ $message }}</p>@enderror
                        </div>

                        <!-- Subcategory -->
                        <div>
                            <label class="block text-sm font-medium text-white mb-2">
                                Sous-catégorie
                            </label>
                            <select name="subcategory_id" id="subcategory_id"
                                    class="w-full px-4 py-2 bg-dark-50 border border-dark-300 rounded-lg focus:ring-2 focus:ring-primary-500 @error('subcategory_id') border-red-500 @enderror">
                                <option value="">Sélectionnez d'abord une catégorie</option>
                            </select>
                            @error('subcategory_id')<p class="mt-1 text-sm text-red-400">{{ $message }}</p>@enderror
                        </div>
                    </div>
                </div>

                @if($libraryMode)
                    @include('admin.products._book')
                @endif

                @include('admin.products._wholesale')

                <!-- Pricing Card -->
                <div class="bg-dark-100 rounded-xl shadow-lg p-6">
                    <h3 class="text-lg font-bold text-white mb-4 flex items-center">
                        <i class="fas fa-dollar-sign text-primary-500 mr-2"></i>
                        Tarification
                    </h3>

                    <div class="mb-4">
                        <label class="block text-sm font-medium text-white mb-2">Devise du prix <span class="text-red-500">*</span></label>
                        <select name="currency" required class="w-full px-4 py-2 bg-dark-50 border border-dark-300 rounded-lg">
                            @foreach($currencies as $currency)<option value="{{ $currency->code }}" {{ old('currency', 'XAF') === $currency->code ? 'selected' : '' }}>{{ $currency->code }} — {{ $currency->name }}</option>@endforeach
                        </select>
                    </div>

                    <!-- Price Type -->
                    <div class="mb-4" id="price_type_field">
                        <label class="block text-sm font-medium text-white mb-2">
                            Type de Prix <span class="text-red-500">*</span>
                        </label>
                        <div class="flex gap-6">
                            <label class="flex items-center cursor-pointer">
                                <input type="radio" name="price_type" value="fixed" {{ old('price_type', 'fixed') == 'fixed' ? 'checked' : '' }}
                                       onchange="togglePriceFields()" class="w-4 h-4 text-primary-600">
                                <span class="ml-2"><i class="fas fa-tag text-green-500 mr-1"></i> Prix Fixe</span>
                            </label>
                            <label class="flex items-center cursor-pointer">
                                <input type="radio" name="price_type" value="variable" {{ old('price_type') == 'variable' ? 'checked' : '' }}
                                       onchange="togglePriceFields()" class="w-4 h-4 text-primary-600">
                                <span class="ml-2"><i class="fas fa-chart-line text-blue-500 mr-1"></i> Prix Variable</span>
                            </label>
                        </div>
                    </div>

                    <!-- Price Fields -->
                    <div id="fixed_price_field" class="grid grid-cols-1 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-white mb-2">Prix (FCFA) <span class="text-red-500">*</span></label>
                            <input type="number" name="price" value="{{ old('price') }}" min="0" step="0.01"
                                   class="w-full px-4 py-2 bg-dark-50 border border-dark-300 rounded-lg focus:ring-2 focus:ring-primary-500"
                                   placeholder="10000">
                            @error('price')<p class="mt-1 text-sm text-red-400">{{ $message }}</p>@enderror
                        </div>
                    </div>

                    <div id="variable_price_fields" class="grid grid-cols-2 gap-4 hidden">
                        <div>
                            <label class="block text-sm font-medium text-white mb-2">Prix Min (FCFA) <span class="text-red-500">*</span></label>
                            <input type="number" name="min_price" value="{{ old('min_price') }}" min="0" step="0.01"
                                   class="w-full px-4 py-2 bg-dark-50 border border-dark-300 rounded-lg focus:ring-2 focus:ring-primary-500"
                                   placeholder="5000">
                            @error('min_price')<p class="mt-1 text-sm text-red-400">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-white mb-2">Prix Max (FCFA) <span class="text-red-500">*</span></label>
                            <input type="number" name="max_price" value="{{ old('max_price') }}" min="0" step="0.01"
                                   class="w-full px-4 py-2 bg-dark-50 border border-dark-300 rounded-lg focus:ring-2 focus:ring-primary-500"
                                   placeholder="15000">
                            @error('max_price')<p class="mt-1 text-sm text-red-400">{{ $message }}</p>@enderror
                        </div>
                    </div>
                </div>

                @include('admin.products._variants')

                @include('admin.products._images')

                @include('admin.products._video')
            </div>

            <!-- Sidebar -->
            <div class="space-y-6">
                <!-- Product Type Card -->
                <div class="bg-dark-100 rounded-xl shadow-lg p-6">
                    <h3 class="text-lg font-bold text-white mb-4 flex items-center">
                        <i class="fas fa-cog text-primary-500 mr-2"></i>
                        Configuration
                    </h3>

                    <!-- Product Type -->
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-white mb-2">
                            Type <span class="text-red-500">*</span>
                        </label>
                        <div class="space-y-2">
                            <label class="flex items-center cursor-pointer p-3 bg-dark-50 border border-dark-300 rounded-lg hover:border-orange-500">
                                <input type="radio" name="type" value="article" {{ old('type', 'article') == 'article' ? 'checked' : '' }} class="w-4 h-4 text-primary-600">
                                <span class="ml-3"><i class="fas fa-box text-blue-500 mr-2"></i> Article</span>
                            </label>
                            <label class="flex items-center cursor-pointer p-3 bg-dark-50 border border-dark-300 rounded-lg hover:border-orange-500">
                                <input type="radio" name="type" value="service" {{ old('type') == 'service' ? 'checked' : '' }} class="w-4 h-4 text-primary-600">
                                <span class="ml-3"><i class="fas fa-concierge-bell text-purple-500 mr-2"></i> Service</span>
                            </label>
                        </div>
                    </div>

                    @include('admin.products._dimensions')

                    <!-- Poids réel (obligatoire pour un article) -->
                    <div id="product_weight_field">
                    <div class="mb-4">
                        <label for="weight" class="block text-sm font-medium text-white mb-2">
                            <i class="fas fa-weight-scale text-primary-500 mr-1"></i>
                            Poids unitaire (kg) <span class="text-red-500">*</span>
                        </label>
                        <input type="number" name="weight" id="weight" value="{{ old('weight') }}"
                               min="0.001" max="999999" step="0.001" inputmode="decimal"
                               {{ old('type', 'article') === 'article' ? 'required' : '' }}
                               class="w-full px-4 py-2 bg-dark-50 border border-dark-300 rounded-lg focus:ring-2 focus:ring-primary-500 @error('weight') border-red-500 @enderror"
                               placeholder="Ex : 2.5">
                        <p class="mt-1 text-xs text-gray-400">
                            Saisie en kilogrammes : 1 = 1 kg, 0,001 = 1 g. Le poids livré est le poids unitaire × la quantité (2 articles de 1 kg = 2 kg).
                        </p>
                        @error('weight')<p class="mt-1 text-sm text-red-400">{{ $message }}</p>@enderror
                    </div>


                    </div>



                    <!-- Origine du produit -->
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-white mb-2">
                            <i class="fas fa-globe text-primary-500 mr-1"></i>
                            Origine du produit
                        </label>
                        @php $currentOrigin = \App\Models\Product::originChoice(old('origin_country')); @endphp
                        <select name="origin_country" class="w-full px-4 py-2 bg-dark-50 border border-dark-300 rounded-lg focus:ring-2 focus:ring-primary-500 @error('origin_country') border-red-500 @enderror">
                            <option value="{{ \App\Models\Product::ORIGIN_IMPORTED }}" @selected($currentOrigin === \App\Models\Product::ORIGIN_IMPORTED)>Produit importé</option>
                            <option value="{{ \App\Models\Product::ORIGIN_LOCAL }}" @selected($currentOrigin === \App\Models\Product::ORIGIN_LOCAL)>Produit local</option>
                            @foreach($importCountries as $country)
                                <option value="{{ $country->code }}" @selected($currentOrigin === $country->code)>
                                    {{ $country->flag }} Grossiste {{ $country->name }}
                                </option>
                            @endforeach
                        </select>
                        <p class="mt-1 text-xs text-gray-400">Produit importé et produit local restent dans le menu principal de l'app. Un grossiste (Chine, Dubaï, Turquie…) fait passer le produit dans l'onglet « Import ».</p>
                        @error('origin_country')<p class="mt-1 text-sm text-red-400">{{ $message }}</p>@enderror
                    </div>

                    <!-- Stock -->
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-white mb-2">
                            Stock <span class="text-red-500">*</span>
                        </label>
                        <input type="number" name="stock" value="{{ old('stock', 0) }}" min="0" required
                               class="w-full px-4 py-2 bg-dark-50 border border-dark-300 rounded-lg focus:ring-2 focus:ring-primary-500">
                        @error('stock')<p class="mt-1 text-sm text-red-400">{{ $message }}</p>@enderror
                    </div>

                    <!-- Status -->
                    <div>
                        <label class="block text-sm font-medium text-white mb-2">
                            Statut <span class="text-red-500">*</span>
                        </label>
                        <div class="space-y-2">
                            <label class="flex items-center cursor-pointer p-3 bg-dark-50 border border-dark-300 rounded-lg hover:border-orange-500">
                                <input type="radio" name="status" value="active" {{ old('status', 'active') == 'active' ? 'checked' : '' }} class="w-4 h-4 text-primary-600">
                                <span class="ml-3"><i class="fas fa-check-circle text-green-500 mr-2"></i> Actif</span>
                            </label>
                            <label class="flex items-center cursor-pointer p-3 bg-dark-50 border border-dark-300 rounded-lg hover:border-orange-500">
                                <input type="radio" name="status" value="inactive" {{ old('status') == 'inactive' ? 'checked' : '' }} class="w-4 h-4 text-primary-600">
                                <span class="ml-3"><i class="fas fa-times-circle text-gray-400 mr-2"></i> Inactif</span>
                            </label>
                        </div>
                    </div>
                </div>

                @include('admin.products._free_delivery')
                @include('admin.products._delivery_delay')
                @include('admin.products._deposit')
                @include('admin.products._quote')

                <!-- Submit Button -->
                <div class="bg-gradient-to-r from-primary-500 to-primary-600 rounded-xl shadow-lg p-6 text-white">
                    <button type="submit" class="w-full px-6 py-3 bg-dark-100 text-primary-600 rounded-lg hover:bg-dark-50 transition-all font-semibold">
                        <i class="fas fa-save mr-2"></i>
                        Créer le Produit
                    </button>
                    <a href="{{ $libraryMode ? route('admin.library.books.index') : route('admin.products.index') }}" class="block w-full mt-3 px-6 py-3 bg-transparent border-2 border-white text-center text-white rounded-lg hover:bg-dark-100 hover:text-primary-600 transition-all">
                        <i class="fas fa-times mr-2"></i>
                        Annuler
                    </a>
                </div>
            </div>
        </div>
    </form>
</div>

@push('scripts')
<script>
// Toggle price fields based on price type
function togglePriceFields() {
    const priceType = document.querySelector('input[name="price_type"]:checked').value;
    const fixedField = document.getElementById('fixed_price_field');
    const variableFields = document.getElementById('variable_price_fields');

    if (priceType === 'fixed') {
        fixedField.classList.remove('hidden');
        variableFields.classList.add('hidden');
    } else {
        fixedField.classList.add('hidden');
        variableFields.classList.remove('hidden');
    }
}

// Load subcategories based on category
function loadSubcategories(categoryId) {
    const subcategorySelect = document.getElementById('subcategory_id');
    subcategorySelect.innerHTML = '<option value="">Chargement...</option>';

    if (!categoryId) {
        subcategorySelect.innerHTML = '<option value="">Sélectionnez d\'abord une catégorie</option>';
        return;
    }

    fetch(`/admin/categories/${categoryId}/subcategories`)
        .then(response => response.json())
        .then(data => {
            subcategorySelect.innerHTML = '<option value="">Aucune sous-catégorie</option>';
            data.forEach(sub => {
                const option = document.createElement('option');
                option.value = sub.id;
                option.textContent = sub.name;
                subcategorySelect.appendChild(option);
            });
        })
        .catch(error => {
            console.error('Error loading subcategories:', error);
            subcategorySelect.innerHTML = '<option value="">Erreur de chargement</option>';
        });
}

// P4 : poids réel obligatoire pour tout article, masqué pour un service. En gros, le
// poids d'une pièce se déduit du carton (_wholesale).
window.syncImportWeightSection = function () {
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
};

// Initialize on load
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('input[name="type"]').forEach(input => input.addEventListener('change', syncImportWeightSection));
    togglePriceFields();
    const oldCategoryId = '{{ old("category_id") }}';
    if (oldCategoryId) {
        loadSubcategories(oldCategoryId);
        setTimeout(() => {
            document.getElementById('subcategory_id').value = '{{ old("subcategory_id") }}';
        }, 500);
    }

    syncImportWeightSection();

});
</script>
@endpush
@endsection
