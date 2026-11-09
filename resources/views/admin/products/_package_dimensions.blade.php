@php
    // Tarifs clients par pays, pour l'aperçu du transport pendant la saisie.
    $freightPreview = \App\Models\ImportCountry::get()->mapWithKeys(fn ($country) => [$country->code => [
        'air_max' => \App\Services\ImportFreightService::airMaxWeightKg(),
        'options' => \App\Models\ImportShippingOption::activeForCountry($country->code)->get()
            ->map(fn ($o) => ['mode' => $o->mode, 'type' => $o->rate_type, 'rate' => (float) $o->rate_amount])->values(),
    ]]);
@endphp
<div class="mb-4 rounded-lg border-2 border-primary-500/60 bg-primary-500/10 p-4" id="package_dimensions">
    <div class="flex items-center gap-2 mb-1">
        <h3 class="text-sm font-semibold text-white"><i class="fas fa-ruler-combined text-primary-400 mr-1"></i>Dimensions du colis</h3>
        <span class="px-2 py-0.5 rounded-full bg-primary-600 text-white text-xs font-bold">Nouveau</span>
    </div>
    <p class="text-xs text-gray-300 mb-3">
        Le bateau se paie au <strong>CBM</strong> : CBM d'un carton × nombre de cartons × tarif (ex. 0,139 CBM × 330 000 = 45 870 FCFA).
        Saisissez le <strong>CBM d'un carton</strong> tel qu'il figure sur le devis du fournisseur, sur chaque palier ou ici pour tous ;
        un palier compte des cartons de « Pièces / carton » pièces. À défaut, les dimensions d'une unité, en <strong>centimètres</strong>.
        <strong>Au-delà de 100 kg par carton, l'avion est refusé et le CBM est obligatoire.</strong>
    </p>
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 max-w-xl">
        <div>
            <label class="block text-xs text-gray-400 mb-1" for="cbm">CBM / carton</label>
            <input type="number" step="0.0001" min="0.0001" max="100" name="cbm" id="cbm" placeholder="0.139"
                   value="{{ old('cbm', $product->cbm ?? null) }}"
                   class="w-full px-3 py-2 bg-dark-100 border border-dark-300 rounded text-white text-sm @error('cbm') border-red-500 @enderror">
        </div>
        @foreach(['length_cm' => 'Longueur (cm)', 'width_cm' => 'Largeur (cm)', 'height_cm' => 'Hauteur (cm)'] as $field => $text)
            <div>
                <label class="block text-xs text-gray-400 mb-1" for="{{ $field }}">{{ $text }}</label>
                <input type="number" step="0.01" min="1" max="3000" name="{{ $field }}" id="{{ $field }}"
                       value="{{ old($field, $product->$field ?? null) }}"
                       class="w-full px-3 py-2 bg-dark-100 border border-dark-300 rounded text-white text-sm @error($field) border-red-500 @enderror">
            </div>
        @endforeach
    </div>
    @error('cbm')<p class="mt-1 text-sm text-red-400">{{ $message }}</p>@enderror
    @error('length_cm')<p class="mt-1 text-sm text-red-400">{{ $message }}</p>@enderror
    <p id="package_dimensions_warning" class="mt-2 text-sm text-yellow-300 hidden"><i class="fas fa-exclamation-triangle mr-1"></i>Ni CBM ni dimensions : le bateau sera calculé au poids (1 CBM pour 1 000 kg).</p>
</div>

