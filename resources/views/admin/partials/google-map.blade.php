@php
    $fn = str_replace('-', '_', $id);
    $hasCoords = !empty($latitude) && !empty($longitude);
@endphp
<div class="{{ $wrapperClass ?? 'bg-dark-200 border border-dark-300 rounded-lg p-6 mb-6' }}" id="{{ $id }}">
    <h4 class="text-lg font-semibold text-white mb-3 flex items-center gap-2">
        <i class="fas fa-map-marker-alt text-orange-500"></i>
        {{ $label ?? 'Localisation GPS (Optionnel)' }}
    </h4>
    <p class="text-sm text-gray-400 mb-4">
        <i class="fas fa-info-circle text-blue-500 mr-1"></i>
        Utilisez « Ma position », saisissez les coordonnées, ou recherchez une adresse : les autres champs se remplissent automatiquement.
    </p>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
        <div>
            <label class="block text-sm font-medium text-gray-200 mb-2">
                <i class="fas fa-location-arrow text-orange-500 mr-1"></i>
                Latitude
            </label>
            <input type="number"
                   step="any"
                   name="latitude"
                   id="{{ $id }}_latitude"
                   class="w-full px-4 py-2 bg-dark-50 border border-dark-400 rounded-lg focus:ring-2 focus:ring-orange-500 focus:border-orange-500 text-white placeholder-gray-500"
                   value="{{ $latitude ?? '' }}"
                   placeholder="Ex: 6.3703"
                   onchange="updateMapPreview_{{ $fn }}(true)">
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-200 mb-2">
                <i class="fas fa-location-arrow text-orange-500 mr-1"></i>
                Longitude
            </label>
            <input type="number"
                   step="any"
                   name="longitude"
                   id="{{ $id }}_longitude"
                   class="w-full px-4 py-2 bg-dark-50 border border-dark-400 rounded-lg focus:ring-2 focus:ring-orange-500 focus:border-orange-500 text-white placeholder-gray-500"
                   value="{{ $longitude ?? '' }}"
                   placeholder="Ex: 2.3912"
                   onchange="updateMapPreview_{{ $fn }}(true)">
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-200 mb-2">
                <i class="fas fa-crosshairs text-orange-500 mr-1"></i>
                Action
            </label>
            <button type="button"
                    id="{{ $id }}_locate_btn"
                    onclick="getCurrentLocation_{{ $fn }}()"
                    class="w-full px-4 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600 transition-all disabled:opacity-60">
                <i class="fas fa-crosshairs mr-2"></i>
                Ma position
            </button>
        </div>
    </div>

    <div>
        <label class="block text-sm font-medium text-gray-200 mb-2">
            <i class="fas fa-map-pin text-orange-500 mr-1"></i>
            Adresse
        </label>
        <div class="flex gap-2">
            <input type="text"
                   name="address"
                   id="{{ $id }}_address"
                   class="flex-1 min-w-0 px-4 py-2 bg-dark-50 border border-dark-400 rounded-lg focus:ring-2 focus:ring-orange-500 focus:border-orange-500 text-white placeholder-gray-500"
                   value="{{ $address ?? '' }}"
                   placeholder="123 Rue Principale, Cotonou, Bénin"
                   onkeydown="if (event.key === 'Enter') { event.preventDefault(); searchAddress_{{ $fn }}(); }">
            <button type="button"
                    onclick="searchAddress_{{ $fn }}()"
                    title="Placer l'adresse sur la carte"
                    class="px-4 py-2 bg-dark-300 text-white rounded-lg hover:bg-dark-400 transition-all">
                <i class="fas fa-search-location"></i>
            </button>
        </div>
        <p id="{{ $id }}_status" class="mt-1 text-xs text-gray-400">
            <i class="fas fa-lightbulb text-yellow-500 mr-1"></i>
            Saisissez une adresse puis la loupe pour la placer sur la carte, ou laissez-la se remplir depuis la position.
        </p>
    </div>

    <!-- Map Preview -->
    <div id="{{ $id }}_map_preview" style="display: {{ $hasCoords ? 'block' : 'none' }}; margin-top: 1.5rem;">
        <h5 class="text-md font-semibold text-white mb-3">
            <i class="fas fa-map text-orange-500 mr-2"></i>
            Aperçu de la localisation
        </h5>
        <div class="border-2 border-dark-400 rounded-lg overflow-hidden shadow-md">
            <iframe
                id="{{ $id }}_map_iframe"
                width="100%"
                height="350"
                frameborder="0"
                style="border:0"
                referrerpolicy="no-referrer-when-downgrade"
                allowfullscreen
                @if($hasCoords)
                src="https://maps.google.com/maps?q={{ $latitude }},{{ $longitude }}&z={{ $zoom ?? '15' }}&hl=fr&output=embed"
                @endif
            ></iframe>
        </div>
        <p class="mt-2 text-xs text-gray-400">
            <i class="fas fa-map-marker-alt text-orange-500 mr-1"></i>
            <span id="{{ $id }}_coordinates_display">
                @if($hasCoords)
                    Coordonnées: {{ number_format($latitude, 6) }}, {{ number_format($longitude, 6) }}
                @endif
            </span>
        </p>
    </div>
</div>

