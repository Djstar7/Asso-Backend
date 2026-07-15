@extends('admin.layouts.app')

@section('content')
<div class="p-6">
    <!-- Header -->
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-white">Configuration des Catégories & Sous-catégories</h1>
            <p class="text-gray-400">Gérez les catégories de produits avec leurs icônes SVG et traductions</p>
        </div>
        <button onclick="openCategoryModal()"
                class="px-4 py-2 bg-gradient-to-r from-primary-500 to-primary-600 hover:shadow-lg text-white rounded-lg transition-all">
            <i class="fas fa-plus mr-2"></i>
            Nouvelle Catégorie
        </button>
    </div>

    <!-- Success/Error Messages -->
    @if(session('success'))
        <div class="mb-6 p-4 bg-green-900/20 border-l-4 border-green-500 rounded">
            <p class="text-green-300">
                <i class="fas fa-check-circle mr-2"></i>
                {{ session('success') }}
            </p>
        </div>
    @endif

    @if(session('error'))
        <div class="mb-6 p-4 bg-red-900/20 border-l-4 border-red-500 rounded">
            <p class="text-red-300">
                <i class="fas fa-exclamation-circle mr-2"></i>
                {{ session('error') }}
            </p>
        </div>
    @endif

    <!-- Statistics Cards -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
        <div class="bg-gradient-to-br from-primary-500 to-primary-600 rounded-xl shadow-lg p-6 text-white">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-orange-100 text-sm">Total Catégories</p>
                    <p class="text-3xl font-bold mt-1">{{ $categories->count() }}</p>
                </div>
                <div class="w-14 h-14 bg-dark-100 bg-opacity-20 rounded-lg flex items-center justify-center">
                    <i class="fas fa-th-large text-3xl"></i>
                </div>
            </div>
        </div>

        <div class="bg-gradient-to-br from-blue-500 to-blue-600 rounded-xl shadow-lg p-6 text-white">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-blue-100 text-sm">Total Sous-catégories</p>
                    <p class="text-3xl font-bold mt-1">{{ $subcategories->count() }}</p>
                </div>
                <div class="w-14 h-14 bg-dark-100 bg-opacity-20 rounded-lg flex items-center justify-center">
                    <i class="fas fa-layer-group text-3xl"></i>
                </div>
            </div>
        </div>

        <div class="bg-gradient-to-br from-green-500 to-green-600 rounded-xl shadow-lg p-6 text-white">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-green-100 text-sm">Total Produits</p>
                    <p class="text-3xl font-bold mt-1">{{ $categories->sum('products_count') }}</p>
                </div>
                <div class="w-14 h-14 bg-dark-100 bg-opacity-20 rounded-lg flex items-center justify-center">
                    <i class="fas fa-box text-3xl"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Categories with Subcategories (Hierarchical View) -->
    <div class="space-y-6">
        @forelse($categories as $category)
            <div class="bg-dark-100 rounded-xl shadow-lg overflow-hidden">
                <!-- Category Header -->
                <div class="bg-gradient-to-r from-primary-500/10 to-primary-600/10 p-6 border-l-4 border-primary-500">
                    <div class="flex items-start justify-between">
                        <div class="flex items-start gap-4 flex-1">
                            <!-- SVG Icon Preview -->
                            <div class="flex-shrink-0 w-16 h-16 bg-dark-100 rounded-xl flex items-center justify-center shadow-md">
                                @if($category->svg_icon)
                                    <div class="w-10 h-10 text-primary-400">
                                        {!! $category->svg_icon !!}
                                    </div>
                                @else
                                    <i class="fas fa-image text-orange-400 text-2xl"></i>
                                @endif
                            </div>

                            <div class="flex-1">
                                <div class="flex items-center gap-3 mb-2">
                                    <h3 class="text-xl font-bold text-white">
                                        {{ $category->name }}
                                    </h3>
                                    <span class="px-2 py-1 bg-primary-500/20 text-primary-300 text-xs rounded-full font-medium">
                                        {{ $category->slug }}
                                    </span>
                                </div>

                                <p class="text-sm text-white mb-2">
                                    <i class="fas fa-language text-blue-500 mr-1"></i>
                                    <span class="font-medium">EN:</span> {{ $category->name_en }}
                                </p>

                                @if($category->description)
                                    <p class="text-sm text-gray-400 mb-3">
                                        {{ $category->description }}
                                    </p>
                                @endif

                                <div class="flex gap-4 text-sm">
                                    <span class="flex items-center text-primary-400">
                                        <i class="fas fa-layer-group mr-1"></i>
                                        <span class="font-semibold">{{ $category->subcategories_count }}</span>
                                        <span class="ml-1">sous-catégories</span>
                                    </span>
                                    <span class="flex items-center text-primary-400">
                                        <i class="fas fa-box mr-1"></i>
                                        <span class="font-semibold">{{ $category->products_count }}</span>
                                        <span class="ml-1">produits</span>
                                    </span>
                                </div>
                            </div>
                        </div>

                        <!-- Category Actions -->
                        <div class="flex gap-2 ml-4">
                            <button onclick="editCategory({{ $category->id }})"
                                    class="w-10 h-10 flex items-center justify-center bg-dark-100 text-blue-400 hover:bg-dark-50 rounded-lg transition-all shadow-sm"
                                    title="Modifier la catégorie">
                                <i class="fas fa-edit"></i>
                            </button>
                            <form action="{{ route('admin.settings.categories.destroy', $category) }}"
                                  method="POST"
                                  data-confirm="Êtes-vous sûr de vouloir supprimer cette catégorie et toutes ses sous-catégories ?"
                                  class="inline">
                                @csrf
                                @method('DELETE')
                                <button type="submit"
                                        class="w-10 h-10 flex items-center justify-center bg-dark-100 text-red-400 hover:bg-red-600 rounded-lg transition-all shadow-sm"
                                        title="Supprimer la catégorie">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Subcategories List -->
                <div class="p-6 bg-dark-50">
                    <div class="flex items-center justify-between mb-4">
                        <h4 class="text-sm font-semibold text-white uppercase tracking-wider">
                            <i class="fas fa-layer-group text-primary-500 mr-2"></i>
                            Sous-catégories ({{ $category->subcategories->count() }})
                        </h4>
                        <button onclick="openSubcategoryModal({{ $category->id }})"
                                class="px-3 py-1.5 bg-gradient-to-r from-primary-500 to-primary-600 text-white text-sm rounded-lg hover:shadow-lg transition-all">
                            <i class="fas fa-plus mr-1"></i>
                            Ajouter
                        </button>
                    </div>

                    @if($category->subcategories->count() > 0)
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
                            @foreach($category->subcategories as $subcategory)
                                <div class="bg-dark-100 p-4 rounded-lg border border-dark-200 hover:border-primary-500 hover:shadow-md transition-all group">
                                    <div class="flex items-start justify-between">
                                        <div class="flex-1">
                                            <h5 class="font-semibold text-white mb-1">
                                                {{ $subcategory->name }}
                                            </h5>
                                            <p class="text-xs text-gray-400 mb-2">
                                                <i class="fas fa-language text-blue-400 mr-1"></i>
                                                EN: {{ $subcategory->name_en }}
                                            </p>
                                            <div class="flex items-center gap-2">
                                                <span class="text-xs px-2 py-0.5 bg-dark-50 text-gray-400 rounded">
                                                    {{ $subcategory->slug }}
                                                </span>
                                                <span class="text-xs text-primary-400">
                                                    <i class="fas fa-box mr-1"></i>
                                                    {{ $subcategory->products_count }}
                                                </span>
                                            </div>
                                        </div>

                                        <div class="flex gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                                            <button onclick="editSubcategory({{ $subcategory->id }})"
                                                    class="w-8 h-8 flex items-center justify-center text-blue-400 hover:bg-dark-50 rounded transition-all"
                                                    title="Modifier">
                                                <i class="fas fa-edit text-sm"></i>
                                            </button>
                                            <form action="{{ route('admin.settings.subcategories.destroy', $subcategory) }}"
                                                  method="POST"
                                                  data-confirm="Êtes-vous sûr de vouloir supprimer cette sous-catégorie ?"
                                                  class="inline">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit"
                                                        class="w-8 h-8 flex items-center justify-center text-red-400 hover:bg-red-600 rounded transition-all"
                                                        title="Supprimer">
                                                    <i class="fas fa-trash text-sm"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <div class="text-center py-8 text-gray-400">
                            <i class="fas fa-layer-group text-3xl mb-2"></i>
                            <p class="text-sm">Aucune sous-catégorie</p>
                            <button onclick="openSubcategoryModal({{ $category->id }})"
                                    class="mt-3 text-primary-500 hover:text-primary-400 text-sm font-medium">
                                <i class="fas fa-plus mr-1"></i>
                                Ajouter la première sous-catégorie
                            </button>
                        </div>
                    @endif
                </div>
            </div>
        @empty
            <div class="bg-dark-100 rounded-xl shadow-lg p-12 text-center">
                <i class="fas fa-th-large text-6xl text-gray-300 mb-4"></i>
                <h3 class="text-xl font-semibold text-gray-400 mb-2">Aucune catégorie</h3>
                <p class="text-gray-500 mb-4">Commencez par créer votre première catégorie</p>
                <button onclick="openCategoryModal()"
                        class="px-6 py-3 bg-gradient-to-r from-primary-500 to-primary-600 hover:shadow-lg text-white rounded-lg transition-all">
                    <i class="fas fa-plus mr-2"></i>
                    Créer une catégorie
                </button>
            </div>
        @endforelse
    </div>
