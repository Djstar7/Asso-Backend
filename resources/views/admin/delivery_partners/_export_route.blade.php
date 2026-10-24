@php($export = $route?->export_config ?? [])
@include('admin.delivery_partners._leaflet')
<fieldset class="mt-4 p-4 border border-dark-300 rounded-lg" x-show="international && originCountry === 'CM'" x-cloak>
    <legend class="text-sm font-semibold text-white px-2">Exportation · collecte Solex puis {{ $partner->name }}</legend>
    <p class="text-xs text-gray-400 mb-3">Solex récupère le colis chez le vendeur et le remet au transporteur dans l’agence choisie. Le tarif défini dans SOLEX → Tarifs de collecte Solex est prioritaire. Les valeurs ci-dessous servent de repli si l’agence n’a pas encore de tarif. La TVA suit cette grille partenaire.</p>
    <label class="flex items-center gap-2 text-sm text-yellow-300 mb-3">
        <input type="checkbox" name="provisional_rates" value="1" @checked(old('provisional_rates', $export['provisional_rates'] ?? false))>
        Tarifs provisoires (indiqué à l’acheteur)
    </label>
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
        <label class="text-xs text-gray-400">Retour international vers le Cameroun · FCFA/kg
            <input name="return_price_per_kg" type="number" min="0.01" step="0.01" value="{{ old('return_price_per_kg', $export['return_pricing']['price_per_kg'] ?? '') }}" class="{{ $input }}">
            <span>Tarif retour distinct. En mode provisoire uniquement, le tarif aller sert de valeur d’essai si ce champ est vide.</span>
        </label>
        <label class="text-xs text-gray-400">Retour international · poids maximum kg
            <input name="return_max_weight_kg" type="number" min="0.001" step="0.001" value="{{ old('return_max_weight_kg', $export['return_pricing']['max_weight_kg'] ?? '') }}" class="{{ $input }}">
        </label>
        <label class="text-xs text-gray-400">Agence Solex de départ
            <select name="solex_agency_id" class="{{ $input }}">
                <option value="">Choisir une agence</option>
                @foreach($solexAgencies as $agency)
                    <option value="{{ $agency->id }}" @selected(old('solex_agency_id', $export['solex_agency_id'] ?? null) == $agency->id)>{{ $agency->city }} · {{ $agency->name }}</option>
                @endforeach
            </select>
        </label>
        <label class="text-xs text-gray-400">Repli collecte · prix FCFA
            <input name="collection_price" type="number" min="0" step="1" value="{{ old('collection_price', $export['collection_pricing']['ranges'][0]['price'] ?? '') }}" class="{{ $input }}">
        </label>
        <label class="text-xs text-gray-400">Repli collecte · jusqu’à (kg)
            <input name="collection_max_kg" type="number" min="0.001" step="0.001" value="{{ old('collection_max_kg', $export['collection_pricing']['ranges'][0]['max'] ?? '') }}" class="{{ $input }}">
        </label>
        <label class="text-xs text-gray-400">Repli collecte · FCFA par kg au-delà
            <input name="collection_extra_per_kg" type="number" min="0" step="1" value="{{ old('collection_extra_per_kg', $export['collection_pricing']['extra_per_kg'] ?? 0) }}" class="{{ $input }}">
        </label>
        <label class="text-xs text-gray-400">Repli collecte · supplément FCFA/km (facultatif)
            <input name="collection_price_per_km" type="number" min="0" step="0.01" value="{{ old('collection_price_per_km', $export['collection_pricing']['price_per_km'] ?? 0) }}" class="{{ $input }}">
            <span>Ajouté au tarif au poids. Distance à vol d’oiseau ; GPS vendeur et agence nécessaires.</span>
        </label>
        <label class="text-xs text-gray-400">Libellé de zone (Europe, Amérique…)
            <input name="zone_label" maxlength="120" value="{{ old('zone_label', $export['zone_label'] ?? '') }}" class="{{ $input }}">
        </label>
        <label class="text-xs text-gray-400">Couverture de destination
            <select name="coverage_mode" class="{{ $input }}">
                <option value="polygon" @selected(old('coverage_mode', $export['coverage_mode'] ?? 'country') === 'polygon')>Zone dessinée sur la carte</option>
                <option value="country" @selected(old('coverage_mode', $export['coverage_mode'] ?? 'country') === 'country')>Tout le pays d’arrivée configuré</option>
            </select>
        </label>
    </div>
    <p class="text-xs text-gray-400 my-3">Cliquez sur la carte pour tracer la zone desservie par le transporteur. Le pays et la ville du trajet restent vérifiés. Pour une couverture nationale, choisissez « Tout le pays ». La carte ne certifie pas la couverture réelle du transporteur.</p>
    <div id="export-coverage-map" class="h-72 rounded-lg" style="min-height:18rem; z-index:0"></div>
    <button id="export-clear-zone" type="button" class="text-sm text-primary-400 mt-2">Effacer la zone</button>
    <label class="block text-xs text-gray-400 mt-2">Points de la zone [latitude, longitude]
        <textarea id="export-polygon" name="coverage_polygon" rows="2" class="{{ $input }}">{{ old('coverage_polygon', json_encode($export['polygon'] ?? [])) }}</textarea>
    </label>
</fieldset>
@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
    const field = document.getElementById('export-polygon');
    const element = document.getElementById('export-coverage-map');
    if (!field || !element || !window.L) return;
    const map = L.map(element).setView([20, 0], 2);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; OpenStreetMap contributors', maxZoom: 19
    }).addTo(map);
    let points = [], shape;
    const redraw = () => {
        if (shape) map.removeLayer(shape);
        if (points.length) shape = L.polygon(points).addTo(map);
    };
    try { points = JSON.parse(field.value); redraw(); if (points.length > 2) map.fitBounds(shape.getBounds()); } catch (_) {}
    map.on('click', event => {
        points.push([Number(event.latlng.lat.toFixed(6)), Number(event.latlng.lng.toFixed(6))]);
        field.value = JSON.stringify(points); redraw();
    });
    field.addEventListener('change', () => {
        try { points = JSON.parse(field.value); redraw(); } catch (_) {}
    });
    document.getElementById('export-clear-zone').addEventListener('click', () => {
        points = []; field.value = '[]'; redraw();
    });
    // The editor can be revealed after changing the origin/destination countries.
    new ResizeObserver(() => map.invalidateSize()).observe(element);
}, {once: true});
</script>
@endpush
