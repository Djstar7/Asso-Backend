@extends('admin.layouts.app')

@section('content')
<div class="p-6 space-y-6">
    <!-- En-tête -->
    <div class="flex justify-between items-center">
        <div>
            <h1 class="text-3xl font-bold text-gray-100">Modifier le Package</h1>
            <p class="text-gray-400 mt-1">{{ $package->name }}</p>
        </div>
        <a href="{{ route('admin.packages.index') }}"
            class="px-4 py-2 bg-dark-100 border border-dark-200 hover:bg-dark-50 text-gray-100 rounded-lg transition-colors">
            <i class="fas fa-arrow-left mr-2"></i>
            Retour
        </a>
    </div>

    <!-- Formulaire -->
    <div class="bg-dark-100 border border-dark-200 rounded-lg p-6">
        <form action="{{ route('admin.packages.update', $package) }}" method="POST" id="packageForm">
            @csrf
            @method('PUT')

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- Type de package -->
                <div class="col-span-2">
                    <label for="type" class="block text-sm font-medium text-gray-300 mb-2">Type de package <span class="text-red-500">*</span></label>
                    <select id="type" name="type" required
                        class="w-full px-4 py-2 bg-dark-50 border border-dark-200 rounded-lg text-gray-100 focus:ring-2 focus:ring-blue-500 @error('type') border-red-500 @enderror">
                        <option value="">Sélectionner un type</option>
                        <option value="storage" {{ old('type', $package->type) === 'storage' ? 'selected' : '' }}>Stockage</option>
                        <option value="boost" {{ old('type', $package->type) === 'boost' ? 'selected' : '' }}>Boost Sponsoring</option>
                        <option value="certification" {{ old('type', $package->type) === 'certification' ? 'selected' : '' }}>Certification</option>
                    </select>
                    @error('type')
                        <p class="text-red-500 text-sm mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <!-- Nom -->
                <div class="col-span-2">
                    <label for="name" class="block text-sm font-medium text-gray-300 mb-2">Nom du package <span class="text-red-500">*</span></label>
                    <input type="text" id="name" name="name" value="{{ old('name', $package->name) }}" required
                        class="w-full px-4 py-2 bg-dark-50 border border-dark-200 rounded-lg text-gray-100 focus:ring-2 focus:ring-blue-500 @error('name') border-red-500 @enderror"
                        placeholder="Ex: Premium Pro, Boost 1000, Certification Or">
                    @error('name')
                        <p class="text-red-500 text-sm mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <!-- Description -->
                <div class="col-span-2">
                    <label for="description" class="block text-sm font-medium text-gray-300 mb-2">Description</label>
                    <textarea id="description" name="description" rows="4"
                        class="w-full px-4 py-2 bg-dark-50 border border-dark-200 rounded-lg text-gray-100 focus:ring-2 focus:ring-blue-500 @error('description') border-red-500 @enderror"
                        placeholder="Décrivez les avantages et caractéristiques de ce package">{{ old('description', $package->description) }}</textarea>
                    @error('description')
                        <p class="text-red-500 text-sm mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <!-- Prix -->
                <div>
                    <label for="price" class="block text-sm font-medium text-gray-300 mb-2">Prix (XOF) <span class="text-red-500">*</span></label>
                    <input type="number" id="price" name="price" value="{{ old('price', $package->price) }}" required min="0" step="0.01"
                        class="w-full px-4 py-2 bg-dark-50 border border-dark-200 rounded-lg text-gray-100 focus:ring-2 focus:ring-blue-500 @error('price') border-red-500 @enderror"
                        placeholder="Ex: 5000">
                    @error('price')
                        <p class="text-red-500 text-sm mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <!-- Durée en jours -->
                <div>
                    <label for="duration_days" class="block text-sm font-medium text-gray-300 mb-2">Durée (jours) <span class="text-red-500">*</span></label>
                    <input type="number" id="duration_days" name="duration_days" value="{{ old('duration_days', $package->duration_days) }}" required min="1"
                        class="w-full px-4 py-2 bg-dark-50 border border-dark-200 rounded-lg text-gray-100 focus:ring-2 focus:ring-blue-500 @error('duration_days') border-red-500 @enderror"
                        placeholder="Ex: 30 (pour 1 mois)">
                    @error('duration_days')
                        <p class="text-red-500 text-sm mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <!-- Taille de stockage (visible uniquement pour type=storage) -->
                <div id="storage_field" class="hidden">
                    <label for="storage_size_mb" class="block text-sm font-medium text-gray-300 mb-2">Taille de stockage (Mo)</label>
                    <input type="number" id="storage_size_mb" name="storage_size_mb" value="{{ old('storage_size_mb', $package->storage_size_mb) }}" min="1"
                        class="w-full px-4 py-2 bg-dark-50 border border-dark-200 rounded-lg text-gray-100 focus:ring-2 focus:ring-blue-500 @error('storage_size_mb') border-red-500 @enderror"
                        placeholder="Ex: 1024 (pour 1 Go)">
                    @error('storage_size_mb')
                        <p class="text-red-500 text-sm mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <!-- Nombre d'utilisateurs atteints (visible uniquement pour type=boost) -->
                <div id="reach_field" class="hidden">
                    <label for="reach_users" class="block text-sm font-medium text-gray-300 mb-2">Nombre d'utilisateurs atteints</label>
                    <input type="number" id="reach_users" name="reach_users" value="{{ old('reach_users', $package->reach_users) }}" min="1"
                        class="w-full px-4 py-2 bg-dark-50 border border-dark-200 rounded-lg text-gray-100 focus:ring-2 focus:ring-blue-500 @error('reach_users') border-red-500 @enderror"
                        placeholder="Ex: 1000">
                    @error('reach_users')
                        <p class="text-red-500 text-sm mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <!-- Bénéfices (visible uniquement pour type=certification) -->
                <div id="benefits_field" class="col-span-2 hidden">
                    <label for="benefits" class="block text-sm font-medium text-gray-300 mb-2">Bénéfices</label>
                    <div id="benefits_container" class="space-y-2">
                        @php
                            $benefits = old('benefits', $package->benefits ?? []);
                        @endphp
                        @if($benefits)
                            @foreach($benefits as $index => $benefit)
                                <div class="benefit-item flex gap-2">
                                    <input type="text" name="benefits[]" value="{{ $benefit }}"
                                        class="flex-1 px-4 py-2 bg-dark-50 border border-dark-200 rounded-lg text-gray-100 focus:ring-2 focus:ring-blue-500"
                                        placeholder="Ex: Badge de certification visible">
                                    <button type="button" onclick="removeBenefit(this)"
                                        class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-lg transition-colors">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            @endforeach
                        @endif
                    </div>
                    <button type="button" onclick="addBenefit()"
                        class="mt-2 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition-colors">
                        <i class="fas fa-plus mr-2"></i>
                        Ajouter un bénéfice
                    </button>
                    @error('benefits')
                        <p class="text-red-500 text-sm mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <!-- Ordre d'affichage -->
                <div>
                    <label for="order" class="block text-sm font-medium text-gray-300 mb-2">Ordre d'affichage</label>
                    <input type="number" id="order" name="order" value="{{ old('order', $package->order ?? 0) }}" min="0"
                        class="w-full px-4 py-2 bg-dark-50 border border-dark-200 rounded-lg text-gray-100 focus:ring-2 focus:ring-blue-500 @error('order') border-red-500 @enderror"
                        placeholder="0">
                    @error('order')
                        <p class="text-red-500 text-sm mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <!-- Options -->
                <div class="col-span-2 space-y-4">
                    <div class="flex items-center">
                        <input type="checkbox" id="is_active" name="is_active" value="1" {{ old('is_active', $package->is_active) ? 'checked' : '' }}
                            class="w-4 h-4 text-blue-600 bg-dark-50 border-dark-200 rounded focus:ring-blue-500">
                        <label for="is_active" class="ml-2 text-sm font-medium text-gray-300">Package actif</label>
                    </div>

                    <div class="flex items-center">
                        <input type="checkbox" id="is_popular" name="is_popular" value="1" {{ old('is_popular', $package->is_popular) ? 'checked' : '' }}
                            class="w-4 h-4 text-blue-600 bg-dark-50 border-dark-200 rounded focus:ring-blue-500">
                        <label for="is_popular" class="ml-2 text-sm font-medium text-gray-300">Marquer comme populaire</label>
                    </div>
                </div>
            </div>

            <!-- Boutons d'action -->
            <div class="flex justify-between items-center gap-4 mt-6 pt-6 border-t border-dark-200">
                <div>
                    {{-- Le bouton supprimer est géré par un formulaire séparé en dehors du form principal --}}
                    <button type="button" id="deletePackageBtn"
                        class="px-6 py-2 bg-red-600 hover:bg-red-700 text-white rounded-lg transition-colors">
                        <i class="fas fa-trash mr-2"></i>
                        Supprimer
                    </button>
                </div>

                <div class="flex gap-4">
                    <a href="{{ route('admin.packages.index') }}"
                        class="px-6 py-2 bg-dark-50 border border-dark-200 hover:bg-dark-100 text-gray-100 rounded-lg transition-colors">
                        Annuler
                    </a>
                    <button type="submit"
                        class="px-6 py-2 bg-gradient-to-r from-primary-500 to-primary-600 text-white rounded-lg hover:shadow-lg transition-all">
                        <i class="fas fa-save mr-2"></i>
                        Mettre à jour
                    </button>
                </div>
            </div>
        </form>

        {{-- Formulaire de suppression séparé (en dehors du formulaire de mise à jour) --}}
        <form id="deletePackageForm" action="{{ route('admin.packages.destroy', $package) }}" method="POST" class="hidden">
            @csrf
            @method('DELETE')
        </form>
    </div>
