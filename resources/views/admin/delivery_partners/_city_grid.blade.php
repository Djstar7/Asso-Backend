@php
    $input = 'w-full px-2 py-1.5 bg-dark-50 border border-dark-200 rounded text-white text-sm focus:border-primary-500 focus:outline-none';
    $codes = collect($grid->zones)->pluck('code')->all();
@endphp
<form action="{{ route('admin.delivery-partners.city-grids.update', [$partner, $grid]) }}" method="POST" x-data="{ tab: '{{ $grid->vehicles[0]['code'] ?? '' }}' }">
    @csrf
    @method('PUT')

    <!-- Zones et quartiers géolocalisés -->
    <h3 class="text-white font-medium mb-1">Zones de couverture et quartiers</h3>
    <p class="text-xs text-gray-500 mb-3">
        Tapez le nom d'un quartier : il est recherché et placé automatiquement sur la carte de {{ $grid->city }}. Pour corriger un point, cliquez sur <i class="fas fa-crosshairs"></i> puis sur la carte.
        La boutique est rattachée au quartier le plus proche de sa position (dans un rayon de {{ rtrim(rtrim(number_format(\App\Services\DeliveryQuoteService::radiusKm(), 1, ',', ''), '0'), ',') }} km) ; l'acheteur choisit son quartier dans l'app.
    </p>
    <div class="grid grid-cols-1 xl:grid-cols-2 gap-4 mb-6">
        <div class="space-y-3 max-h-[560px] overflow-y-auto pr-1" id="grid_quarters_{{ $grid->id }}">
            @foreach($grid->zones as $zone)
                @php $quarters = \App\Models\DeliveryCityGrid::quartersOf($zone); @endphp
                <div class="p-3 bg-dark-50 rounded-lg border border-dark-300" data-zone="{{ $zone['code'] }}">
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-white text-sm font-medium">
                            <span class="inline-block w-3 h-3 rounded-full mr-1 align-middle zone-color" data-zone-color="{{ $zone['code'] }}"></span>
                            {{ $zone['label'] }}
                        </span>
                        <button type="button" class="text-primary-400 text-xs hover:text-primary-300 add-quarter" data-zone="{{ $zone['code'] }}">
                            <i class="fas fa-plus mr-1"></i> Ajouter un quartier
                        </button>
                    </div>
                    <div class="space-y-2 quarter-list">
                        @foreach($quarters as $i => $quarter)
                            <div class="grid grid-cols-12 gap-1 items-center quarter-row">
                                <input type="text" name="zones[{{ $zone['code'] }}][quarters][{{ $i }}][name]" value="{{ $quarter['name'] }}" class="col-span-5 {{ $input }} q-name">
                                <input type="text" name="zones[{{ $zone['code'] }}][quarters][{{ $i }}][lat]" value="{{ $quarter['lat'] }}" placeholder="lat" class="col-span-2 {{ $input }} q-lat">
                                <input type="text" name="zones[{{ $zone['code'] }}][quarters][{{ $i }}][lng]" value="{{ $quarter['lng'] }}" placeholder="lng" class="col-span-2 {{ $input }} q-lng">
                                <button type="button" title="Placer sur la carte" class="col-span-1 text-gray-300 hover:text-primary-400 q-pick"><i class="fas fa-crosshairs"></i></button>
                                <button type="button" title="Localiser par le nom" class="col-span-1 text-gray-300 hover:text-primary-400 q-geo"><i class="fas fa-search-location"></i></button>
                                <button type="button" title="Retirer" class="col-span-1 text-red-400 hover:text-red-300 q-del"><i class="fas fa-trash"></i></button>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach
            <button type="submit" name="add_zone" value="1" class="w-full px-3 py-2 border border-dashed border-dark-300 rounded-lg text-sm text-primary-400 hover:bg-dark-50">
                <i class="fas fa-plus mr-1"></i> Ajouter une zone à {{ $grid->city }} (enregistre la grille)
            </button>
        </div>
        <div>
            <div id="grid_map_{{ $grid->id }}" class="w-full h-[560px] rounded-lg border border-dark-300"></div>
            <div class="mt-2 flex flex-wrap items-center justify-between gap-2">
                <p class="text-xs text-gray-400" id="grid_map_hint_{{ $grid->id }}">Sélectionnez un quartier (<i class="fas fa-crosshairs"></i>) puis cliquez sur la carte.</p>
                <button type="button" id="grid_geo_all_{{ $grid->id }}" class="px-3 py-1.5 bg-dark-50 border border-primary-500/50 text-primary-400 rounded-lg text-xs hover:bg-dark-200">
                    <i class="fas fa-map-marker-alt mr-1"></i> Localiser tous les quartiers non placés
                </button>
            </div>
        </div>
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
                    <option value="{{ $code }}" @selected($grid->agency_zone === $code)>Zone {{ $code }}</option>
                @endforeach
            </select>
            <p class="mt-1 text-xs text-gray-500">Départ de la livraison à domicile après un trajet interurbain arrivant à {{ $grid->city }}.</p>
        </div>
        <div class="flex items-center justify-between gap-3">
            <label class="flex items-center gap-2 text-sm text-gray-300">
                <input type="checkbox" name="is_active" value="1" @checked($grid->is_active) class="w-4 h-4 rounded bg-dark-50">
                Grille active
            </label>
            <button type="submit" class="px-4 py-2 bg-primary-500 text-white rounded-lg hover:bg-primary-600 text-sm">
                <i class="fas fa-save mr-1"></i> Enregistrer
            </button>
        </div>
    </div>