@push('scripts')
<script>
(function () {
    const prefix = '{{ $id }}';
    const zoom = '{{ $zoom ?? '15' }}';
    const el = suffix => document.getElementById(`${prefix}_${suffix}`);

    function setStatus(message, tone) {
        const colors = { info: 'text-gray-400', ok: 'text-green-400', error: 'text-red-400' };
        const status = el('status');
        status.className = `mt-1 text-xs ${colors[tone] || colors.info}`;
        status.textContent = message;
    }

    // Compare sans accents ni casse ("Bénin" == "benin").
    const normalize = value => (value || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim();

    // Champs Ville / Pays du même formulaire (input ou select), s'ils existent.
    function fillField(name, value) {
        const form = el('address').form;
        const field = form ? form.querySelector(`[name="${name}"]`) : null;
        if (!field || !value) return;
        if (field.tagName === 'SELECT') {
            const option = Array.from(field.options).find(o => o.value && normalize(o.value) === normalize(value));
            if (option) field.value = option.value;
        } else {
            field.value = value;
        }
    }

    function applyPlace(data, withAddress) {
        const a = data.address || {};
        if (withAddress && data.display_name) el('address').value = data.display_name;
        fillField('city', a.city || a.town || a.village || a.municipality || a.county || a.state);
        fillField('country', a.country);
    }

    function renderMap(latitude, longitude) {
        el('map_iframe').src = `https://maps.google.com/maps?q=${latitude},${longitude}&z=${zoom}&hl=fr&output=embed`;
        el('coordinates_display').textContent = `Coordonnées: ${parseFloat(latitude).toFixed(6)}, ${parseFloat(longitude).toFixed(6)}`;
        el('map_preview').style.display = 'block';
    }

    // refreshPlace = true : la position vient d'être choisie, on réécrit adresse / ville / pays.
    window['updateMapPreview_{{ $fn }}'] = function (refreshPlace) {
        const latitude = el('latitude').value;
        const longitude = el('longitude').value;

        if (!latitude || !longitude) {
            el('map_preview').style.display = 'none';
            return;
        }
        renderMap(latitude, longitude);
        if (!refreshPlace) return;

        setStatus('Recherche de l\'adresse…', 'info');
        fetch(`https://nominatim.openstreetmap.org/reverse?format=json&lat=${latitude}&lon=${longitude}&zoom=18&addressdetails=1&accept-language=fr`)
            .then(response => response.ok ? response.json() : Promise.reject(response.status))
            .then(data => {
                if (!data || data.error) throw new Error(data && data.error);
                applyPlace(data, true);
                setStatus('Adresse, ville et pays mis à jour depuis la position.', 'ok');
            })
            .catch(error => {
                console.log('Geocoding error:', error);
                setStatus('Position enregistrée, mais l\'adresse n\'a pas pu être trouvée : saisissez-la manuellement.', 'error');
            });
    };

    window['searchAddress_{{ $fn }}'] = function () {
        const query = el('address').value.trim();
        if (!query) {
            setStatus('Saisissez une adresse à rechercher.', 'error');
            return;
        }
        setStatus('Recherche de l\'adresse sur la carte…', 'info');
        fetch(`https://nominatim.openstreetmap.org/search?format=json&limit=1&addressdetails=1&accept-language=fr&q=${encodeURIComponent(query)}`)
            .then(response => response.ok ? response.json() : Promise.reject(response.status))
            .then(results => {
                if (!results || !results.length) {
                    setStatus('Adresse introuvable : précisez-la (quartier, ville, pays).', 'error');
                    return;
                }
                const place = results[0];
                el('latitude').value = parseFloat(place.lat).toFixed(6);
                el('longitude').value = parseFloat(place.lon).toFixed(6);
                renderMap(el('latitude').value, el('longitude').value);
                applyPlace(place, false);
                setStatus('Adresse placée sur la carte, ville et pays mis à jour.', 'ok');
            })
            .catch(error => {
                console.log('Geocoding error:', error);
                setStatus('La recherche d\'adresse a échoué, réessayez.', 'error');
            });
    };

    window['getCurrentLocation_{{ $fn }}'] = function () {
        if (!window.isSecureContext) {
            setStatus('La géolocalisation exige une connexion HTTPS (ou localhost). Saisissez les coordonnées ou recherchez l\'adresse.', 'error');
            return;
        }
        if (!navigator.geolocation) {
            setStatus('La géolocalisation n\'est pas supportée par ce navigateur.', 'error');
            return;
        }

        const button = el('locate_btn');
        button.disabled = true;
        setStatus('Détection de la position…', 'info');

        const onSuccess = position => {
            button.disabled = false;
            el('latitude').value = position.coords.latitude.toFixed(6);
            el('longitude').value = position.coords.longitude.toFixed(6);
            window['updateMapPreview_{{ $fn }}'](true);
        };
        const onFinalError = error => {
            button.disabled = false;
            console.error('Geolocation error:', error);
            const reasons = {
                1: 'Accès à la position refusé : autorisez la localisation pour ce site dans le navigateur.',
                2: 'Position indisponible (service de localisation de l\'appareil désactivé ?).',
                3: 'La détection de la position a pris trop de temps.',
            };
            setStatus(`${reasons[error.code] || 'Impossible de détecter la position.'} Vous pouvez saisir les coordonnées ou rechercher l'adresse.`, 'error');
        };

        // Haute précision d'abord, puis repli en précision réseau si le GPS échoue.
        navigator.geolocation.getCurrentPosition(onSuccess, error => {
            if (error.code === 1) return onFinalError(error);
            navigator.geolocation.getCurrentPosition(onSuccess, onFinalError, { enableHighAccuracy: false, timeout: 15000, maximumAge: 300000 });
        }, { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 });
    };

    document.addEventListener('DOMContentLoaded', () => window['updateMapPreview_{{ $fn }}'](false));
})();
</script>
@endpush