</div>

<!-- Category Modal -->
<div id="categoryModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4">
    <div class="bg-dark-100 rounded-xl shadow-2xl max-w-2xl w-full max-h-[90vh] overflow-y-auto">
        <form id="categoryForm" method="POST" action="{{ route('admin.settings.categories.store') }}">
            @csrf
            <input type="hidden" id="category_method" name="_method" value="POST">

            <!-- Modal Header -->
            <div class="p-6 border-b border-dark-200 bg-gradient-to-r from-primary-500/10 to-primary-600/10">
                <h3 class="text-xl font-bold text-white" id="categoryModalTitle">
                    <i class="fas fa-th-large text-primary-500 mr-2"></i>
                    Ajouter une Catégorie
                </h3>
            </div>

            <!-- Modal Body -->
            <div class="p-6 space-y-4">
                <!-- French Name -->
                <div>
                    <label class="block text-sm font-medium text-white mb-2">
                        <i class="fas fa-tag text-primary-500 mr-1"></i>
                        Nom (Français) <span class="text-red-500">*</span>
                    </label>
                    <input type="text"
                           name="name"
                           id="category_name"
                           required
                           class="w-full px-4 py-2 bg-dark-50 border border-dark-300 text-white placeholder-gray-400 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500"
                           placeholder="Ex: Électronique">
                </div>

                <!-- English Name -->
                <div>
                    <label class="block text-sm font-medium text-white mb-2">
                        <i class="fas fa-language text-blue-500 mr-1"></i>
                        Nom (Anglais) <span class="text-red-500">*</span>
                    </label>
                    <input type="text"
                           name="name_en"
                           id="category_name_en"
                           required
                           class="w-full px-4 py-2 bg-dark-50 border border-dark-300 text-white placeholder-gray-400 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500"
                           placeholder="Ex: Electronics">
                </div>

                <!-- SVG Icon -->
                <div>
                    <label class="block text-sm font-medium text-white mb-2">
                        <i class="fas fa-code text-purple-500 mr-1"></i>
                        Icône SVG (Optionnel)
                    </label>
                    <textarea name="svg_icon"
                              id="category_svg_icon"
                              rows="4"
                              class="w-full px-4 py-2 bg-dark-50 border border-dark-300 text-white placeholder-gray-400 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500 font-mono text-sm"
                              placeholder='<svg xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 24 24"><path d="..."/></svg>'></textarea>
                    <p class="mt-1 text-xs text-gray-500">
                        <i class="fas fa-info-circle mr-1"></i>
                        Collez le code SVG complet. L'icône s'affichera dans la liste des catégories.
                    </p>
                </div>

                <!-- Description -->
                <div>
                    <label class="block text-sm font-medium text-white mb-2">
                        <i class="fas fa-align-left text-gray-500 mr-1"></i>
                        Description (Optionnel)
                    </label>
                    <textarea name="description"
                              id="category_description"
                              rows="3"
                              class="w-full px-4 py-2 bg-dark-50 border border-dark-300 text-white placeholder-gray-400 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500"
                              placeholder="Description de la catégorie..."></textarea>
                </div>
            </div>

            <!-- Modal Footer -->
            <div class="p-6 border-t border-dark-200 flex gap-3 justify-end bg-dark-50">
                <button type="button"
                        onclick="closeCategoryModal()"
                        class="px-6 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 transition-all">
                    <i class="fas fa-times mr-2"></i>
                    Annuler
                </button>
                <button type="submit"
                        class="px-6 py-2 bg-gradient-to-r from-primary-500 to-primary-600 hover:shadow-lg text-white rounded-lg transition-all">
                    <i class="fas fa-save mr-2"></i>
                    Enregistrer
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Subcategory Modal -->
<div id="subcategoryModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4">
    <div class="bg-dark-100 rounded-xl shadow-2xl max-w-2xl w-full max-h-[90vh] overflow-y-auto">
        <form id="subcategoryForm" method="POST" action="{{ route('admin.settings.subcategories.store') }}">
            @csrf
            <input type="hidden" id="subcategory_method" name="_method" value="POST">

            <!-- Modal Header -->
            <div class="p-6 border-b border-dark-200 bg-gradient-to-r from-blue-500/10 to-blue-600/10">
                <h3 class="text-xl font-bold text-white" id="subcategoryModalTitle">
                    <i class="fas fa-layer-group text-blue-400 mr-2"></i>
                    Ajouter une Sous-catégorie
                </h3>
            </div>

            <!-- Modal Body -->
            <div class="p-6 space-y-4">
                <!-- Category Selection -->
                <div>
                    <label class="block text-sm font-medium text-white mb-2">
                        <i class="fas fa-th-large text-primary-500 mr-1"></i>
                        Catégorie Parente <span class="text-red-500">*</span>
                    </label>
                    <select name="category_id"
                            id="subcategory_category_id"
                            required
                            class="w-full px-4 py-2 bg-dark-50 border border-dark-300 text-white placeholder-gray-400 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500">
                        <option value="">Sélectionnez une catégorie</option>
                        @foreach($categories as $category)
                            <option value="{{ $category->id }}">{{ $category->name }}</option>
                        @endforeach
                    </select>
                </div>

                <!-- French Name -->
                <div>
                    <label class="block text-sm font-medium text-white mb-2">
                        <i class="fas fa-tag text-primary-500 mr-1"></i>
                        Nom (Français) <span class="text-red-500">*</span>
                    </label>
                    <input type="text"
                           name="name"
                           id="subcategory_name"
                           required
                           class="w-full px-4 py-2 bg-dark-50 border border-dark-300 text-white placeholder-gray-400 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500"
                           placeholder="Ex: Téléphones">
                </div>

                <!-- English Name -->
                <div>
                    <label class="block text-sm font-medium text-white mb-2">
                        <i class="fas fa-language text-blue-500 mr-1"></i>
                        Nom (Anglais) <span class="text-red-500">*</span>
                    </label>
                    <input type="text"
                           name="name_en"
                           id="subcategory_name_en"
                           required
                           class="w-full px-4 py-2 bg-dark-50 border border-dark-300 text-white placeholder-gray-400 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500"
                           placeholder="Ex: Phones">
                </div>
            </div>

            <!-- Modal Footer -->
            <div class="p-6 border-t border-dark-200 flex gap-3 justify-end bg-dark-50">
                <button type="button"
                        onclick="closeSubcategoryModal()"
                        class="px-6 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 transition-all">
                    <i class="fas fa-times mr-2"></i>
                    Annuler
                </button>
                <button type="submit"
                        class="px-6 py-2 bg-gradient-to-r from-primary-500 to-primary-600 hover:shadow-lg text-white rounded-lg transition-all">
                    <i class="fas fa-save mr-2"></i>
                    Enregistrer
                </button>
            </div>
        </form>
    </div>
