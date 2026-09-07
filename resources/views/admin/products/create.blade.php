@extends('admin.layouts.app')

@section('content')
<div class="p-6">
    <!-- Header -->
    <div class="mb-6">
        <div class="flex items-center gap-3">
            <a href="{{ route('admin.products.index') }}" class="text-gray-400 hover:text-primary-600 transition-colors">
                <i class="fas fa-arrow-left"></i>
            </a>
            <div>
                <h1 class="text-2xl font-bold text-white">Créer un Produit</h1>
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

                        <!-- Product Name -->
                        <div>
                            <label class="block text-sm font-medium text-white mb-2">
                                <i class="fas fa-tag text-primary-500 mr-1"></i>
                                Nom du Produit <span class="text-red-500">*</span>
                            </label>
                            <input type="text" name="name" value="{{ old('name') }}" required
                                   class="w-full px-4 py-2 bg-dark-50 border border-dark-300 rounded-lg focus:ring-2 focus:ring-primary-500 @error('name') border-red-500 @enderror"
                                   placeholder="Ex: iPhone 15 Pro">
                            @error('name')<p class="mt-1 text-sm text-red-400">{{ $message }}</p>@enderror
                        </div>

                        <!-- Description -->
                        <div>
                            <label class="block text-sm font-medium text-white mb-2">
                                <i class="fas fa-align-left text-primary-500 mr-1"></i>
                                Description
                            </label>
                            <textarea name="description" rows="4"
                                      class="w-full px-4 py-2 bg-dark-50 border border-dark-300 rounded-lg focus:ring-2 focus:ring-primary-500 @error('description') border-red-500 @enderror"
                                      placeholder="Décrivez le produit...">{{ old('description') }}</textarea>
                            @error('description')<p class="mt-1 text-sm text-red-400">{{ $message }}</p>@enderror
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

                <!-- Vente en gros (Import) -->
<div class="bg-dark-100 rounded-xl shadow-lg p-6" id="wholesale_section" style="display:none;">
    <div class="flex items-center justify-between mb-4">
        <h2 class="text-lg font-semibold text-white flex items-center">
            <i class="fas fa-boxes text-primary-500 mr-2"></i>
            Vente en gros (Import)
        </h2>
        <span id="wholesale_country_badge" class="text-xs px-2 py-1 bg-primary-600/20 text-primary-400 rounded-full"></span>
    </div>

    <div class="mb-4 flex items-center">
        <input type="checkbox" name="is_wholesale" id="is_wholesale" value="1"
               {{ old('is_wholesale', $product->is_wholesale ?? false) ? 'checked' : '' }}
               class="mr-2 w-4 h-4 text-primary-500 focus:ring-primary-500 rounded">
        <label for="is_wholesale" class="text-white text-sm font-medium cursor-pointer">
            Activer les paliers de prix (cota / gros)
        </label>
    </div>

    <div id="tiers_container" class="{{ old('is_wholesale', $product->is_wholesale ?? false) ? '' : 'hidden' }}">
        <div class="flex items-center justify-between mb-2">
            <label class="text-sm font-medium text-white">Paliers de prix</label>
            <button type="button" onclick="addTierRow()"
                    class="px-3 py-1.5 bg-primary-600 text-white text-xs rounded-lg hover:bg-primary-700">
                <i class="fas fa-plus mr-1"></i> Ajouter un palier
            </button>
        </div>

        <div id="tiers_wrapper" class="space-y-2"></div>

        <p id="tiers_empty_msg" class="text-xs text-gray-500 mt-2 hidden">
            Aucun palier. Cliquez sur « Ajouter un palier » (ex: Pack de 50, Carton de 100...).
        </p>

        @error('tiers')
            <p class="mt-2 text-sm text-red-400">{{ $message }}</p>
        @enderror
    </div>

    <div id="shipping_preview" class="mt-6 border-t border-dark-300 pt-4 hidden">
        <h3 class="text-sm font-semibold text-white mb-2">
            <i class="fas fa-ship text-primary-500 mr-1"></i> Expéditions configurées pour ce pays
        </h3>
        <div id="shipping_list" class="text-sm text-gray-300 space-y-1"></div>
    </div>