</div>

@push('scripts')
<script>
    // Gestion de l'affichage des champs selon le type
    document.getElementById('type').addEventListener('change', function() {
        const type = this.value;

        // Masquer tous les champs spécifiques
        document.getElementById('storage_field').classList.add('hidden');
        document.getElementById('reach_field').classList.add('hidden');
        document.getElementById('benefits_field').classList.add('hidden');

        // Afficher le champ correspondant
        if (type === 'storage') {
            document.getElementById('storage_field').classList.remove('hidden');
        } else if (type === 'boost') {
            document.getElementById('reach_field').classList.remove('hidden');
        } else if (type === 'certification') {
            document.getElementById('benefits_field').classList.remove('hidden');
        }
    });

    // Déclencher l'événement au chargement de la page pour les anciennes valeurs
    document.getElementById('type').dispatchEvent(new Event('change'));

    // Gestion des bénéfices
    function addBenefit() {
        const container = document.getElementById('benefits_container');
        const div = document.createElement('div');
        div.className = 'benefit-item flex gap-2';
        div.innerHTML = `
            <input type="text" name="benefits[]"
                class="flex-1 px-4 py-2 bg-dark-50 border border-dark-200 rounded-lg text-gray-100 focus:ring-2 focus:ring-blue-500"
                placeholder="Ex: Badge de certification visible">
            <button type="button" onclick="removeBenefit(this)"
                class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-lg transition-colors">
                <i class="fas fa-trash"></i>
            </button>
        `;
        container.appendChild(div);
    }

    function removeBenefit(button) {
        button.parentElement.remove();
    }

    // Gestion du bouton supprimer (formulaire séparé pour éviter le conflit avec le formulaire de mise à jour)
    document.getElementById('deletePackageBtn').addEventListener('click', function() {
        window.customConfirm('Êtes-vous sûr de vouloir supprimer ce package ? Cette action est irréversible.', function () {
            document.getElementById('deletePackageForm').submit();
        });
    });
</script>
@endpush
@endsection
