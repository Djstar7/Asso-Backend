@php
    $input = 'w-full px-2 py-1.5 bg-dark-50 border border-dark-200 rounded text-white text-sm';
    $codes = collect($grid->zones)->pluck('code')->all();
@endphp
<form method="POST" action="{{ route('admin.delivery-partners.network.grids.update', [$partner, $grid]) }}" id="territorial_form_{{ $grid->id }}" x-data="{ tab: '{{ $grid->vehicles[0]['code'] ?? '' }}' }">
    @csrf @method('PUT')
    <h3 class="text-white font-semibold mb-2">{{ $territories->get($grid->department_code)?->name }} — centres et rayons</h3>
    <p class="text-gray-400 text-sm mb-4">Sélectionnez « Placer » puis cliquez sur la carte. Rayon en kilomètres à vol d’oiseau. Cocher une zone confirme son centre et son périmètre. Les adresses hors cercle ne sont pas admises à domicile.</p>
    <div class="grid grid-cols-1 xl:grid-cols-2 gap-4 mb-6">
        <div class="space-y-3 max-h-[520px] overflow-y-auto">
        @foreach($grid->zones as $zone)
            <div data-circle="{{ $zone['code'] }}" class="circle-row border border-dark-200 rounded p-3">
                <div class="flex justify-between text-white mb-2">
                    <label><input name="zones[{{ $zone['code'] }}][is_active]" type="checkbox" value="1" @checked($zone['is_active'])> {{ $zone['label'] }}</label>
                    <button type="button" class="circle-pick text-primary-400">Placer</button>
                </div>
                <div class="grid grid-cols-2 gap-2">
                    @foreach(['center_latitude' => 'Latitude', 'center_longitude' => 'Longitude', 'radius_km' => 'Rayon (km)', 'priority' => 'Priorité (égalité de prix)'] as $key => $label)
                        <label class="text-xs text-gray-400">{{ $label }}<input required type="number" step="{{ $key === 'priority' ? '1' : 'any' }}" name="zones[{{ $zone['code'] }}][{{ $key }}]" data-field="{{ $key }}" value="{{ $zone[$key] ?? 0 }}" class="{{ $input }}"></label>
                    @endforeach
                </div>
            </div>
        @endforeach
        </div>
        <div id="territorial_map_{{ $grid->id }}" class="h-[520px] rounded border border-dark-200"></div>
    </div>
    <!-- Véhicules -->
    <h3 class="text-white font-medium mb-2">Véhicules et prix zone à zone</h3>
    <p class="text-xs text-gray-500 mb-3">
        Prix {{ $partner->prices_exclude_vat ? 'hors taxe (la TVA est ajoutée)' : 'TTC' }}. Ligne = zone de départ, colonne = zone d'arrivée ;
        le prix vaut dans les deux sens. Le poids max. décide des véhicules proposés à l'acheteur.
    </p>
    <div class="flex flex-wrap gap-2 mb-3">
        @foreach($grid->vehicles as $vehicle)
            <button type="button" @click="tab = '{{ $vehicle['code'] }}'"
                    :class="tab === '{{ $vehicle['code'] }}' ? 'bg-primary-500 text-white' : 'bg-dark-50 text-gray-300'"
                    class="px-3 py-1.5 rounded-lg text-sm">{{ $vehicle['label'] }}</button>
        @endforeach
    </div>

    @foreach($grid->vehicles as $vehicle)
        @php
            $code = $vehicle['code'];
            $vehicleEn = $grid->getTranslation('vehicles', 'en')[$code] ?? [];
        @endphp
        <div x-show="tab === '{{ $code }}'" x-cloak class="mb-6">
            @if(($vehicle['billing'] ?? null) === 'excess_per_kg')
                <p class="text-sm text-amber-300 mb-3">Au-delà de 1 000 kg : tarif de base du camion 1 tonne + kilos supplémentaires. Par défaut, prix au kilo = prix de base ÷ 1 000. Le trajet interurbain conserve sa propre grille.</p>
                <label class="block text-xs text-gray-400 mb-3">Prix HT par kilo supplémentaire (facultatif, sinon prorata du tarif 1 tonne)
                    <input type="number" step="0.001" min="0" name="vehicles[{{ $code }}][extra_per_kg]" value="{{ $vehicle['extra_per_kg'] ?? '' }}" class="{{ $input }}">
                </label>
            @endif
            <div class="grid grid-cols-1 md:grid-cols-5 gap-3 mb-3">
                <div>
                    <label class="block text-xs text-gray-400 mb-1">Nom affiché</label>
                    <input type="text" name="vehicles[{{ $code }}][label]" value="{{ $vehicle['label'] }}" required class="{{ $input }}">
                </div>
                <div>
                    <label class="block text-xs text-gray-400 mb-1">Nom affiché (EN)</label>
                    <input type="text" name="translations[en][vehicles][{{ $code }}][label]" value="{{ $vehicleEn['label'] ?? '' }}" placeholder="Facultatif" class="{{ $input }}">
                </div>
                <div>
                    <label class="block text-xs text-gray-400 mb-1">Poids max. (kg)</label>
                    <input type="number" step="0.1" min="0.1" name="vehicles[{{ $code }}][max_weight_kg]" value="{{ $vehicle['max_weight_kg'] ?? '' }}" class="{{ $input }}">
                </div>
                <div>
                    <label class="block text-xs text-gray-400 mb-1">Délai estimé affiché</label>
                    <input type="text" name="vehicles[{{ $code }}][lead_time]" value="{{ $vehicle['lead_time'] ?? '' }}" placeholder="1 h à 3 h" class="{{ $input }}">
                </div>
                <div>
                    <label class="block text-xs text-gray-400 mb-1">Délai affiché (EN)</label>
                    <input type="text" name="translations[en][vehicles][{{ $code }}][lead_time]" value="{{ $vehicleEn['lead_time'] ?? '' }}" placeholder="1 to 3 h" class="{{ $input }}">
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="text-sm">
                    <thead>
                        <tr class="text-gray-400">
                            <th class="px-2 py-1 text-left">Départ \ Arrivée</th>
                            @foreach($codes as $to)
                                <th class="px-2 py-1">Zone {{ $to }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($codes as $from)
                            <tr>
                                <td class="px-2 py-1 text-gray-300 whitespace-nowrap">Zone {{ $from }}</td>
                                @foreach($codes as $to)
                                    <td class="px-1 py-1 min-w-[90px]">
                                        @if($to >= $from)
                                            <input type="number" step="1" min="0" name="vehicles[{{ $code }}][prices][{{ $from }}-{{ $to }}]"
                                                   value="{{ $vehicle['prices'][$from . '-' . $to] ?? '' }}" class="{{ $input }}">
                                        @else
                                            <span class="block text-center text-gray-600">=</span>
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endforeach

    <div class="grid grid-cols-1 md:grid-cols-3 gap-3 items-end">
        <div>
            <label class="block text-xs text-gray-400 mb-1">Zone de l'agence {{ $partner->name }} à {{ $grid->city }}</label>
            <select name="agency_zone" class="{{ $input }}">
                <option value="">Non renseignée</option>
                @foreach($codes as $code)
                    <option value="{{ $code }}" @selected($grid->agency_zone === $code)>{{ $grid->zone($code)['label'] }}</option>
                @endforeach
            </select>
            <p class="mt-1 text-xs text-gray-500">Zone du relais départemental : arrivée de la collecte et départ de la distribution.</p>
        </div>
        <div class="flex items-center justify-between gap-3">
            <label class="flex items-center gap-2 text-sm text-gray-300">
                <input type="checkbox" name="is_active" value="1" @checked($grid->is_active) class="w-4 h-4 rounded bg-dark-50">
                Desserte départementale active — tarifs validés
            </label>
            <button type="submit" class="px-4 py-2 bg-primary-500 text-white rounded-lg hover:bg-primary-600 text-sm">
                <i class="fas fa-save mr-1"></i> Enregistrer
            </button>
        </div>
    </div>
</form>
@include('admin.delivery_partners._leaflet')
@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('territorial_form_{{ $grid->id }}');
    const map = L.map('territorial_map_{{ $grid->id }}').setView([4, 12], 7);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {attribution: '&copy; OpenStreetMap'}).addTo(map);
    const layers = L.layerGroup().addTo(map);
    let selected = null;
    function draw() {
        layers.clearLayers();
        const bounds = L.latLngBounds([]);
        form.querySelectorAll('.circle-row').forEach(row => {
            const get = key => Number(row.querySelector('[data-field="'+key+'"]').value);
            const lat = get('center_latitude'), lng = get('center_longitude'), radius = get('radius_km');
            if (!Number.isFinite(lat) || !Number.isFinite(lng) || radius <= 0) return;
            const active = row.querySelector('input[type="checkbox"]').checked;
            const circle = L.circle([lat, lng], {radius: radius*1000, color: active ? '#16a34a' : '#f97316', fillOpacity: .12});
            const label = row.querySelector('label').textContent.trim();
            const tooltip = document.createElement('span'); tooltip.textContent = label+' · '+radius+' km';
            circle.bindTooltip(tooltip).addTo(layers); bounds.extend(circle.getBounds());
        });
        return bounds;
    }
    form.querySelectorAll('.circle-row').forEach(row => {
        row.querySelector('.circle-pick').addEventListener('click', () => {
            selected = row;
            form.querySelectorAll('.circle-row').forEach(el => el.classList.toggle('ring-2', el === row));
        });
        row.querySelectorAll('input').forEach(el => el.addEventListener('input', draw));
    });
    map.on('click', e => {
        if (!selected) return;
        selected.querySelector('[data-field="center_latitude"]').value = e.latlng.lat.toFixed(6);
        selected.querySelector('[data-field="center_longitude"]').value = e.latlng.lng.toFixed(6);
        draw();
    });
    const bounds = draw(); if (bounds.isValid()) map.fitBounds(bounds, {padding: [15,15], maxZoom: 12});
});
</script>
@endpush
