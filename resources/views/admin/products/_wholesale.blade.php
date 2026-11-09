{{--
    Vente en gros (Import), partagée par la création et l'édition d'un produit.
    On recopie une ligne du devis fournisseur : pièces par carton, poids et CBM du
    carton, puis les prix par pièce selon la quantité. Le poids d'une pièce et le prix
    affiché se déduisent côté serveur (ProductController::prepareWholesaleInput).
--}}
@php
    $wholesaleProduct = isset($product) ? $product : null;
    $savedTiers = $wholesaleProduct?->priceTiers ?? collect();
    $tiersForForm = array_values(old('tiers', $savedTiers->map->only([
        'label', 'unit_price', 'min_quantity', 'pack_size', 'weight_kg', 'length_cm', 'width_cm', 'height_cm', 'cbm',
    ])->all()));
    $cartonPieces = old('pieces_per_carton', $wholesaleProduct?->pieces_per_carton ?? $savedTiers->first()?->pack_size);
    $cartonWeight = old('carton_weight_kg', $wholesaleProduct?->carton_weight_kg !== null
        ? (float) $wholesaleProduct->carton_weight_kg
        : (($wholesaleProduct?->weightKg() && $cartonPieces) ? round($wholesaleProduct->weightKg() * $cartonPieces, 3) : null));
    $freightPreview = \App\Models\ImportCountry::get()->mapWithKeys(fn ($country) => [$country->code => [
        'air_max' => \App\Services\ImportFreightService::airMaxWeightKg(),
        'options' => \App\Models\ImportShippingOption::activeForCountry($country->code)->get()
            ->map(fn ($o) => ['mode' => $o->mode, 'type' => $o->rate_type, 'rate' => (float) $o->rate_amount, 'days' => $o->lead_time_days])->values(),
    ]]);
    $isWholesale = (bool) old('is_wholesale', $wholesaleProduct?->is_wholesale ?? false);
@endphp

