@extends('admin.layouts.app')

@php
    $currentVerification = $shop->isPending() ? 'pending' : ($shop->isVerified() ? 'verified' : 'rejected');
    $verification = old('verification_status', $currentVerification);
    $selectedCategories = old('categories', $shop->categories ?? []);
    $inputClass = 'w-full px-4 py-2 bg-dark-50 border border-dark-400 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500 text-white placeholder-gray-500';
@endphp

@section('content')
<div class="p-6">
    <!-- Header -->
    <div class="mb-6 flex flex-wrap items-center justify-between gap-4">
        <div>
            <div class="flex items-center gap-3 mb-2">
                <a href="{{ route('admin.shops.show', $shop) }}"
                   class="text-gray-400 hover:text-primary-600 transition-colors">
                    <i class="fas fa-arrow-left"></i>
                </a>
                <h1 class="text-2xl font-bold text-white">Modifier la Boutique</h1>
            </div>
            <p class="text-gray-400 ml-10">Modifiez les informations de {{ $shop->name }}</p>
        </div>
        <div class="flex flex-wrap gap-2 text-sm">
            <span class="px-3 py-1 rounded-full bg-dark-200 border border-dark-300 text-gray-300">
                <i class="fas fa-box text-blue-400 mr-1"></i>{{ $shop->products->count() }} produit(s)
            </span>
            <span class="px-3 py-1 rounded-full bg-dark-200 border border-dark-300 text-gray-300">
                <i class="fas fa-calendar text-green-400 mr-1"></i>Créée le {{ $shop->created_at->format('d/m/Y') }}
            </span>
            @if($shop->is_certified)
                <span class="px-3 py-1 rounded-full bg-yellow-500/20 border border-yellow-500/50 text-yellow-300">
                    <i class="fas fa-award mr-1"></i>Certifiée
                    @if($shop->certification_expires_at) jusqu'au {{ $shop->certification_expires_at->format('d/m/Y') }} @endif
                </span>
            @endif
        </div>
    </div>

    @if($errors->any())
        <div class="mb-6 p-4 bg-red-500/15 border border-red-500/50 rounded-xl">
            <p class="text-red-300 font-semibold mb-2"><i class="fas fa-exclamation-triangle mr-1"></i>Le formulaire contient des erreurs :</p>
            <ul class="list-disc list-inside text-sm text-red-300 space-y-1">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form action="{{ route('admin.shops.update', $shop) }}" method="POST" enctype="multipart/form-data" class="space-y-6">
        @csrf
        @method('PUT')

        <!-- 1. Identité -->
        <section class="bg-dark-200 border border-dark-300 border-l-4 border-l-primary-500 rounded-xl p-6 shadow-lg">
            <h2 class="text-lg font-semibold text-white mb-5 flex items-center gap-2">
                <span class="h-8 w-8 rounded-lg bg-primary-500/20 text-primary-400 flex items-center justify-center"><i class="fas fa-store"></i></span>
                Identité de la boutique
            </h2>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                <div>
                    <label for="user_id" class="block text-sm font-medium text-gray-200 mb-2">
                        Propriétaire <span class="text-red-500">*</span>
                    </label>
                    <select name="user_id" id="user_id" required
                            class="{{ $inputClass }} @error('user_id') border-red-500 @enderror">
                        <option value="">Sélectionnez un utilisateur</option>
                        @foreach($users as $user)
                            <option value="{{ $user->id }}" {{ old('user_id', $shop->user_id) == $user->id ? 'selected' : '' }}>
                                {{ $user->name }} — {{ ucfirst($user->role) }}{{ $user->email ? ' ('.$user->email.')' : '' }}
                            </option>
                        @endforeach
                    </select>
                    @error('user_id')<p class="mt-1 text-sm text-red-400">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="name" class="block text-sm font-medium text-gray-200 mb-2">
                        Nom de la boutique <span class="text-red-500">*</span>
                    </label>
                    <input type="text" name="name" id="name" required
                           value="{{ old('name', $shop->name) }}"
                           placeholder="Ex: Boutique de Marie"
                           class="{{ $inputClass }} @error('name') border-red-500 @enderror">
                    @error('name')<p class="mt-1 text-sm text-red-400">{{ $message }}</p>@enderror
                    <p class="mt-1 text-xs text-gray-400">
                        Slug actuel : <span class="font-mono font-semibold text-gray-300">{{ $shop->slug }}</span> (mis à jour si le nom change)
                    </p>
                </div>

                <div class="md:col-span-2">
                    <label for="description" class="block text-sm font-medium text-gray-200 mb-2">Description</label>
                    <textarea name="description" id="description" rows="4"
                              placeholder="Décrivez l'activité de la boutique..."
                              class="{{ $inputClass }} @error('description') border-red-500 @enderror">{{ old('description', $shop->description) }}</textarea>
                    @error('description')<p class="mt-1 text-sm text-red-400">{{ $message }}</p>@enderror
                </div>
            </div>
        </section>

        <!-- 2. Contact -->
        <section class="bg-dark-200 border border-dark-300 border-l-4 border-l-blue-500 rounded-xl p-6 shadow-lg">
            <h2 class="text-lg font-semibold text-white mb-5 flex items-center gap-2">
                <span class="h-8 w-8 rounded-lg bg-blue-500/20 text-blue-400 flex items-center justify-center"><i class="fas fa-address-book"></i></span>
                Contact
            </h2>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
                <div>
                    <label for="phone" class="block text-sm font-medium text-gray-200 mb-2">Téléphone</label>
                    <input type="tel" name="phone" id="phone"
                           value="{{ old('phone', $shop->phone) }}"
                           placeholder="+237 6XX XX XX XX"
                           class="{{ $inputClass }} @error('phone') border-red-500 @enderror">
                    @error('phone')<p class="mt-1 text-sm text-red-400">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="email" class="block text-sm font-medium text-gray-200 mb-2">E-mail</label>
                    <input type="email" name="email" id="email"
                           value="{{ old('email', $shop->email) }}"
                           placeholder="contact@boutique.com"
                           class="{{ $inputClass }} @error('email') border-red-500 @enderror">
                    @error('email')<p class="mt-1 text-sm text-red-400">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="shop_link" class="block text-sm font-medium text-gray-200 mb-2">Lien externe</label>
                    <input type="url" name="shop_link" id="shop_link"
                           value="{{ old('shop_link', $shop->shop_link) }}"
                           placeholder="https://exemple.com/ma-boutique"
                           class="{{ $inputClass }} @error('shop_link') border-red-500 @enderror">
                    @error('shop_link')<p class="mt-1 text-sm text-red-400">{{ $message }}</p>@enderror
                </div>
            </div>
        </section>

        <!-- 3. Catégories -->
        <section class="bg-dark-200 border border-dark-300 border-l-4 border-l-purple-500 rounded-xl p-6 shadow-lg">
            <h2 class="text-lg font-semibold text-white mb-1 flex items-center gap-2">
                <span class="h-8 w-8 rounded-lg bg-purple-500/20 text-purple-400 flex items-center justify-center"><i class="fas fa-tags"></i></span>
                Catégories
            </h2>
            <p class="text-sm text-gray-400 mb-5 ml-10">Cochez les catégories dans lesquelles la boutique vend.</p>

            @if($categories->isEmpty())
                <p class="text-sm text-gray-400">Aucune catégorie dans le catalogue.</p>
            @else
                <div class="flex flex-wrap gap-2">
                    @foreach($categories as $category)
                        <label class="cursor-pointer">
                            <input type="checkbox" name="categories[]" value="{{ $category }}" class="peer sr-only"
                                   {{ in_array($category, $selectedCategories, true) ? 'checked' : '' }}>
                            <span class="inline-flex items-center gap-1 px-3 py-1.5 rounded-full text-sm border transition-all
                                         bg-dark-50 border-dark-400 text-gray-300 hover:border-purple-400
                                         peer-checked:bg-purple-600 peer-checked:border-purple-500 peer-checked:text-white peer-focus-visible:ring-2 peer-focus-visible:ring-purple-400">
                                {{ $category }}
                            </span>
                        </label>
                    @endforeach
                </div>
            @endif
            @error('categories')<p class="mt-2 text-sm text-red-400">{{ $message }}</p>@enderror
        </section>

        <!-- 4. Logo -->
        <section class="bg-dark-200 border border-dark-300 border-l-4 border-l-pink-500 rounded-xl p-6 shadow-lg">
            <h2 class="text-lg font-semibold text-white mb-5 flex items-center gap-2">
                <span class="h-8 w-8 rounded-lg bg-pink-500/20 text-pink-400 flex items-center justify-center"><i class="fas fa-image"></i></span>
                Logo
            </h2>

            <div class="flex flex-col md:flex-row gap-6 md:items-start">
                <div class="flex gap-4">
                    <div>
                        <p class="text-xs text-gray-400 mb-2">Actuel</p>
                        <div class="h-32 w-32 rounded-lg border-2 border-dark-400 bg-dark-50 flex items-center justify-center overflow-hidden">
                            @if($shop->logo_url)
                                <img src="{{ $shop->logo_url }}" alt="Logo de {{ $shop->name }}" class="h-full w-full object-cover"
                                     onerror="this.replaceWith(Object.assign(document.createElement('span'), {className: 'text-xs text-red-400 px-2 text-center', textContent: 'Logo introuvable'}))">
                            @else
                                <i class="fas fa-store text-3xl text-gray-600"></i>
                            @endif
                        </div>
                    </div>
                    <div id="logoPreview" class="hidden">
                        <p class="text-xs text-gray-400 mb-2">Nouveau</p>
                        <img id="previewImage" src="" alt="Aperçu du logo" class="h-32 w-32 object-cover rounded-lg border-2 border-primary-500">
                    </div>
                </div>

                <div class="flex-1">
                    <input type="file" name="logo" id="logo"
                           accept="image/jpeg,image/png,image/jpg,image/gif,image/webp"
                           onchange="previewLogo(event)"
                           class="w-full text-sm text-gray-300 bg-dark-50 border border-dark-400 rounded-lg file:mr-4 file:py-2 file:px-4 file:border-0 file:bg-pink-600 file:text-white hover:file:bg-pink-700 @error('logo') border-red-500 @enderror">
                    @error('logo')<p class="mt-1 text-sm text-red-400">{{ $message }}</p>@enderror
                    <p class="mt-2 text-xs text-gray-400">
                        Formats acceptés : JPEG, PNG, GIF, WebP. Taille max : 2 Mo.
                        @if($shop->logo_url)<br><span class="text-primary-400">Laisser vide pour conserver le logo actuel.</span>@endif
                    </p>
                </div>
            </div>
        </section>

        <!-- 5. Localisation -->
        <section class="bg-dark-200 border border-dark-300 border-l-4 border-l-teal-500 rounded-xl p-6 shadow-lg">
            @include('admin.partials.google-map', [
                'id' => 'shop-map',
                'label' => 'Localisation de la boutique',
                'latitude' => old('latitude', $shop->latitude),
                'longitude' => old('longitude', $shop->longitude),
                'address' => old('address', $shop->address),
                'zoom' => 15,
                'wrapperClass' => '',
            ])

            <div class="grid grid-cols-1 md:grid-cols-3 gap-5 mt-5">
                <div>
                    <label for="city" class="block text-sm font-medium text-gray-200 mb-2">Ville</label>
                    <input type="text" name="city" id="city"
                           value="{{ old('city', $shop->city) }}"
                           placeholder="Ex: Douala"
                           class="{{ $inputClass }} @error('city') border-red-500 @enderror">
                    @error('city')<p class="mt-1 text-sm text-red-400">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="country" class="block text-sm font-medium text-gray-200 mb-2">Pays</label>
                    <input type="text" name="country" id="country"
                           value="{{ old('country', $shop->country) }}"
                           placeholder="Ex: Cameroun"
                           class="{{ $inputClass }} @error('country') border-red-500 @enderror">
                    @error('country')<p class="mt-1 text-sm text-red-400">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-200 mb-2">Quartier de livraison</label>
                    <div class="px-4 py-2 bg-dark-50/60 border border-dashed border-dark-400 rounded-lg text-gray-300">
                        {{ $shop->quarter ?: '—' }}
                    </div>
                    <p class="mt-1 text-xs text-gray-400">Recalculé automatiquement depuis la position.</p>
                </div>
            </div>
            <p class="mt-3 text-xs text-gray-400">
                <i class="fas fa-info-circle text-teal-400 mr-1"></i>
                Ville et pays laissés vides sont déduits de l'adresse.
            </p>
        </section>

        <!-- 6. Vérification -->
        <section class="bg-dark-200 border border-dark-300 border-l-4 border-l-green-500 rounded-xl p-6 shadow-lg">
            <h2 class="text-lg font-semibold text-white mb-5 flex items-center gap-2">
                <span class="h-8 w-8 rounded-lg bg-green-500/20 text-green-400 flex items-center justify-center"><i class="fas fa-certificate"></i></span>
                Statut de vérification <span class="text-red-500">*</span>
            </h2>

            @if($shop->isVerified())
                <div class="mb-4 p-3 bg-green-500/15 border border-green-500/50 rounded-lg">
                    <p class="text-green-300 text-sm">
                        <i class="fas fa-check-circle mr-1"></i>
                        Vérifiée le {{ $shop->verified_at->format('d/m/Y à H:i') }}
                        @if($shop->verifier) par <span class="font-semibold">{{ $shop->verifier->name }}</span>@endif
                    </p>
                </div>
            @elseif($shop->isRejected())
                <div class="mb-4 p-3 bg-red-500/15 border border-red-500/50 rounded-lg">
                    <p class="text-red-300 text-sm">
                        <i class="fas fa-times-circle mr-1"></i>
                        Rejetée le {{ $shop->rejected_at->format('d/m/Y à H:i') }}
                        @if($shop->rejector) par <span class="font-semibold">{{ $shop->rejector->name }}</span>@endif
                    </p>
                </div>
            @else
                <div class="mb-4 p-3 bg-orange-500/15 border border-orange-500/50 rounded-lg">
                    <p class="text-orange-300 text-sm"><i class="fas fa-clock mr-1"></i>En attente de vérification</p>
                </div>
            @endif

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                @foreach([
                    'pending' => ['En attente', 'fa-clock', 'orange'],
                    'verified' => ['Vérifiée', 'fa-check-circle', 'green'],
                    'rejected' => ['Rejetée', 'fa-times-circle', 'red'],
                ] as $value => [$text, $icon, $color])
                    <label class="flex items-center cursor-pointer p-3 bg-dark-50 border border-dark-400 rounded-lg hover:border-{{ $color }}-500 transition-all has-[:checked]:border-{{ $color }}-500 has-[:checked]:bg-{{ $color }}-500/10">
                        <input type="radio" name="verification_status" value="{{ $value }}"
                               {{ $verification === $value ? 'checked' : '' }}
                               class="w-4 h-4 text-primary-600 border-dark-300 focus:ring-primary-500 verification-radio">
                        <span class="ml-2 text-white"><i class="fas {{ $icon }} text-{{ $color }}-400 mr-1"></i>{{ $text }}</span>
                    </label>
                @endforeach
            </div>
            @error('verification_status')<p class="mt-1 text-sm text-red-400">{{ $message }}</p>@enderror

            <div id="rejectionReasonField" class="mt-4 {{ $verification === 'rejected' ? '' : 'hidden' }}">
                <label for="rejection_reason" class="block text-sm font-medium text-gray-200 mb-2">
                    Raison du rejet <span class="text-red-500">*</span>
                </label>
                <textarea name="rejection_reason" id="rejection_reason" rows="3" maxlength="500"
                          placeholder="Expliquez pourquoi cette boutique est rejetée..."
                          class="{{ $inputClass }} @error('rejection_reason') border-red-500 @enderror">{{ old('rejection_reason', $shop->rejection_reason) }}</textarea>
                @error('rejection_reason')<p class="mt-1 text-sm text-red-400">{{ $message }}</p>@enderror
            </div>
        </section>

        <!-- 7. Activité & livraison -->
        <section class="bg-dark-200 border border-dark-300 border-l-4 border-l-yellow-500 rounded-xl p-6 shadow-lg">
            <h2 class="text-lg font-semibold text-white mb-5 flex items-center gap-2">
                <span class="h-8 w-8 rounded-lg bg-yellow-500/20 text-yellow-400 flex items-center justify-center"><i class="fas fa-sliders-h"></i></span>
                Activité & livraison
            </h2>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                <div>
                    <p class="block text-sm font-medium text-gray-200 mb-2">Statut d'activité <span class="text-red-500">*</span></p>
                    <div class="flex gap-3">
                        <label class="flex-1 flex items-center cursor-pointer p-3 bg-dark-50 border border-dark-400 rounded-lg has-[:checked]:border-green-500 has-[:checked]:bg-green-500/10">
                            <input type="radio" name="status" value="active"
                                   {{ old('status', $shop->status) == 'active' ? 'checked' : '' }}
                                   class="w-4 h-4 text-primary-600 border-dark-300 focus:ring-primary-500">
                            <span class="ml-2 text-white"><i class="fas fa-check-circle text-green-400 mr-1"></i>Active</span>
                        </label>
                        <label class="flex-1 flex items-center cursor-pointer p-3 bg-dark-50 border border-dark-400 rounded-lg has-[:checked]:border-gray-400 has-[:checked]:bg-gray-500/10">
                            <input type="radio" name="status" value="inactive"
                                   {{ old('status', $shop->status) == 'inactive' ? 'checked' : '' }}
                                   class="w-4 h-4 text-primary-600 border-dark-300 focus:ring-primary-500">
                            <span class="ml-2 text-white"><i class="fas fa-times-circle text-gray-400 mr-1"></i>Inactive</span>
                        </label>
                    </div>
                    @error('status')<p class="mt-1 text-sm text-red-400">{{ $message }}</p>@enderror
                    <p class="mt-2 text-xs text-gray-400">Une boutique peut être vérifiée mais inactive.</p>
                </div>

                <div>
                    <p class="block text-sm font-medium text-gray-200 mb-2">Livraison gratuite</p>
                    <input type="hidden" name="free_delivery" value="0">
                    <label class="flex items-center justify-between cursor-pointer p-3 bg-dark-50 border border-dark-400 rounded-lg has-[:checked]:border-green-500">
                        <span class="text-white text-sm"><i class="fas fa-truck text-green-400 mr-2"></i>Sur toute la boutique</span>
                        <input type="checkbox" name="free_delivery" value="1" class="peer sr-only"
                               {{ old('free_delivery', $shop->free_delivery) ? 'checked' : '' }}>
                        <span class="relative inline-flex h-7 w-12 flex-shrink-0 rounded-full bg-gray-600 transition-colors peer-checked:bg-green-600
                                     after:content-[''] after:absolute after:top-1 after:left-1 after:h-5 after:w-5 after:rounded-full after:bg-white after:transition-transform peer-checked:after:translate-x-5"></span>
                    </label>
                    @error('free_delivery')<p class="mt-1 text-sm text-red-400">{{ $message }}</p>@enderror
                    <p class="mt-2 text-xs text-gray-400">Financée par le vendeur. Les produits réglés un à un gardent leur choix.</p>
                </div>
            </div>
        </section>

        <!-- Actions -->
        <div class="sticky bottom-0 z-10 -mx-6 px-6 py-4 bg-dark-50/95 backdrop-blur border-t border-dark-300 flex flex-wrap gap-4">
            <button type="submit"
                    class="px-6 py-3 bg-gradient-to-r from-primary-500 to-primary-600 text-white rounded-lg shadow-lg hover:shadow-xl transition-all">
                <i class="fas fa-save mr-2"></i>Mettre à jour
            </button>
            <a href="{{ route('admin.shops.show', $shop) }}"
               class="px-6 py-3 bg-dark-300 text-white rounded-lg hover:bg-dark-400 transition-all">
                <i class="fas fa-times mr-2"></i>Annuler
            </a>
        </div>
    </form>
</div>

@push('scripts')
<script>
function previewLogo(event) {
    const file = event.target.files[0];
    const preview = document.getElementById('logoPreview');
    if (!file) {
        preview.classList.add('hidden');
        return;
    }
    const reader = new FileReader();
    reader.onload = function (e) {
        document.getElementById('previewImage').src = e.target.result;
        preview.classList.remove('hidden');
    };
    reader.readAsDataURL(file);
}

document.addEventListener('DOMContentLoaded', function () {
    const rejectionReasonField = document.getElementById('rejectionReasonField');
    const rejectionReason = document.getElementById('rejection_reason');

    function syncRejectionField() {
        const rejected = document.querySelector('.verification-radio[value="rejected"]').checked;
        rejectionReasonField.classList.toggle('hidden', !rejected);
        rejectionReason.required = rejected;
    }

    document.querySelectorAll('.verification-radio').forEach(radio => radio.addEventListener('change', syncRejectionField));
    syncRejectionField();
});
</script>
@endpush
@endsection