</div>

@push('scripts')
<script>
// Categories data for editing
const categoriesData = @json($categories);
const subcategoriesData = @json($subcategories);

// Category Modal Functions
function openCategoryModal() {
    document.getElementById('categoryModal').classList.remove('hidden');
    document.getElementById('categoryModalTitle').innerHTML = '<i class="fas fa-th-large text-primary-500 mr-2"></i><span class="text-white">Ajouter une Catégorie</span>';
    document.getElementById('categoryForm').action = '{{ route("admin.settings.categories.store") }}';
    document.getElementById('category_method').value = 'POST';
    document.getElementById('categoryForm').reset();
}

function closeCategoryModal() {
    document.getElementById('categoryModal').classList.add('hidden');
}

function editCategory(id) {
    const category = categoriesData.find(c => c.id === id);
    if (!category) return;

    document.getElementById('categoryModal').classList.remove('hidden');
    document.getElementById('categoryModalTitle').innerHTML = '<i class="fas fa-edit text-primary-500 mr-2"></i><span class="text-white">Modifier la Catégorie</span>';
    document.getElementById('categoryForm').action = `/admin/settings/categories/${id}`;
    document.getElementById('category_method').value = 'PUT';

    document.getElementById('category_name').value = category.name;
    document.getElementById('category_name_en').value = category.name_en;
    document.getElementById('category_svg_icon').value = category.svg_icon || '';
    document.getElementById('category_description').value = category.description || '';
}