</div>

                <!-- Pricing Card -->
                <div class="bg-dark-100 rounded-xl shadow-lg p-6">
                    <h3 class="text-lg font-bold text-white mb-4 flex items-center">
                        <i class="fas fa-dollar-sign text-primary-500 mr-2"></i>
                        Tarification
                    </h3>

                    <!-- Price Type -->
                    <div class="mb-4">
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

                <!-- Images Card -->
                <div class="bg-dark-100 rounded-xl shadow-lg p-6">
                    <h3 class="text-lg font-bold text-white mb-4 flex items-center">
                        <i class="fas fa-images text-primary-500 mr-2"></i>
                        Images du Produit
                    </h3>
                    <input type="file" name="images[]" multiple accept="image/*" onchange="previewImages(event)"
                           class="w-full px-4 py-2 bg-dark-50 border border-dark-300 rounded-lg focus:ring-2 focus:ring-primary-500">
                    <p class="mt-2 text-xs text-gray-400">Max 2MB par image. Formats: JPG, PNG, GIF, WebP. La première image sera l'image principale.</p>
                    <div id="image_preview" class="mt-4 grid grid-cols-4 gap-4"></div>
                </div>
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

                    <!-- Weight Category -->
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
                            Poids d'une seule unité. Le poids total sera calculé automatiquement : poids unitaire × quantité commandée.
                        </p>
                        @error('weight')<p class="mt-1 text-sm text-red-400">{{ $message }}</p>@enderror
                    </div>

                    <!-- Weight Category -->
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-white mb-2">
                            <i class="fas fa-weight-hanging text-primary-500 mr-1"></i>
                            Catégorie de poids
                        </label>
                        <select name="weight_category" class="w-full px-4 py-2 bg-dark-50 border border-dark-300 rounded-lg focus:ring-2 focus:ring-primary-500 @error('weight_category') border-red-500 @enderror">
                            @foreach(\App\Models\DeliveryPricelist::WEIGHT_CATEGORIES as $key => $label)
                                <option value="{{ $key }}" {{ old('weight_category', 'X-small') == $key ? 'selected' : '' }}>{{ $key }} — {{ $label }}</option>
                            @endforeach
                        </select>
                        <p class="mt-1 text-xs text-gray-400">Détermine le prix de livraison chez les partenaires</p>
                        @error('weight_category')<p class="mt-1 text-sm text-red-400">{{ $message }}</p>@enderror
                    </div>

                    <!-- Pays d'origine (produits importés) -->
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-white mb-2">
                            <i class="fas fa-globe text-primary-500 mr-1"></i>
                            Pays d'origine (produit importé)
                        </label>
                        <select name="origin_country" class="w-full px-4 py-2 bg-dark-50 border border-dark-300 rounded-lg focus:ring-2 focus:ring-primary-500 @error('origin_country') border-red-500 @enderror">
                            <option value="">🏳️ Produit local (aucun)</option>
                            @foreach($importCountries as $country)
                                <option value="{{ $country->code }}" {{ old('origin_country') == $country->code ? 'selected' : '' }}>
                                    {{ $country->flag }} {{ $country->name }}
                                </option>
                            @endforeach
                        </select>
                        <p class="mt-1 text-xs text-gray-400">Sélectionnez un pays pour afficher le produit dans l'onglet « Import » de l'app (Chine, Turquie, Dubaï…).</p>
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

                <!-- Submit Button -->
                <div class="bg-gradient-to-r from-primary-500 to-primary-600 rounded-xl shadow-lg p-6 text-white">
                    <button type="submit" class="w-full px-6 py-3 bg-dark-100 text-primary-600 rounded-lg hover:bg-dark-50 transition-all font-semibold">
                        <i class="fas fa-save mr-2"></i>
                        Créer le Produit
                    </button>
                    <a href="{{ route('admin.products.index') }}" class="block w-full mt-3 px-6 py-3 bg-transparent border-2 border-white text-center text-white rounded-lg hover:bg-dark-100 hover:text-primary-600 transition-all">
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

// Preview images
function previewImages(event) {
    const preview = document.getElementById('image_preview');
    preview.innerHTML = '';

    Array.from(event.target.files).forEach((file, index) => {
        const reader = new FileReader();
        reader.onload = function(e) {
            const div = document.createElement('div');
            div.className = 'relative';
            div.innerHTML = `
                <img src="${e.target.result}" class="w-full h-24 object-cover rounded-lg border-2 border-orange-500">
                ${index === 0 ? '<span class="absolute top-1 right-1 px-2 py-1 bg-orange-500 text-white text-xs rounded">Principal</span>' : ''}
            `;
            preview.appendChild(div);
        };
        reader.readAsDataURL(file);
    });
}

// ============================================
// MODULE GROS — Vente en gros par pays d'import
// ============================================
let tierIndex = 0;