<!-- Vente en gros (Import) -->
<div class="bg-dark-100 rounded-xl shadow-lg p-6" id="wholesale_section" style="display:none;">
    <div class="flex items-center justify-between mb-4">
        <h2 class="text-lg font-semibold text-white flex items-center">
            <i class="fas fa-boxes text-primary-500 mr-2"></i>
            Vente en gros (Import)
        </h2>
        <span id="wholesale_country_badge" class="text-xs px-2 py-1 bg-primary-600/20 text-primary-400 rounded-full"></span>
    </div>

    <label class="mb-1 flex items-center cursor-pointer">
        <input type="checkbox" name="is_wholesale" id="is_wholesale" value="1" {{ $isWholesale ? 'checked' : '' }}
               class="mr-2 w-4 h-4 text-primary-500 focus:ring-primary-500 rounded">
        <span class="text-white text-sm font-medium">Vendre en gros (prix selon la quantité)</span>
    </label>
    <p class="mb-4 text-xs text-gray-400">
        <i class="fas fa-warehouse mr-1"></i>Rattaché à « {{ \App\Support\ImportHub::NAME }} » : arrive à Douala, puis SOLEX livre le client.
    </p>

    <div id="tiers_container" class="{{ $isWholesale ? '' : 'hidden' }} space-y-5">
        {{-- 1. Le carton, comme sur le devis --}}
        <div class="rounded-lg border border-primary-500/50 bg-primary-500/5 p-4" id="carton_block">
            <h3 class="text-sm font-semibold text-white mb-1">1. Le carton <span class="font-normal text-gray-400">— recopiez la ligne du devis</span></h3>
            <p class="text-xs text-gray-400">Exemple, devis Smile Bella, MX-6030 : <strong class="text-gray-300">8</strong> pièces · <strong class="text-gray-300">18,5</strong> kg · <strong class="text-gray-300">0,139</strong> CBM.</p>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mt-3">
                <div>
                    <label for="pieces_per_carton" class="block text-xs text-gray-300 mb-1">Pièces par carton <span class="text-gray-500">(PCS/CTN)</span></label>
                    <input type="number" min="1" step="1" name="pieces_per_carton" id="pieces_per_carton" value="{{ $cartonPieces }}"
                           class="w-full px-3 py-2 bg-dark-50 border border-dark-300 rounded-lg text-white @error('pieces_per_carton') border-red-500 @enderror">
                    @error('pieces_per_carton')<p class="mt-1 text-xs text-red-400">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="carton_weight_kg" class="block text-xs text-gray-300 mb-1">Poids du carton (kg) <span class="text-gray-500">(G.W)</span></label>
                    <input type="number" min="0.001" step="0.001" name="carton_weight_kg" id="carton_weight_kg" value="{{ $cartonWeight }}"
                           class="w-full px-3 py-2 bg-dark-50 border border-dark-300 rounded-lg text-white @error('carton_weight_kg') border-red-500 @enderror">
                    @error('carton_weight_kg')<p class="mt-1 text-xs text-red-400">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="cbm" class="block text-xs text-gray-300 mb-1">Volume du carton (CBM)</label>
                    <input type="number" min="0.0001" max="100" step="0.0001" name="cbm" id="cbm" value="{{ old('cbm', $wholesaleProduct?->cbm !== null ? (float) $wholesaleProduct->cbm : null) }}"
                           class="w-full px-3 py-2 bg-dark-50 border border-dark-300 rounded-lg text-white @error('cbm') border-red-500 @enderror">
                    @error('cbm')<p class="mt-1 text-xs text-red-400">{{ $message }}</p>@enderror
                </div>
            </div>

            <details class="mt-2 text-xs text-gray-400">
                <summary class="cursor-pointer hover:text-gray-200">Pas de CBM sur le devis ? Le calculer avec la taille du carton</summary>
                <div class="mt-2 flex flex-wrap items-center gap-2">
                    @foreach(['L' => 'Longueur', 'l' => 'Largeur', 'H' => 'Hauteur'] as $key => $title)
                        <input type="number" min="0" step="0.1" data-carton-size placeholder="{{ $key }}" title="{{ $title }} (cm)" aria-label="{{ $title }} du carton (cm)"
                               class="w-20 px-2 py-1 bg-dark-50 border border-dark-300 rounded text-white">
                        @if(! $loop->last)<span>×</span>@endif
                    @endforeach
                    <span>cm</span>
                    <button type="button" id="carton_size_apply" class="px-2 py-1 rounded bg-dark-300 text-white hover:bg-dark-200">Calculer le CBM</button>
                </div>
                <p class="mt-1">En millimètres sur le devis (ex. 560*510*340MM) : divisez par 10.</p>
            </details>

            <p id="carton_summary" class="mt-3 text-sm text-primary-200"></p>
        </div>

        {{-- 2. Les prix --}}
        <div>
            <div class="flex items-center justify-between mb-1">
                <h3 class="text-sm font-semibold text-white">2. Prix par pièce selon la quantité</h3>
                <button type="button" onclick="addTierRow()" class="px-3 py-1.5 bg-primary-600 text-white text-xs rounded-lg hover:bg-primary-700">
                    <i class="fas fa-plus mr-1"></i> Ajouter un prix
                </button>
            </div>
            <p class="mb-2 text-xs text-gray-400">La 1<sup>re</sup> ligne est le minimum de commande. Chaque ligne suivante donne un meilleur prix à partir d'une plus grande quantité.</p>

            <div id="tiers_wrapper" class="space-y-2"></div>
            <p id="tiers_empty_msg" class="text-xs text-yellow-300 mt-2 hidden">Ajoutez au moins un prix (ex. à partir de 8 pièces, 83 CNY la pièce).</p>

            @error('tiers')<p class="mt-2 text-sm text-red-400">{{ $message }}</p>@enderror
            @foreach($errors->get('tiers.*') as $key => $messages)
                @foreach($messages as $message)
                    <p class="mt-2 text-sm text-red-400">Ligne {{ (int) explode('.', $key)[1] + 1 }} : {{ $message }}</p>
                @endforeach
            @endforeach

            <label class="mt-3 flex items-start cursor-pointer">
                <input type="hidden" name="tier_mix_variants" value="0">
                <input type="checkbox" name="tier_mix_variants" id="tier_mix_variants" value="1"
                       {{ old('tier_mix_variants', $wholesaleProduct?->tier_mix_variants ?? true) ? 'checked' : '' }}
                       class="mr-2 mt-0.5 w-4 h-4 text-primary-500 focus:ring-primary-500 rounded">
                <span class="text-xs text-gray-300">Additionner les couleurs / tailles pour atteindre une quantité <span class="text-gray-500">(60 bleus + 40 rouges = 100)</span></span>
            </label>
        </div>

        <div id="shipping_preview" class="border-t border-dark-300 pt-3 hidden">
            <h3 class="text-xs font-semibold text-gray-300 mb-1"><i class="fas fa-ship text-primary-500 mr-1"></i>Transport configuré pour ce pays</h3>
            <div id="shipping_list" class="text-xs text-gray-400 space-y-0.5"></div>
        </div>
    </div>
