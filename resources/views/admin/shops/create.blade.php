@extends('admin.layouts.app')

@section('content')
<div class="p-6">
    <!-- Header -->
    <div class="mb-6">
        <div class="flex items-center gap-3 mb-2">
            <a href="{{ route('admin.shops.index') }}"
               class="text-gray-400 hover:text-primary-600 transition-colors">
                <i class="fas fa-arrow-left"></i>
            </a>
            <h1 class="text-2xl font-bold text-white">Créer une Boutique</h1>
        </div>
        <p class="text-gray-400 ml-10">Ajoutez une nouvelle boutique à la plateforme</p>
    </div>

    <!-- Form -->
    <div class="bg-dark-100 rounded-xl shadow-lg p-6">
        <form action="{{ route('admin.shops.store') }}" method="POST" enctype="multipart/form-data">
            @csrf

            <div class="space-y-6">
                <!-- User Selection -->
                <div>
                    <label for="user_id" class="block text-sm font-medium text-white mb-2">
                        <i class="fas fa-user text-primary-500 mr-1"></i>
                        Propriétaire <span class="text-red-500">*</span>
                    </label>
                    <select name="user_id"
                            id="user_id"
                            required
                            class="w-full px-4 py-2 bg-dark-50 border border-dark-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500 text-white @error('user_id') border-red-500 @enderror">
                        <option value="">Sélectionnez un utilisateur</option>
                        @foreach($users as $user)
                            <option value="{{ $user->id }}" {{ old('user_id', request('user_id')) == $user->id ? 'selected' : '' }}>
                                {{ $user->name }} - {{ ucfirst($user->role) }}
                            </option>
                        @endforeach
                    </select>
                    @error('user_id')
                        <p class="mt-1 text-sm text-red-400">{{ $message }}</p>
                    @enderror
                </div>

                <!-- Shop Name -->
                <div>
                    <label for="name" class="block text-sm font-medium text-white mb-2">
                        <i class="fas fa-store text-primary-500 mr-1"></i>
                        Nom de la boutique <span class="text-red-500">*</span>
                    </label>
                    <input type="text"
                           name="name"
                           id="name"
                           value="{{ old('name') }}"
                           required
                           placeholder="Ex: Boutique de Marie"
                           class="w-full px-4 py-2 bg-dark-50 border border-dark-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500 text-white placeholder-gray-400 @error('name') border-red-500 @enderror">
                    @error('name')
                        <p class="mt-1 text-sm text-red-400">{{ $message }}</p>
                    @enderror
                    <p class="mt-1 text-xs text-gray-400">Un slug sera automatiquement généré à partir du nom</p>
                </div>

                <!-- Description -->
                <div>
                    <label for="description" class="block text-sm font-medium text-white mb-2">
                        <i class="fas fa-align-left text-primary-500 mr-1"></i>
                        Description
                    </label>
                    <textarea name="description"
                              id="description"
                              rows="4"
                              placeholder="Décrivez l'activité de la boutique..."
                              class="w-full px-4 py-2 bg-dark-50 border border-dark-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500 text-white placeholder-gray-400 @error('description') border-red-500 @enderror">{{ old('description') }}</textarea>
                    @error('description')
                        <p class="mt-1 text-sm text-red-400">{{ $message }}</p>
                    @enderror
                </div>

                <!-- Shop Link -->
                <div>
                    <label for="shop_link" class="block text-sm font-medium text-white mb-2">
                        <i class="fas fa-link text-primary-500 mr-1"></i>
                        Lien de la boutique
                    </label>
                    <input type="url"
                           name="shop_link"
                           id="shop_link"
                           value="{{ old('shop_link') }}"
                           placeholder="https://exemple.com/ma-boutique"
                           class="w-full px-4 py-2 bg-dark-50 border border-dark-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500 text-white placeholder-gray-400 @error('shop_link') border-red-500 @enderror">
                    @error('shop_link')
                        <p class="mt-1 text-sm text-red-400">{{ $message }}</p>
                    @enderror
                    <p class="mt-1 text-xs text-gray-400">URL externe vers la boutique (optionnel)</p>
                </div>

                <!-- Logo -->
                <div>
                    <label for="logo" class="block text-sm font-medium text-white mb-2">
                        <i class="fas fa-image text-primary-500 mr-1"></i>
                        Logo de la boutique
                    </label>
                    <input type="file"
                           name="logo"
                           id="logo"
                           accept="image/jpeg,image/png,image/jpg,image/gif,image/webp"
                           class="w-full px-4 py-2 bg-dark-50 border border-dark-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500 text-white @error('logo') border-red-500 @enderror"
                           onchange="previewLogo(event)">
                    @error('logo')
                        <p class="mt-1 text-sm text-red-400">{{ $message }}</p>
                    @enderror
                    <p class="mt-1 text-xs text-gray-400">Formats acceptés: JPEG, PNG, GIF, WebP. Taille max: 2MB (optionnel)</p>

                    <!-- Logo Preview -->
                    <div id="logoPreview" class="mt-3 hidden">
                        <img id="previewImage" src="" alt="Aperçu du logo" class="h-32 w-32 object-cover rounded-lg border-2 border-orange-500">
                    </div>
                </div>

                <!-- Localisation with Google Maps -->
                <div>
                    @include('admin.partials.google-map', [
                        'id' => 'shop-map',
                        'label' => 'Localisation de la boutique',
                        'latitude' => old('latitude'),
                        'longitude' => old('longitude'),
                        'address' => old('address'),
                        'zoom' => 15
                    ])
                </div>

                <!-- Status -->
                <div>
                    <label for="status" class="block text-sm font-medium text-white mb-2">
                        <i class="fas fa-toggle-on text-primary-500 mr-1"></i>
                        Statut <span class="text-red-500">*</span>
                    </label>
                    <div class="flex gap-6">
                        <label class="flex items-center cursor-pointer">
                            <input type="radio"
                                   name="status"
                                   value="active"
                                   {{ old('status', 'active') == 'active' ? 'checked' : '' }}
                                   class="w-4 h-4 text-primary-600 border-dark-300 focus:ring-primary-500">
                            <span class="ml-2 text-white">
                                <i class="fas fa-check-circle text-green-500 mr-1"></i>
                                Active
                            </span>
                        </label>
                        <label class="flex items-center cursor-pointer">
                            <input type="radio"
                                   name="status"
                                   value="inactive"
                                   {{ old('status') == 'inactive' ? 'checked' : '' }}
                                   class="w-4 h-4 text-primary-600 border-dark-300 focus:ring-primary-500">
                            <span class="ml-2 text-white">
                                <i class="fas fa-times-circle text-gray-400 mr-1"></i>
                                Inactive
                            </span>
                        </label>
                    </div>
                    @error('status')
                        <p class="mt-1 text-sm text-red-400">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="mt-8 flex gap-4">
                <button type="submit"
                        class="px-6 py-3 bg-gradient-to-r from-primary-500 to-primary-600 text-white rounded-lg hover:shadow-lg transition-all duration-200 shadow-lg hover:shadow-xl">
                    <i class="fas fa-save mr-2"></i>
                    Créer la boutique
                </button>
                <a href="{{ route('admin.shops.index') }}"
                   class="px-6 py-3 bg-gray-200 text-white rounded-lg hover:bg-gray-700 transition-all">
                    <i class="fas fa-times mr-2"></i>
                    Annuler
                </a>
            </div>
        </form>
    </div>

    <!-- Info Box -->
    <div class="mt-6 bg-blue-900/20 border-l-4 border-blue-500 rounded-lg p-4">
        <div class="flex items-start">
            <i class="fas fa-info-circle text-blue-500 text-xl mr-3 mt-1"></i>
            <div>
                <h4 class="font-semibold text-blue-800 mb-1">Information</h4>
                <p class="text-blue-700 text-sm">
                    Une boutique sera automatiquement liée au propriétaire sélectionné. Un utilisateur peut avoir plusieurs boutiques.
                    Le logo de la boutique pourra être ajouté ultérieurement via la modification.
                </p>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
function previewLogo(event) {
    const file = event.target.files[0];
    if (file) {
        const reader = new FileReader();
        reader.onload = function(e) {
            document.getElementById('previewImage').src = e.target.result;
            document.getElementById('logoPreview').classList.remove('hidden');
        }
        reader.readAsDataURL(file);
    }
}
</script>
@endpush
@endsection