<script>
(function () {
    const countries = @json($freightPreview);
    const fmt = (n, d = 0) => Number(n).toLocaleString('fr-FR', { maximumFractionDigits: d });
    const number = (root, name) => parseFloat(root.querySelector(`[name$="${name}"]`)?.value) || 0;
    const form = () => document.getElementById('package_dimensions')?.closest('form') ?? document;
    const productNumber = (id) => parseFloat(document.getElementById(id)?.value) || 0;

    // Même calcul que ImportFreightService : CBM du carton (palier, puis fiche) × cartons
    // entiers, sinon dimensions d'une unité, sinon 1 CBM pour 1 000 kg.
    function measure(row) {
        const quantity = number(row, '[min_quantity]');
        const perCarton = Math.max(1, number(row, '[pack_size]'));
        const unitWeight = number(row, '[weight_kg]') || productNumber('weight');
        const weight = unitWeight * quantity;
        const cartons = Math.ceil(quantity / perCarton);
        const own = [number(row, '[length_cm]'), number(row, '[width_cm]'), number(row, '[height_cm]')];
        const fiche = [productNumber('length_cm'), productNumber('width_cm'), productNumber('height_cm')];
        const volume = (dims) => dims[0] * dims[1] * dims[2] / 1000000 * quantity;
        let cbm = null;
        if (number(row, '[cbm]') > 0) cbm = number(row, '[cbm]') * cartons;
        else if (own.every(v => v > 0)) cbm = volume(own);
        else if (productNumber('cbm') > 0) cbm = productNumber('cbm') * cartons;
        else if (fiche.every(v => v > 0)) cbm = volume(fiche);

        return { quantity, weight, cartons, cartonWeight: unitWeight * perCarton, cbm, measured: cbm !== null };
    }

    function preview(row) {
        const target = row.querySelector('[data-tier-preview]');
        if (!target) return;
        const m = measure(row);
        const rules = countries[form().querySelector('select[name="origin_country"]')?.value];
        if (!m.quantity || !rules) { target.textContent = ''; return; }
        const billedCbm = m.measured ? m.cbm : m.weight / 1000;
        const prices = rules.options.map(o => {
            if (o.mode === 'air') {
                if (!m.weight) return 'Avion impossible sans poids';
                return m.weight > rules.air_max
                    ? `Avion retiré (plus de ${fmt(rules.air_max)} kg)`
                    : `Avion ${fmt((m.weight + (m.cbm ?? 0) * 1000000 / 6000) * o.rate)} FCFA`;
            }
            return o.type === 'per_cbm'
                ? `Bateau ${fmt(billedCbm, 4)} CBM × ${fmt(o.rate)} = ${fmt(billedCbm * o.rate)} FCFA`
                : `Bateau ${fmt(m.weight * o.rate)} FCFA (ancien tarif au kg)`;
        });
        const dense = m.measured && m.cbm > 0 && m.weight / m.cbm > 20000;
        const tooHeavy = m.cartonWeight > rules.air_max && !m.measured;
        target.className = 'md:col-span-4 text-xs ' + (dense || tooHeavy ? 'text-red-400' : 'text-primary-200');
        target.textContent = tooHeavy
            ? `Carton de ${fmt(m.cartonWeight, 2)} kg : au-delà de ${fmt(rules.air_max)} kg, l'avion est refusé. Renseignez le CBM du carton.`
            : dense
            ? 'Volume incohérent avec le poids : vérifiez le CBM, ou saisissez bien des centimètres.'
            : `Quantité minimale ${fmt(m.quantity)} (${fmt(m.cartons)} carton${m.cartons > 1 ? 's' : ''}) : ${fmt(billedCbm, 4)} CBM, ${fmt(m.weight, 3)} kg réels · ${prices.join(' · ')}`
              + (m.measured ? '' : ' · sans volume, estimé au poids');
    }

    function refresh() {
        document.getElementById('package_dimensions_warning')?.classList.toggle('hidden',
            productNumber('cbm') > 0 || ['length_cm', 'width_cm', 'height_cm'].every(id => productNumber(id) > 0));
        document.querySelectorAll('#tiers_wrapper > [id^="tier_row_"]').forEach(preview);
    }

    document.addEventListener('input', e => {
        if (e.target.closest('#tiers_wrapper, #package_dimensions') || e.target.id === 'weight') refresh();
    });
    document.addEventListener('change', e => { if (e.target.name === 'origin_country') refresh(); });
    document.addEventListener('DOMContentLoaded', () => {
        const wrapper = document.getElementById('tiers_wrapper');
        if (wrapper) new MutationObserver(refresh).observe(wrapper, { childList: true });
        refresh();
    });
})();
</script>