function tierRowTemplate(data = {}) {
    const idx = tierIndex++;
    const esc = (v) => String(v ?? '').replace(/"/g, '&quot;');
    return `
    <div class="grid grid-cols-1 md:grid-cols-12 gap-2 items-end bg-dark-50 p-3 rounded-lg" id="tier_row_${idx}">
        <div class="md:col-span-4">
            <label class="block text-xs text-gray-400 mb-1">Label</label>
            <input type="text" name="tiers[${idx}][label]" value="${esc(data.label)}"
                   placeholder="Ex: Pack de 50"
                   class="w-full px-3 py-2 bg-dark-100 border border-dark-300 rounded text-white text-sm">
        </div>
        <div class="md:col-span-3">
            <label class="block text-xs text-gray-400 mb-1">Prix unitaire (FCFA)</label>
            <input type="number" step="0.01" min="0" name="tiers[${idx}][unit_price]" value="${esc(data.unit_price)}"
                   class="w-full px-3 py-2 bg-dark-100 border border-dark-300 rounded text-white text-sm">
        </div>
        <div class="md:col-span-2">
            <label class="block text-xs text-gray-400 mb-1">Qté min</label>
            <input type="number" min="1" name="tiers[${idx}][min_quantity]" value="${esc(data.min_quantity)}"
                   class="w-full px-3 py-2 bg-dark-100 border border-dark-300 rounded text-white text-sm">
        </div>
        <div class="md:col-span-2">
            <label class="block text-xs text-gray-400 mb-1">Pack size</label>
            <input type="number" min="1" name="tiers[${idx}][pack_size]" value="${esc(data.pack_size ?? 1)}"
                   class="w-full px-3 py-2 bg-dark-100 border border-dark-300 rounded text-white text-sm">
        </div>
        <div class="md:col-span-1">
            <button type="button" onclick="removeTierRow(${idx})"
                    class="w-full px-3 py-2 bg-red-500 text-white rounded text-sm hover:bg-red-600">
                <i class="fas fa-trash"></i>
            </button>
        </div>
    </div>`;
}

function addTierRow(data = {}) {
    document.getElementById('tiers_wrapper').insertAdjacentHTML('beforeend', tierRowTemplate(data));
    updateEmptyMsg();
}

function removeTierRow(idx) {
    document.getElementById(`tier_row_${idx}`)?.remove();
    updateEmptyMsg();
}

function updateEmptyMsg() {
    const wrapper = document.getElementById('tiers_wrapper');
    const msg = document.getElementById('tiers_empty_msg');
    if (!wrapper || !msg) return;
    msg.classList.toggle('hidden', wrapper.children.length > 0);
}

function toggleTiersContainer() {
    const checkbox = document.getElementById('is_wholesale');
    const container = document.getElementById('tiers_container');
    if (!checkbox || !container) return;
    container.classList.toggle('hidden', !checkbox.checked);
    if (checkbox.checked) updateEmptyMsg();
}

function toggleWholesaleSection() {
    const select = document.querySelector('select[name="origin_country"]');
    const country = select ? select.value : '';
    const section = document.getElementById('wholesale_section');
    const badge = document.getElementById('wholesale_country_badge');
    if (!section) return;

    if (country) {
        section.style.display = 'block';
        const label = select.options[select.selectedIndex]?.text?.trim() ?? country;
        if (badge) badge.textContent = label;
        loadShippingOptions(country);
    } else {
        section.style.display = 'none';
        const cb = document.getElementById('is_wholesale');
        if (cb) cb.checked = false;
        toggleTiersContainer();
    }
}

function loadShippingOptions(countryCode) {
    fetch(`/api/v1/import/${countryCode}/shipping`)
        .then(r => r.json())
        .then(response => {
            const data = response.success ? (response.shipping_options ?? []) : [];
            const list = document.getElementById('shipping_list');
            const preview = document.getElementById('shipping_preview');
            preview.classList.remove('hidden');

            if (!data.length) {
                list.innerHTML = '<p class="text-gray-500">Aucune option d\'expédition définie pour ce pays.</p>';
                return;
            }

            list.innerHTML = data.map(s => {
                const mode = s.mode ?? s.type ?? '';
                const rateType = s.rate_type ?? s.rateType ?? '';
                const rateAmount = s.rate_amount ?? s.rateAmount ?? s.price ?? 0;
                const leadTime = s.lead_time_days ?? s.leadTimeDays ?? s.delay ?? '?';
                const note = s.expedition_note ?? s.expeditionNote ?? s.note ?? '';

                const priceLabel = rateType === 'flat'
                    ? Number(rateAmount).toLocaleString() + ' FCFA (forfait)'
                    : Number(rateAmount).toLocaleString() + ' FCFA/kg';

                return `<div>• <strong>${String(mode).toUpperCase()}</strong> — ${priceLabel} — ${leadTime}j${note ? ' · ' + note : ''}</div>`;
            }).join('');
        })
        .catch(() => {
            document.getElementById('shipping_preview')?.classList.add('hidden');
        });
}

// Initialize on load
document.addEventListener('DOMContentLoaded', function() {
    const syncWeightRequirement = () => {
        const weight = document.getElementById('weight');
        const type = document.querySelector('input[name="type"]:checked')?.value;
        if (weight) weight.required = type === 'article';
    };
    document.querySelectorAll('input[name="type"]').forEach(input => input.addEventListener('change', syncWeightRequirement));
    syncWeightRequirement();
    togglePriceFields();
    const oldCategoryId = '{{ old("category_id") }}';
    if (oldCategoryId) {
        loadSubcategories(oldCategoryId);
        setTimeout(() => {
            document.getElementById('subcategory_id').value = '{{ old("subcategory_id") }}';
        }, 500);
    }
    @foreach(old('tiers', []) as $tier)
        addTierRow({
            label: @json($tier['label'] ?? ''),
            unit_price: {{ $tier['unit_price'] ?? 0 }},
            min_quantity: {{ $tier['min_quantity'] ?? 1 }},
            pack_size: {{ $tier['pack_size'] ?? 1 }}
        });
    @endforeach

    document.querySelector('select[name="origin_country"]')?.addEventListener('change', toggleWholesaleSection);
    document.getElementById('is_wholesale')?.addEventListener('change', toggleTiersContainer);

    toggleWholesaleSection();
    toggleTiersContainer();

});
</script>
@endpush
@endsection
