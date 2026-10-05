@extends('admin.layouts.app')

@section('content')
<div class="p-6">
    <!-- Header -->
    <div class="mb-6">
        <div class="flex items-center gap-3">
            <a href="{{ route('admin.banners.index') }}"
               class="text-gray-400 hover:text-primary-600 transition-colors">
                <i class="fas fa-arrow-left"></i>
            </a>
            <div>
                <h1 class="text-2xl font-bold text-white">Nouvelle Bannière</h1>
                <p class="text-gray-400">Créez une nouvelle bannière publicitaire</p>
            </div>
        </div>
    </div>

    @if(session('error'))
        <div class="mb-6 p-4 bg-red-900/20 border-l-4 border-red-500 rounded">
            <p class="text-red-300"><i class="fas fa-exclamation-circle mr-2"></i>{{ session('error') }}</p>
        </div>
    @endif

    <!-- Form -->
    <form action="{{ route('admin.banners.store') }}" method="POST" enctype="multipart/form-data">
        @csrf

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Main Information -->
            <div class="lg:col-span-2 space-y-6">
                <!-- Image Upload -->
                <div class="bg-dark-100 rounded-xl shadow-lg border border-dark-200 p-6">
                    <h3 class="text-lg font-semibold text-white mb-4 flex items-center">
                        <i class="fas fa-image text-primary-500 mr-2"></i>
                        Image de la Bannière <span class="text-red-500 ml-1">*</span>
                    </h3>

                    <div>
                        <label for="image" class="block text-sm font-medium text-gray-300 mb-2">
                            Fichier image
                        </label>
                        <input type="file" name="image" id="image" accept="image/*" required
                               class="w-full px-4 py-2 bg-dark-50 border border-dark-300 rounded-lg text-white file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:bg-primary-500 file:text-white hover:file:bg-primary-600 transition-all"
                               onchange="previewImage(event)">
                        <p class="mt-2 text-xs text-gray-400">
                            <i class="fas fa-info-circle mr-1"></i>
                            Formats acceptés: JPEG, PNG, JPG, GIF, WEBP (Max: 2MB)
                        </p>
                        @error('image')
                            <p class="mt-1 text-sm text-red-400">{{ $message }}</p>
                        @enderror

                        <!-- Image Preview -->
                        <div id="imagePreview" class="mt-4 hidden">
                            <p class="text-sm text-gray-400 mb-2">Aperçu:</p>
                            <img id="preview" class="rounded-lg border-2 border-dark-300 max-h-64" alt="Aperçu">
                        </div>
                    </div>
                </div>

                <!-- Content -->
                <div class="bg-dark-100 rounded-xl shadow-lg border border-dark-200 p-6">
                    <h3 class="text-lg font-semibold text-white mb-4 flex items-center">
                        <i class="fas fa-align-left text-primary-500 mr-2"></i>
                        Contenu
                    </h3>

                    <div class="space-y-4">
                        <!-- Title -->
                        <x-admin.translatable name="title" label="Titre (optionnel)" :model="null"
                            placeholder="Ex: Promotion d'été" help="Le titre affiché sur la bannière" />

                        <!-- Description -->
                        <x-admin.translatable name="description" label="Description/Texte (optionnel)" :model="null" type="textarea"
                            placeholder="Texte descriptif à afficher sur la bannière..." help="Texte qui accompagne la bannière" />

                        <!-- Link -->
                        <div>
                            <label for="link" class="block text-sm font-medium text-gray-300 mb-2">
                                <i class="fas fa-link text-primary-400 mr-1"></i>
                                Lien de redirection (optionnel)
                            </label>
                            <input type="url" name="link" id="link" value="{{ old('link') }}"
                                   class="w-full px-4 py-3 bg-dark-50 border border-dark-300 rounded-lg text-white placeholder-gray-500 focus:ring-2 focus:ring-primary-500 focus:border-transparent transition-all"
                                   placeholder="https://example.com">
                            <p class="mt-1 text-xs text-gray-400">URL de redirection lors du clic sur la bannière</p>
                            @error('link')
                                <p class="mt-1 text-sm text-red-400">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>
                </div>
            </div>

            <!-- Settings Sidebar -->
            <div class="lg:col-span-1 space-y-6">
                <!-- Position & Status -->
                <div class="bg-dark-100 rounded-xl shadow-lg border border-dark-200 p-6">
                    <h3 class="text-lg font-semibold text-white mb-4 flex items-center">
                        <i class="fas fa-cog text-primary-500 mr-2"></i>
                        Paramètres
                    </h3>

                    <div class="space-y-4">
                        <!-- Position -->
                        <div>
                            <label for="position" class="block text-sm font-medium text-gray-300 mb-2">
                                <i class="fas fa-sort-numeric-up text-primary-400 mr-1"></i>
                                Position d'affichage <span class="text-red-500">*</span>
                            </label>
                            <input type="number" name="position" id="position" value="{{ old('position', 0) }}"
                                   min="0" required
                                   class="w-full px-4 py-3 bg-dark-50 border border-dark-300 rounded-lg text-white placeholder-gray-500 focus:ring-2 focus:ring-primary-500 focus:border-transparent transition-all"
                                   placeholder="0">
                            <p class="mt-1 text-xs text-gray-400">
                                Ordre d'affichage (0 = non défini, 1 = première position, 2 = deuxième, etc.)
                            </p>
                            @error('position')
                                <p class="mt-1 text-sm text-red-400">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Active Status -->
                        <div class="pt-4 border-t border-dark-300">
                            <div class="flex items-center justify-between p-4 bg-dark-50 rounded-lg">
                                <div>
                                    <label for="is_active" class="text-sm font-medium text-white flex items-center">
                                        <i class="fas fa-toggle-on text-primary-500 mr-2"></i>
                                        Bannière active
                                    </label>
                                    <p class="text-xs text-gray-400 mt-1">
                                        Activer immédiatement cette bannière
                                    </p>
                                </div>
                                <label class="relative inline-flex items-center cursor-pointer">
                                    <input type="checkbox" name="is_active" id="is_active" value="1"
                                           {{ old('is_active', true) ? 'checked' : '' }}
                                           class="sr-only peer">
                                    <div class="w-11 h-6 bg-dark-300 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-primary-500/20 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-primary-500"></div>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Info Box -->
                <div class="bg-blue-500/10 border border-blue-500/30 rounded-xl p-4">
                    <div class="flex items-start">
                        <i class="fas fa-info-circle text-blue-500 text-lg mt-0.5 mr-2"></i>
                        <div>
                            <h4 class="text-sm font-semibold text-blue-400 mb-2">Conseils</h4>
                            <ul class="text-xs text-blue-300/80 space-y-1">
                                <li>• Utilisez des images de haute qualité</li>
                                <li>• Format recommandé: 1200x400px</li>
                                <li>• Position 0 = non triée</li>
                                <li>• Position 1 = première bannière</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Action Buttons -->
        <div class="mt-6 flex justify-between items-center">
            <a href="{{ route('admin.banners.index') }}"
               class="px-6 py-3 bg-dark-300 text-white rounded-lg hover:bg-dark-400 transition-all shadow-md">
                <i class="fas fa-times mr-2"></i> Annuler
            </a>
            <button type="submit"
                    class="px-8 py-3 bg-gradient-to-r from-primary-500 to-primary-600 text-white rounded-lg hover:from-primary-600 hover:to-primary-700 transition-all shadow-lg hover:shadow-xl">
                <i class="fas fa-save mr-2"></i> Créer la bannière
            </button>
        </div>
    </form>
</div>

@push('scripts')
<script>
function previewImage(event) {
    const preview = document.getElementById('preview');
    const previewContainer = document.getElementById('imagePreview');
    const file = event.target.files[0];

    if (file) {
        const reader = new FileReader();
        reader.onload = function(e) {
            preview.src = e.target.result;
            previewContainer.classList.remove('hidden');
        }
        reader.readAsDataURL(file);
    }
}
</script>
@endpush
@endsection
