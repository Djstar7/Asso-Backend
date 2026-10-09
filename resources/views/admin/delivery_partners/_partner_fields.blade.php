@php
    $input = 'w-full px-3 py-2 bg-dark-50 border border-dark-200 rounded-lg text-white focus:border-primary-500 focus:outline-none';
@endphp
<div class="grid grid-cols-1 md:grid-cols-3 gap-4">
    <div>
        <label class="block text-sm text-gray-400 mb-1">Nom <span class="text-red-500">*</span></label>
        <input type="text" name="name" required value="{{ old('name', $partner?->name) }}" class="{{ $input }}">
    </div>
    <div>
        <label class="block text-sm text-gray-400 mb-1">Téléphone</label>
        <input type="text" name="phone" value="{{ old('phone', $partner?->phone) }}" class="{{ $input }}">
    </div>
    <div>
        <label class="block text-sm text-gray-400 mb-1">Email</label>
        <input type="email" name="email" value="{{ old('email', $partner?->email) }}" class="{{ $input }}">
    </div>
    <div>
        <label class="block text-sm text-gray-400 mb-1">Catégorie principale (tri de la liste) <span class="text-red-500">*</span></label>
        <select name="service_type" class="{{ $input }}">
            @foreach(\App\Models\DelivererCompany::SERVICE_TYPES as $value => $label)
                <option value="{{ $value }}" @selected(old('service_type', $partner?->service_type ?? 'intercity') === $value)>{{ $label }}</option>
            @endforeach
        </select>
        <p class="mt-1 text-xs text-gray-500">Sert seulement au tri. Les services réels viennent de ce qui est configuré : Urbain pour la grille de ville, Interurbain / International pour les trajets — un partenaire peut en avoir plusieurs.</p>
    </div>
    <div>
        <label class="block text-sm text-gray-400 mb-1">Mode des trajets interurbains <span class="text-red-500">*</span></label>
        <select name="service_mode" class="{{ $input }}">
            @foreach(\App\Models\DelivererCompany::SERVICE_MODES as $value => $label)
                <option value="{{ $value }}" @selected(old('service_mode', $partner?->service_mode ?? 'agency_to_agency') === $value)>{{ $label }}</option>
            @endforeach
        </select>
        <p class="mt-1 text-xs text-gray-500">« D'agence en agence » : l'acheteur choisit le retrait en agence ou la livraison à domicile depuis l'agence d'arrivée. L'urbain est toujours livré à domicile.</p>
    </div>
    <div>
        <label class="block text-sm text-gray-400 mb-1">Poids max. accepté (kg)</label>
        <input type="number" name="max_weight_kg" step="0.1" min="0.1" value="{{ old('max_weight_kg', $partner?->max_weight_kg) }}" placeholder="Sans limite" class="{{ $input }}">
    </div>
    <div class="md:col-span-2">
        <label class="block text-sm text-gray-400 mb-1">Lien de suivi du transporteur</label>
        <input type="text" name="tracking_url_template" value="{{ old('tracking_url_template', $partner?->tracking_url_template) }}"
               placeholder="https://site-du-transporteur/suivi?numero={number}" class="{{ $input }}">
        <p class="mt-1 text-xs text-gray-500"><code>{number}</code> est remplacé par le numéro de suivi saisi par le vendeur.</p>
    </div>
    <div class="flex items-center gap-2 pt-6">
        <input type="checkbox" id="prices_exclude_vat" name="prices_exclude_vat" value="1"
               @checked(old('prices_exclude_vat', $partner?->prices_exclude_vat ?? true))
               class="w-4 h-4 rounded border-dark-300 bg-dark-50 text-primary-500">
        <label for="prices_exclude_vat" class="text-sm text-gray-300">Grille hors taxe (la TVA est ajoutée)</label>
    </div>
    <x-admin.translatable class="md:col-span-3" name="conditions" label="Conditions affichées à l'acheteur" :model="$partner" type="textarea" rows="3"
        placeholder="Dépôt et retrait en agence, délais hors week-end, objets interdits…" />
    <x-admin.translatable class="md:col-span-3" name="description" label="Description" :model="$partner" />
</div>
