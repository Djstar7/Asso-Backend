@extends('admin.layouts.app')

@section('content')
<div class="p-6">
    <!-- Header -->
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-white">Modifier le Produit</h1>
            <p class="text-gray-400">Mettez à jour les informations du produit</p>
        </div>
        <a href="{{ route('admin.products.index') }}" class="px-4 py-2 bg-gray-500 text-white rounded-lg hover:bg-gray-600 transition-all">
            <i class="fas fa-arrow-left mr-2"></i>
            Retour
        </a>
    </div>

    @if ($errors->any())
        <div class="mb-6 p-4 bg-red-900/20 border-l-4 border-red-500 rounded">
            <p class="font-semibold text-red-400 mb-2"><i class="fas fa-exclamation-circle mr-2"></i>Erreurs de validation:</p>
            <ul class="list-disc list-inside text-red-300">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

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

                    <!-- Product Name -->
                    <div class="mb-4">
                        <label for="name" class="block text-sm font-medium text-white mb-2">
                            Nom du produit <span class="text-red-500">*</span>
                        </label>
                        <input type="text" name="name" id="name" value="{{ old('name', $product->name) }}" required
                               class="w-full px-4 py-2 bg-dark-50 border border-dark-300 rounded-lg text-white focus:ring-2 focus:ring-primary-500 focus:border-transparent"
                               placeholder="Ex: Laptop Dell XPS 15">
                    </div>

                    <!-- Description -->
                    <div class="mb-4">
                        <label for="description" class="block text-sm font-medium text-white mb-2">
                            Description
                        </label>
                        <textarea name="description" id="description" rows="4"
                                  class="w-full px-4 py-2 bg-dark-50 border border-dark-300 rounded-lg text-white focus:ring-2 focus:ring-primary-500 focus:border-transparent"
                                  placeholder="Description détaillée du produit...">{{ old('description', $product->description) }}</textarea>
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

                <!-- Prix -->
                <div class="bg-dark-100 rounded-xl shadow-lg p-6">
                    <h2 class="text-lg font-semibold text-white mb-4 flex items-center">
                        <i class="fas fa-dollar-sign text-primary-500 mr-2"></i>
                        Tarification
                    </h2>

                    <!-- Price Type -->
                    <div class="mb-4">
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

                <!-- Existing Images -->
                @if($product->images->count() > 0)
                <div class="bg-dark-100 rounded-xl shadow-lg p-6">
                    <h2 class="text-lg font-semibold text-white mb-4 flex items-center">
                        <i class="fas fa-images text-primary-500 mr-2"></i>
                        Images existantes
                    </h2>

                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                        @foreach($product->images as $image)
                            <div class="relative group" id="image-{{ $image->id }}">
                                <img src="{{ asset($image->image_path) }}" alt="{{ $product->name }}" class="w-full h-32 object-cover rounded-lg border-2 {{ $image->is_primary ? 'border-orange-500' : 'border-dark-200' }}">

                                @if($image->is_primary)
                                    <span class="absolute top-2 left-2 px-2 py-1 bg-orange-500 text-white text-xs rounded-full">
                                        <i class="fas fa-star mr-1"></i>Principal
                                    </span>
                                @endif

                                <div class="absolute inset-0 bg-black bg-opacity-50 opacity-0 group-hover:opacity-100 transition-opacity rounded-lg flex items-center justify-center gap-2">
                                    @if(!$image->is_primary)
                                        <button type="button" onclick="setPrimaryImage({{ $product->id }}, {{ $image->id }})"
                                                class="px-3 py-1 bg-orange-500 text-white text-sm rounded hover:bg-orange-600">
                                            <i class="fas fa-star"></i>
                                        </button>
                                    @endif
                                    <button type="button" onclick="deleteImage({{ $product->id }}, {{ $image->id }})"
                                            class="px-3 py-1 bg-red-500 text-white text-sm rounded hover:bg-red-600">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
                @endif

                <!-- New Images -->
                <div class="bg-dark-100 rounded-xl shadow-lg p-6">
                    <h2 class="text-lg font-semibold text-white mb-4 flex items-center">
                        <i class="fas fa-camera text-primary-500 mr-2"></i>
                        Ajouter de nouvelles images
                    </h2>

                    <div class="mb-4">
                        <label for="images" class="block text-sm font-medium text-white mb-2">
                            Images (JPEG, PNG, JPG, GIF, WEBP - Max 2MB chacune)
                        </label>
                        <input type="file" name="images[]" id="images" multiple accept="image/*" onchange="previewImages(event)"
                               class="w-full px-4 py-2 bg-dark-50 border border-dark-300 rounded-lg text-white focus:ring-2 focus:ring-primary-500 focus:border-transparent">
                        <p class="text-sm text-gray-400 mt-1">Vous pouvez sélectionner plusieurs images</p>
                    </div>

                    <!-- Image Preview -->
                    <div id="image_preview" class="grid grid-cols-2 md:grid-cols-4 gap-4 hidden"></div>
                </div>
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

                <!-- Weight Category -->
                <div class="bg-dark-100 rounded-xl shadow-lg p-6">
                    <h2 class="text-lg font-semibold text-white mb-4 flex items-center">
                        <i class="fas fa-weight-hanging text-primary-500 mr-2"></i>
                        Catégorie de poids
                    </h2>

                    <div>
                        <label for="weight_category" class="block text-sm font-medium text-white mb-2">
                            Taille du produit
                        </label>
                        <select name="weight_category" id="weight_category"
                                class="w-full px-4 py-2 bg-dark-50 border border-dark-300 rounded-lg text-white focus:ring-2 focus:ring-primary-500 focus:border-transparent">
                            @foreach(\App\Models\DeliveryPricelist::WEIGHT_CATEGORIES as $key => $label)
                                <option value="{{ $key }}" {{ old('weight_category', $product->weight_category) == $key ? 'selected' : '' }}>{{ $key }} — {{ $label }}</option>
                            @endforeach
                        </select>
                        <p class="text-xs text-gray-400 mt-2">Détermine le prix de livraison chez les partenaires</p>
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

    // Preview new images
    window.previewImages = function(event) {
        const preview = document.getElementById('image_preview');
        preview.innerHTML = '';

        const files = event.target.files;
        if (files.length > 0) {
            preview.classList.remove('hidden');

            Array.from(files).forEach((file, index) => {
                const reader = new FileReader();
                reader.onload = function(e) {
                    const div = document.createElement('div');
                    div.className = 'relative';
                    div.innerHTML = `
                        <img src="${e.target.result}" alt="Preview ${index + 1}" class="w-full h-32 object-cover rounded-lg border-2 border-dark-200">
                        <span class="absolute top-2 left-2 px-2 py-1 bg-blue-500 text-white text-xs rounded-full">
                            <i class="fas fa-plus mr-1"></i>Nouveau
                        </span>
                    `;
                    preview.appendChild(div);
                };
                reader.readAsDataURL(file);
            });
        } else {
            preview.classList.add('hidden');
        }
    };

    // Delete image via AJAX
    window.deleteImage = function(productId, imageId) {
        if (!confirm('Êtes-vous sûr de vouloir supprimer cette image ?')) {
            return;
        }

        fetch(`/admin/products/${productId}/images/${imageId}`, {
            method: 'DELETE',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                'Accept': 'application/json',
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                document.getElementById(`image-${imageId}`).remove();

                // Reload page if no images left to update the display
                const remainingImages = document.querySelectorAll('[id^="image-"]');
                if (remainingImages.length === 0) {
                    location.reload();
                }
            } else {
                alert('Erreur lors de la suppression de l\'image');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Erreur lors de la suppression de l\'image');
        });
    };

    // Set primary image via AJAX
    window.setPrimaryImage = function(productId, imageId) {
        fetch(`/admin/products/${productId}/images/${imageId}/primary`, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                'Accept': 'application/json',
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                location.reload();
            } else {
                alert('Erreur lors de la définition de l\'image principale');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Erreur lors de la définition de l\'image principale');
        });
    };

    // Initialize on page load
    document.addEventListener('DOMContentLoaded', function() {
        togglePriceFields();
    });
})();
</script>
@endsection
