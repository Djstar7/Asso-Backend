@php
    $input = 'w-full px-3 py-2 bg-dark-50 border border-dark-200 rounded-lg text-white focus:border-primary-500 focus:outline-none text-sm';
    $ranges = $route?->pricing_data['ranges'] ?? [
        ['min' => 0, 'max' => 2, 'price' => '', 'label' => 'Plis & paquets'],
        ['min' => 2, 'max' => 10, 'price' => '', 'label' => 'Colis'],
    ];
@endphp
<form action="{{ $action }}" method="POST"
      x-data="{ ranges: @js(array_values($ranges)), international: '{{ $route?->origin_country ?? 'CM' }}' !== '{{ $route?->destination_country ?? 'CM' }}' }">
    @csrf
    @isset($method) @method($method) @endisset
    <div class="grid grid-cols-2 md:grid-cols-6 gap-3">
        <div>
            <label class="block text-xs text-gray-400 mb-1">Pays de départ</label>
            <select name="origin_country" class="{{ $input }}"
                    @change="international = $event.target.value !== $el.form.destination_country.value">
                @foreach($countries as $code => $name)
                    <option value="{{ $code }}" @selected(($route?->origin_country ?? 'CM') === $code)>{{ $name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs text-gray-400 mb-1">Ville de départ</label>
            <input type="text" name="origin_city" value="{{ $route?->origin_city ?? 'Douala' }}" placeholder="Toutes" class="{{ $input }}">
        </div>
        <div>
            <label class="block text-xs text-gray-400 mb-1">Pays d'arrivée</label>
            <select name="destination_country" class="{{ $input }}"
                    @change="international = $event.target.value !== $el.form.origin_country.value">
                @foreach($countries as $code => $name)
                    <option value="{{ $code }}" @selected(($route?->destination_country ?? 'CM') === $code)>{{ $name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs text-gray-400 mb-1">Ville d'arrivée</label>
            <input type="text" name="destination_city" value="{{ $route?->destination_city }}" placeholder="Toutes" class="{{ $input }}">
        </div>
        <div>
            <label class="block text-xs text-gray-400 mb-1">Délai de route</label>
            <input type="text" name="lead_time" value="{{ $route?->lead_time }}" placeholder="24 h" class="{{ $input }}">
        </div>
        <div>
            <label class="block text-xs text-gray-400 mb-1">Délai de route (EN)</label>
            <input type="text" name="translations[en][lead_time]" value="{{ $route?->getTranslation('lead_time', 'en') }}" placeholder="Facultatif" class="{{ $input }}">
        </div>
    </div>

    <div class="mt-3">
        <label class="block text-xs text-gray-400 mb-1">Tranches de poids (prix de la grille du partenaire)</label>
        <template x-for="(range, i) in ranges" :key="i">
            <div class="grid grid-cols-12 gap-2 mb-2">
                <input type="text" :name="`ranges[${i}][label]`" x-model="range.label" placeholder="Libellé (Colis…)" class="col-span-2 {{ $input }}">
                <input type="text" :name="`ranges[${i}][label_en]`" x-model="range.label_en" placeholder="EN (Parcel…)" class="col-span-2 {{ $input }}">
                <input type="number" :name="`ranges[${i}][min]`" x-model="range.min" step="0.001" min="0" placeholder="De (kg)" class="col-span-2 {{ $input }}">
                <input type="number" :name="`ranges[${i}][max]`" x-model="range.max" step="0.001" min="0.001" placeholder="À (kg)" class="col-span-2 {{ $input }}">
                <input type="number" :name="`ranges[${i}][price]`" x-model="range.price" step="1" min="0" placeholder="Prix FCFA" class="col-span-3 {{ $input }}">
                <button type="button" @click="ranges.splice(i, 1)" class="col-span-1 text-red-400 hover:text-red-300"><i class="fas fa-trash"></i></button>
            </div>
        </template>
        <button type="button" @click="ranges.push({label: '', label_en: '', min: ranges.length ? ranges[ranges.length - 1].max : 0, max: '', price: ''})"
                class="text-sm text-primary-400 hover:text-primary-300"><i class="fas fa-plus mr-1"></i> Ajouter une tranche</button>
    </div>

    <div class="mt-3 grid grid-cols-2 md:grid-cols-4 gap-3 items-end">
        <div>
            <label class="block text-xs text-gray-400 mb-1">Prix par kg supplémentaire</label>
            <input type="number" name="extra_per_kg" min="0" step="1" value="{{ $route?->pricing_data['extra_per_kg'] ?? '' }}" placeholder="0 = refusé au-delà" class="{{ $input }}">
        </div>
        <label class="flex items-center gap-2 text-sm text-gray-300" x-show="!international">
            <input type="checkbox" name="bidirectional" value="1" @checked($route?->bidirectional ?? true) class="w-4 h-4 rounded bg-dark-50">
            Et vice versa
        </label>
        <p class="text-xs text-yellow-400" x-show="international" x-cloak>International : de l'étranger vers le Cameroun uniquement.</p>
        <label class="flex items-center gap-2 text-sm text-gray-300">
            <input type="checkbox" name="is_active" value="1" @checked($route?->is_active ?? true) class="w-4 h-4 rounded bg-dark-50">
            Trajet actif
        </label>
        <div class="text-right">
            <button type="submit" class="px-4 py-2 bg-primary-500 text-white rounded-lg hover:bg-primary-600 text-sm">
                <i class="fas fa-save mr-1"></i> {{ $route ? 'Enregistrer' : 'Ajouter le trajet' }}
            </button>
        </div>
    </div>
</form>
