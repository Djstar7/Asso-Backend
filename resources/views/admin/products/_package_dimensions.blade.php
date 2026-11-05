@php
    // Tarifs clients par pays, pour l'aperçu du transport pendant la saisie.
    $freightPreview = \App\Models\ImportCountry::get()->mapWithKeys(fn ($country) => [$country->code => [
        'air_max' => \App\Services\ImportFreightService::airMaxWeightKg(),
        'divisor' => \App\Services\ImportFreightService::volumetricDivisor(),
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
        Facultatif, mais à remplir pour les gros articles : colis emballé d'<strong>une unité commandée</strong>, en <strong>centimètres</strong>.
        Sans dimensions, le transport se calcule au poids seul : une voiture de 1 200 kg serait facturée 1,2 CBM au lieu de 12,15 CBM.
        Un palier peut avoir ses propres dimensions.
    </p>
    <div class="grid grid-cols-3 gap-2 max-w-md">
        @foreach(['length_cm' => 'Longueur (cm)', 'width_cm' => 'Largeur (cm)', 'height_cm' => 'Hauteur (cm)'] as $field => $text)
            <div>
                <label class="block text-xs text-gray-400 mb-1" for="{{ $field }}">{{ $text }}</label>
                <input type="number" step="0.01" min="1" max="3000" name="{{ $field }}" id="{{ $field }}"
                       value="{{ old($field, $product->$field ?? null) }}"
                       class="w-full px-3 py-2 bg-dark-100 border border-dark-300 rounded text-white text-sm @error($field) border-red-500 @enderror">
            </div>
        @endforeach
    </div>
    @error('length_cm')<p class="mt-1 text-sm text-red-400">{{ $message }}</p>@enderror
    <p id="package_dimensions_warning" class="mt-2 text-sm text-yellow-300 hidden"><i class="fas fa-exclamation-triangle mr-1"></i>Dimensions vides : le transport sera calculé au poids seul.</p>
</div>

<script>
(function () {
    const countries = @json($freightPreview);
    const fmt = (n, d = 0) => Number(n).toLocaleString('fr-FR', { maximumFractionDigits: d });
    const number = (root, name) => parseFloat(root.querySelector(`[name$="${name}"]`)?.value) || 0;
    const form = () => document.getElementById('package_dimensions')?.closest('form') ?? document;
    const productNumber = (id) => parseFloat(document.getElementById(id)?.value) || 0;

    function measure(row) {
        const own = [number(row, '[length_cm]'), number(row, '[width_cm]'), number(row, '[height_cm]')];
        const dims = own.every(v => v > 0) ? own
            : [productNumber('length_cm'), productNumber('width_cm'), productNumber('height_cm')];
        const quantity = number(row, '[min_quantity]');
        const weight = (number(row, '[weight_kg]') || productNumber('weight')) * quantity;
        const cbm = dims.every(v => v > 0) ? dims[0] * dims[1] * dims[2] / 1000000 * quantity : 0;

        return { quantity, weight, cbm, measured: dims.every(v => v > 0) };
    }

    function preview(row) {
        const target = row.querySelector('[data-tier-preview]');
        if (!target) return;
        const m = measure(row);
        const rules = countries[form().querySelector('select[name="origin_country"]')?.value];
        if (!m.quantity || !rules) { target.textContent = ''; return; }
        const billableKg = Math.max(m.weight, m.cbm * 1000000 / rules.divisor);
        const prices = rules.options.map(o => {
            if (o.mode === 'air') {
                return billableKg > rules.air_max
                    ? `Avion retiré (plus de ${fmt(rules.air_max)} kg)`
                    : `Avion ${fmt(billableKg * o.rate)} FCFA`;
            }
            return o.type === 'per_cbm'
                ? `Bateau ${fmt(Math.max(m.cbm, m.weight / 1000) * o.rate)} FCFA`
                : `Bateau ${fmt(m.weight * o.rate)} FCFA (ancien tarif au kg)`;
        });
        const dense = m.cbm > 0 && m.weight / m.cbm > 20000;
        target.className = 'md:col-span-6 text-xs ' + (dense ? 'text-red-400' : 'text-primary-200');
        target.textContent = dense
            ? 'Dimensions incohérentes avec le poids : saisissez bien des centimètres, pas des mètres.'
            : `Quantité minimale ${fmt(m.quantity)} : ${fmt(m.cbm, 3)} CBM, ${fmt(m.weight, 3)} kg réels · ${prices.join(' · ')}`
              + (m.measured ? '' : ' · sans dimensions');
    }

    function refresh() {
        document.getElementById('package_dimensions_warning')?.classList.toggle('hidden',
            ['length_cm', 'width_cm', 'height_cm'].every(id => productNumber(id) > 0));
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