</form>

@once
    @push('styles')
        <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    @endpush
    @push('scripts')
        <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    @endpush
@endonce

@push('scripts')
<script>
(function () {
    const gridId = {{ $grid->id }};
    const city = @json($grid->city);
    const list = document.getElementById('grid_quarters_' + gridId);
    const hint = document.getElementById('grid_map_hint_' + gridId);
    const colors = ['#ef4444', '#f59e0b', '#10b981', '#3b82f6', '#8b5cf6', '#ec4899', '#14b8a6', '#eab308', '#6366f1', '#84cc16'];
    const colorOf = (zone) => colors[(parseInt(zone, 10) - 1) % colors.length];
    list.querySelectorAll('[data-zone-color]').forEach(el => el.style.background = colorOf(el.dataset.zoneColor));

    function init() {
        const map = L.map('grid_map_' + gridId).setView([4.05, 9.72], 12);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '© OpenStreetMap' }).addTo(map);
        let active = null;
        const markers = new Map();

        function draw(row) {
            const zone = row.closest('[data-zone]').dataset.zone;
            const lat = parseFloat(row.querySelector('.q-lat').value.replace(',', '.'));
            const lng = parseFloat(row.querySelector('.q-lng').value.replace(',', '.'));
            const name = row.querySelector('.q-name').value || 'Quartier';
            if (markers.has(row)) { map.removeLayer(markers.get(row)); markers.delete(row); }
            if (isNaN(lat) || isNaN(lng)) return;
            const m = L.circleMarker([lat, lng], { radius: 8, color: '#111', weight: 1, fillColor: colorOf(zone), fillOpacity: .9 })
                .bindTooltip(name + ' — Zone ' + zone).addTo(map);
            markers.set(row, m);
        }

        function wire(row) {
            row.querySelector('.q-pick').addEventListener('click', () => {
                list.querySelectorAll('.quarter-row').forEach(r => r.classList.remove('ring-2', 'ring-primary-500'));
                active = row;
                row.classList.add('ring-2', 'ring-primary-500');
                hint.textContent = 'Cliquez sur la carte pour placer « ' + (row.querySelector('.q-name').value || 'ce quartier') + ' ».';
            });
            row.querySelector('.q-geo').addEventListener('click', () => geocode(row, true));
            row.querySelector('.q-del').addEventListener('click', () => {
                if (markers.has(row)) map.removeLayer(markers.get(row));
                markers.delete(row);
                row.remove();
            });
            ['.q-lat', '.q-lng'].forEach(sel => row.querySelector(sel).addEventListener('change', () => draw(row)));
            // Nom saisi : recherche automatique du quartier sur la carte de la ville.
            let lastName = row.querySelector('.q-name').value;
            row.querySelector('.q-name').addEventListener('change', () => {
                const name = row.querySelector('.q-name').value.trim();
                if (!name || name === lastName) return draw(row);
                lastName = name;
                geocode(row, true);
            });
            draw(row);
        }

        function geocode(row, focus) {
            const name = row.querySelector('.q-name').value;
            if (!name) return Promise.resolve();
            hint.textContent = 'Recherche de « ' + name + ' »…';
            return fetch('https://nominatim.openstreetmap.org/search?format=json&limit=1&countrycodes=cm&q=' + encodeURIComponent(name + ', ' + city))
                    .then(r => r.json())
                    .then(res => {
                        if (!res.length) { hint.textContent = '« ' + name + ' » introuvable : placez-le à la main.'; return false; }
                        row.querySelector('.q-lat').value = parseFloat(res[0].lat).toFixed(6);
                        row.querySelector('.q-lng').value = parseFloat(res[0].lon).toFixed(6);
                        draw(row);
                        if (focus) map.setView([res[0].lat, res[0].lon], 14);
                        hint.textContent = '« ' + name + ' » localisé. Vérifiez le point sur la carte.';
                        return true;
                    })
                    .catch(() => { hint.textContent = 'Recherche indisponible : placez le quartier à la main.'; return false; });
        }

        // Tous les quartiers sans position, un par seconde (règle d'usage d'OpenStreetMap).
        document.getElementById('grid_geo_all_' + gridId).addEventListener('click', async (e) => {
            const rows = [...list.querySelectorAll('.quarter-row')].filter(r => !r.querySelector('.q-lat').value);
            let found = 0, missing = [];
            e.target.disabled = true;
            for (const row of rows) {
                if (await geocode(row, false)) found++; else missing.push(row.querySelector('.q-name').value);
                await new Promise(r => setTimeout(r, 1100));
            }
            e.target.disabled = false;
            const placed = [...markers.values()];
            if (placed.length) map.fitBounds(L.featureGroup(placed).getBounds().pad(0.2));
            hint.textContent = found + ' quartier(s) localisé(s).' + (missing.length ? ' À placer à la main : ' + missing.join(', ') + '.' : '') + ' Vérifiez puis enregistrez.';
        });

        map.on('click', (e) => {
            if (!active) return;
            active.querySelector('.q-lat').value = e.latlng.lat.toFixed(6);
            active.querySelector('.q-lng').value = e.latlng.lng.toFixed(6);
            draw(active);
            hint.textContent = '« ' + (active.querySelector('.q-name').value || 'Quartier') + ' » placé.';
        });

        list.querySelectorAll('.quarter-row').forEach(wire);
        list.querySelectorAll('.add-quarter').forEach(btn => btn.addEventListener('click', () => {
            const zone = btn.dataset.zone;
            const box = btn.closest('[data-zone]').querySelector('.quarter-list');
            const i = Date.now();
            const cls = @json($input);
            box.insertAdjacentHTML('beforeend', `
                <div class="grid grid-cols-12 gap-1 items-center quarter-row">
                    <input type="text" name="zones[${zone}][quarters][${i}][name]" placeholder="Nom du quartier" class="col-span-5 ${cls} q-name">
                    <input type="text" name="zones[${zone}][quarters][${i}][lat]" placeholder="lat" class="col-span-2 ${cls} q-lat">
                    <input type="text" name="zones[${zone}][quarters][${i}][lng]" placeholder="lng" class="col-span-2 ${cls} q-lng">
                    <button type="button" title="Placer sur la carte" class="col-span-1 text-gray-300 hover:text-primary-400 q-pick"><i class="fas fa-crosshairs"></i></button>
                    <button type="button" title="Localiser par le nom" class="col-span-1 text-gray-300 hover:text-primary-400 q-geo"><i class="fas fa-search-location"></i></button>
                    <button type="button" title="Retirer" class="col-span-1 text-red-400 hover:text-red-300 q-del"><i class="fas fa-trash"></i></button>
                </div>`);
            wire(box.lastElementChild);
            box.lastElementChild.querySelector('.q-name').focus();
        }));

        const placed = [...markers.values()];
        if (placed.length) {
            map.fitBounds(L.featureGroup(placed).getBounds().pad(0.2));
        } else {
            // Nouvelle ville : carte centrée sur la ville saisie.
            fetch('https://nominatim.openstreetmap.org/search?format=json&limit=1&countrycodes=cm&q=' + encodeURIComponent(city))
                .then(r => r.json())
                .then(res => { if (res.length) map.setView([res[0].lat, res[0].lon], 12); })
                .catch(() => {});
        }
    }

    if (window.L) init(); else window.addEventListener('load', init);
})();
</script>
@endpush
