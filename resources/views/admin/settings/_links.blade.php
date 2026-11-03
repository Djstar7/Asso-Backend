{{-- Liens partagés : à activer dans cet ordre, une fois les essais de chaque étape réussis. --}}
<div x-show="activeTab === 'links'" x-cloak>
    <form action="{{ route('admin.settings.links.update') }}" method="POST" class="p-6 space-y-6">
        @csrf
        @method('PUT')

        <div>
            <h3 class="text-lg font-semibold text-white mb-1">Liens produit et boutique</h3>
            <p class="text-sm text-gray-400">La vitrine publique reste accessible quels que soient ces réglages.</p>
        </div>

        <div>
            <label for="android_sha256_fingerprints" class="block text-sm font-medium text-gray-300 mb-2">
                Empreintes SHA-256 de signature Android
            </label>
            <textarea id="android_sha256_fingerprints" name="android_sha256_fingerprints" rows="3"
                      placeholder="AA:BB:CC:…"
                      class="w-full px-4 py-2 bg-dark-200 border border-dark-300 text-white rounded-lg font-mono text-xs focus:ring-2 focus:ring-primary-500">{{ old('android_sha256_fingerprints', $linkSettings['android_sha256_fingerprints']) }}</textarea>
            <p class="mt-1 text-xs text-gray-400">Play Console : Configuration, Intégrité de l'application, Signature de l'application. Une par ligne.</p>
            @error('android_sha256_fingerprints')
                <p class="mt-1 text-sm text-red-400">{{ $message }}</p>
            @enderror
        </div>

        <label class="flex items-start gap-3 cursor-pointer">
            <input type="checkbox" name="app_links_association_enabled" value="1"
                   {{ old('app_links_association_enabled', $linkSettings['app_links_association_enabled']) ? 'checked' : '' }}
                   class="mt-1 w-4 h-4 text-primary-500 focus:ring-primary-500 rounded">
            <span>
                <span class="block text-sm font-medium text-white">Ouvrir l'application depuis les liens</span>
                <span class="block text-xs text-gray-400">
                    Publie les fichiers d'association. À n'activer qu'une fois la nouvelle version disponible : Apple garde ces fichiers en cache plusieurs jours après un retrait.
                </span>
            </span>
        </label>

        <label class="flex items-start gap-3 cursor-pointer">
            <input type="checkbox" name="share_links_enabled" value="1"
                   {{ old('share_links_enabled', $linkSettings['share_links_enabled']) ? 'checked' : '' }}
                   class="mt-1 w-4 h-4 text-primary-500 focus:ring-primary-500 rounded">
            <span>
                <span class="block text-sm font-medium text-white">Afficher le bouton de partage dans l'application</span>
                <span class="block text-xs text-gray-400">Concerne les versions de l'application qui ont ce bouton. Effet au prochain démarrage de l'application.</span>
            </span>
        </label>

        <div class="border-t border-dark-300 pt-6 space-y-4">
            <div>
                <h3 class="text-lg font-semibold text-white mb-1">Espace vendeur web</h3>
                <p class="text-sm text-gray-400">Connexion par QR à {{ url('/vendor') }}. Couper ferme aussitôt les sessions ouvertes.</p>
            </div>

            <label class="flex items-start gap-3 cursor-pointer">
                <input type="checkbox" name="web_vendor_login_enabled" value="1"
                       {{ old('web_vendor_login_enabled', $linkSettings['web_vendor_login_enabled']) ? 'checked' : '' }}
                       class="mt-1 w-4 h-4 text-primary-500 focus:ring-primary-500 rounded">
                <span class="block text-sm font-medium text-white">Activer l'espace vendeur web</span>
            </label>

            <div>
                <label for="web_vendor_test_user_ids" class="block text-sm font-medium text-gray-300 mb-2">
                    Comptes de test (numéros d'utilisateur)
                </label>
                <input type="text" id="web_vendor_test_user_ids" name="web_vendor_test_user_ids"
                       value="{{ old('web_vendor_test_user_ids', $linkSettings['web_vendor_test_user_ids']) }}"
                       placeholder="12, 45"
                       class="w-full px-4 py-2 bg-dark-200 border border-dark-300 text-white rounded-lg focus:ring-2 focus:ring-primary-500">
                <p class="mt-1 text-xs text-gray-400">Renseigné : seuls ces comptes y ont accès. Vide : tous les vendeurs avec une boutique.</p>
                @error('web_vendor_test_user_ids')
                    <p class="mt-1 text-sm text-red-400">{{ $message }}</p>
                @enderror
            </div>
        </div>

        <div class="flex justify-end">
            <button type="submit" class="px-6 py-2 bg-gradient-to-r from-primary-500 to-primary-600 text-white rounded-lg">
                <i class="fas fa-save mr-2"></i> Enregistrer
            </button>
        </div>
    </form>
</div>