// Subcategory Modal Functions
function openSubcategoryModal(categoryId = null) {
    document.getElementById('subcategoryModal').classList.remove('hidden');
    document.getElementById('subcategoryModalTitle').innerHTML = '<i class="fas fa-layer-group text-blue-400 mr-2"></i><span class="text-white">Ajouter une Sous-catégorie</span>';
    document.getElementById('subcategoryForm').action = '{{ route("admin.settings.subcategories.store") }}';
    document.getElementById('subcategory_method').value = 'POST';
    document.getElementById('subcategoryForm').reset();

    if (categoryId) {
        document.getElementById('subcategory_category_id').value = categoryId;
    }
}

function closeSubcategoryModal() {
    document.getElementById('subcategoryModal').classList.add('hidden');
}

function editSubcategory(id) {
    const subcategory = subcategoriesData.find(s => s.id === id);
    if (!subcategory) return;

    document.getElementById('subcategoryModal').classList.remove('hidden');
    document.getElementById('subcategoryModalTitle').innerHTML = '<i class="fas fa-edit text-blue-400 mr-2"></i><span class="text-white">Modifier la Sous-catégorie</span>';
    document.getElementById('subcategoryForm').action = `/admin/settings/subcategories/${id}`;
    document.getElementById('subcategory_method').value = 'PUT';

    document.getElementById('subcategory_category_id').value = subcategory.category_id;
    document.getElementById('subcategory_name').value = subcategory.name;
    document.getElementById('subcategory_name_en').value = subcategory.name_en;
}

// Close modals on ESC key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeCategoryModal();
        closeSubcategoryModal();
    }
});

// Close modals when clicking outside
document.getElementById('categoryModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeCategoryModal();
    }
});

document.getElementById('subcategoryModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeSubcategoryModal();
    }
});
</script>
@endpush
@endsection