</div>

@push('scripts')
<script>
(function () {
    const countries = @json($freightPreview);
    const initialTiers = @json($tiersForForm);
    const LEGACY = ['pack_size', 'weight_kg', 'length_cm', 'width_cm', 'height_cm', 'cbm'];
    const byId = (id) => document.getElementById(id);
    const num = (v) => parseFloat(String(v ?? '').replace(',', '.')) || 0;
    const val = (id) => num(byId(id)?.value);
    const fmt = (n, d = 0) => Number(n).toLocaleString('fr-FR', { maximumFractionDigits: d });
    const esc = (v) => String(v ?? '').replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');
    const filled = (v) => v !== null && v !== undefined && v !== '';
    const currency = () => document.querySelector('select[name="currency"]')?.value || 'XAF';
    const rules = () => countries[document.querySelector('select[name="origin_country"]')?.value];
    const isWholesale = () => !!byId('is_wholesale')?.checked && byId('wholesale_section')?.style.display !== 'none';
    const isArticle = () => (document.querySelector('input[name="type"]:checked')?.value ?? 'article') === 'article';
    let index = 0;

    function rowHtml(data) {
        const i = index++;
        // Ancien format : poids / dimensions / CBM propres au palier, gardés tels quels.
        const legacy = ['weight_kg', 'length_cm', 'width_cm', 'height_cm', 'cbm'].some(k => filled(data[k]));
        const hidden = legacy ? LEGACY.filter(k => filled(data[k]))
            .map(k => `<input type="hidden" name="tiers[${i}][${k}]" value="${esc(data[k])}" data-legacy>`).join('') : '';
        return `
        <div class="grid grid-cols-12 gap-2 items-end bg-dark-50 p-3 rounded-lg" data-tier-row>
            ${hidden}
            <div class="col-span-5">
                <label class="block text-xs text-gray-400 mb-1">À partir de (pièces)</label>
                <input type="number" min="1" step="1" required name="tiers[${i}][min_quantity]" value="${esc(data.min_quantity)}"
                       class="w-full px-3 py-2 bg-dark-100 border border-dark-300 rounded text-white text-sm">
            </div>
            <div class="col-span-5">
                <label class="block text-xs text-gray-400 mb-1">Prix d'une pièce (<span data-currency>${esc(currency())}</span>)</label>
                <input type="number" min="0" step="0.01" required name="tiers[${i}][unit_price]" value="${esc(data.unit_price)}"
                       class="w-full px-3 py-2 bg-dark-100 border border-dark-300 rounded text-white text-sm">
            </div>
            <div class="col-span-2">
                <button type="button" data-remove-tier title="Supprimer cette ligne" aria-label="Supprimer cette ligne"
                        class="w-full px-3 py-2 bg-red-500/80 text-white rounded text-sm hover:bg-red-600"><i class="fas fa-trash"></i></button>
            </div>
            <div class="col-span-12 text-xs text-primary-200" data-tier-preview></div>
            ${legacy ? `<div class="col-span-12 text-xs text-yellow-300" data-legacy-note>Ancienne saisie : ce prix a son propre poids ou volume.
                <button type="button" data-drop-legacy class="underline">Utiliser le carton ci-dessus</button></div>` : ''}
        </div>`;
    }

    window.addTierRow = function (data = {}) {
        const wrapper = byId('tiers_wrapper');
        if (!wrapper) return;
        if (!filled(data.min_quantity)) {
            const last = [...wrapper.querySelectorAll('input[name$="[min_quantity]"]')].pop();
            data.min_quantity = last ? (num(last.value) || 1) * 2 : (val('pieces_per_carton') || 1);
        }
        wrapper.insertAdjacentHTML('beforeend', rowHtml(data));
        refresh();
    };

    function measure(quantity) {
        const pieces = val('pieces_per_carton');
        const cartonWeight = val('carton_weight_kg');
        const cbm = val('cbm');
        const cartons = pieces ? Math.ceil(quantity / pieces) : 0;
        const weight = pieces ? cartonWeight / pieces * quantity : 0;
        return { cartons, weight, volume: cbm > 0 ? cartons * cbm : null };
    }

    function prices(m, r) {
        if (!r) return [];
        return r.options.map(o => {
            if (o.mode === 'air') {
                return m.weight > r.air_max ? `avion impossible (plus de ${fmt(r.air_max)} kg)`
                    : `avion ${fmt((m.weight + (m.volume ?? 0) * 1000000 / 6000) * o.rate)} FCFA`;
            }
            if (o.type === 'per_kg') return `bateau ${fmt(m.weight * o.rate)} FCFA (ancien tarif au kg)`;
            const cbm = m.volume ?? m.weight / 1000;
            return `bateau ${fmt(cbm * o.rate)} FCFA${m.volume === null ? ' (estimé, sans CBM)' : ''}`;
        });
    }

    function refresh() {
        const r = rules();
        const pieces = val('pieces_per_carton');
        const cartonWeight = val('carton_weight_kg');
        const cbm = val('cbm');
        const summary = byId('carton_summary');
        const tooHeavy = r && cartonWeight > r.air_max;
        const cbmInput = byId('cbm');
        if (cbmInput) cbmInput.required = isWholesale() && isArticle() && tooHeavy;
        if (summary) {
            summary.className = 'mt-3 text-sm ' + (tooHeavy && !cbm ? 'text-red-400' : 'text-primary-200');
            if (!pieces || !cartonWeight) {
                summary.textContent = 'Renseignez les pièces et le poids du carton.';
            } else if (tooHeavy && !cbm) {
                summary.textContent = `Carton de ${fmt(cartonWeight, 2)} kg : trop lourd pour l'avion (plus de ${fmt(r.air_max)} kg). Renseignez son volume en CBM.`;
            } else {
                const sea = r?.options.find(o => o.mode === 'sea');
                const parts = [`1 pièce = ${fmt(cartonWeight / pieces, 3)} kg`];
                if (sea && sea.type === 'per_kg') parts.push(`bateau encore au kilo (${fmt(sea.rate)} / kg) : ${fmt(cartonWeight * sea.rate)} FCFA par carton`);
                else if (sea && cbm) parts.push(`bateau ${fmt(cbm * sea.rate)} FCFA par carton (${fmt(cbm, 4)} CBM × ${fmt(sea.rate)})`);
                if (!cbm) parts.push('CBM vide : le bateau est estimé au poids');
                if (r) parts.push(tooHeavy ? 'avion impossible' : `avion possible jusqu'à ${fmt(Math.floor(r.air_max / cartonWeight))} carton(s)`);
                summary.textContent = parts.join(' · ');
            }
        }
        document.querySelectorAll('[data-currency]').forEach(el => { el.textContent = currency(); });
        document.querySelectorAll('[data-tier-row]').forEach(row => {
            const target = row.querySelector('[data-tier-preview]');
            const quantity = num(row.querySelector('input[name$="[min_quantity]"]')?.value);
            const price = num(row.querySelector('input[name$="[unit_price]"]')?.value);
            if (!target) return;
            if (!quantity || !pieces) { target.textContent = ''; return; }
            const m = measure(quantity);
            target.textContent = [`${fmt(quantity)} pièces = ${fmt(m.cartons)} carton(s), ${fmt(m.weight, 2)} kg`,
                price ? `marchandise ${fmt(price * quantity, 2)} ${currency()}` : null, ...prices(m, r)].filter(Boolean).join(' · ');
        });
        byId('tiers_empty_msg')?.classList.toggle('hidden', !!byId('tiers_wrapper')?.children.length);
    }

    // Le bloc gros remplace le poids unitaire, les dimensions et le prix : un seul jeu de champs part.
    window.syncWholesaleFields = function () {
        const on = isWholesale();
        byId('tiers_container')?.classList.toggle('hidden', !on);
        document.querySelectorAll('#tiers_container input, #tiers_container button').forEach(el => {
            if (!el.matches('[data-carton-size]')) el.disabled = !on;
        });
        ['pieces_per_carton', 'carton_weight_kg'].forEach(id => { if (byId(id)) byId(id).required = on && isArticle(); });
        document.querySelectorAll('[data-general-dimension]').forEach(el => { el.disabled = on; });
        byId('general_dimensions')?.classList.toggle('hidden', on);
        // Prix affiché = prix de la 1re ligne, calculé par le serveur.
        document.querySelectorAll('input[name="price_type"], input[name="price"], input[name="min_price"], input[name="max_price"]')
            .forEach(el => { el.disabled = on; });
        ['price_type_field', 'fixed_price_field', 'variable_price_fields'].forEach(id => {
            if (on) byId(id)?.classList.add('hidden');
        });
        if (!on && typeof window.togglePriceFields === 'function') window.togglePriceFields();
        byId('price_type_field')?.classList.toggle('hidden', on);
        if (typeof window.syncImportWeightSection === 'function') window.syncImportWeightSection();
        refresh();
    };

    window.toggleWholesaleSection = function () {
        const select = document.querySelector('select[name="origin_country"]');
        const country = select?.value || '';
        const section = byId('wholesale_section');
        if (!section) return;
        // Seul un pays grossiste (Chine, Dubaï…) ouvre la vente en gros.
        const isCountry = !!countries[country];
        section.style.display = isCountry ? 'block' : 'none';
        if (isCountry) {
            byId('wholesale_country_badge').textContent = select.options[select.selectedIndex]?.text?.trim() ?? country;
            loadShippingOptions(country);
        }
        syncWholesaleFields();
    };

    function loadShippingOptions(code) {
        fetch(`/api/v1/import/${code}/shipping`).then(r => r.json()).then(response => {
            const data = response.success ? (response.shipping_options ?? []) : [];
            byId('shipping_preview')?.classList.remove('hidden');
            const seaByKg = data.some(s => s.mode === 'sea' && s.rate_type !== 'per_cbm');
            byId('shipping_list').innerHTML = data.length
                ? data.map(s => `<div>• ${esc(s.mode_label ?? s.mode)} : ${esc(s.formatted_rate)} — ${esc(s.lead_time_days)} j</div>`).join('')
                  + (seaByKg ? '<p class="mt-1 text-yellow-300">Le bateau est encore facturé au kilo. Pays d\'importation → ce pays → modifier « Bateau » et enregistrer : il passe au CBM.</p>' : '')
                : '<p class="text-yellow-300">Aucun transport configuré pour ce pays : Pays d\'importation → options d\'expédition.</p>';
        }).catch(() => byId('shipping_preview')?.classList.add('hidden'));
    }

    document.addEventListener('click', e => {
        if (e.target.closest('[data-remove-tier]')) { e.target.closest('[data-tier-row]').remove(); refresh(); }
        if (e.target.closest('[data-drop-legacy]')) {
            const row = e.target.closest('[data-tier-row]');
            row.querySelectorAll('[data-legacy], [data-legacy-note]').forEach(el => el.remove());
            refresh();
        }
        if (e.target.id === 'carton_size_apply') {
            const [l, w, h] = [...document.querySelectorAll('[data-carton-size]')].map(el => num(el.value));
            if (l && w && h) { byId('cbm').value = (l * w * h / 1000000).toFixed(4); refresh(); }
        }
    });
    document.addEventListener('input', e => { if (e.target.closest('#wholesale_section')) refresh(); });
    document.addEventListener('change', e => {
        if (e.target.name === 'origin_country') toggleWholesaleSection();
        if (e.target.id === 'is_wholesale' || e.target.name === 'type') syncWholesaleFields();
        if (e.target.name === 'currency') refresh();
    });
    document.addEventListener('DOMContentLoaded', () => {
        initialTiers.forEach(tier => addTierRow({ ...tier }));
        toggleWholesaleSection();
    });
})();
</script>
@endpush
