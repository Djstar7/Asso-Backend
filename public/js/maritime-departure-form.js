(function () {
    'use strict';
    function init() {
        document.querySelectorAll('[data-freight-departure]').forEach(form => {
            const hubs = JSON.parse(form.dataset.hubs);
            const rates = JSON.parse(form.dataset.rates);
            const origin = form.querySelector('[name="origin_hub_id"]');
            const destination = form.querySelector('[name="destination_hub_id"]');
            const rateSelect = form.querySelector('[name="partner_freight_rate_id"]');
            if (!origin || !destination || !rateSelect) return;
            for (const select of [origin, destination]) {
                if (select.querySelector('option[value=""]')) continue;
                const empty = document.createElement('option');
                empty.value = '';
                empty.textContent = 'Choisir un QG';
                select.prepend(empty);
            }
            const country = value => hubs.find(h => String(h.id) === value)?.country;
            function sync() {
                const rate = rates.find(r => String(r.id) === rateSelect.value);
                const price = form.querySelector('[name="price"]');
                const currency = form.querySelector('[name="currency"]');
                const summary = form.querySelector('[data-rate-summary]');
                if (price) {
                    price.disabled = !!rate;
                    if (rate) {
                        price.value = rate.rate_amount;
                        price.dispatchEvent(new Event('input', { bubbles: true }));
                    }
                }
                if (currency) {
                    currency.disabled = !!rate;
                    if (rate) currency.value = rate.currency;
                }
                if (summary) {
                    summary.textContent = rate ? `Partenaire : ${rate.rate_amount} ${rate.currency}/${rate.rate_unit === 'cbm' ? 'CBM' : 'kg'}` +
                        (rate.public_rate_amount != null ? ` · Client : ${rate.public_rate_amount} ${rate.currency} · Marge ASSO : ${(Number(rate.public_rate_amount) - Number(rate.rate_amount)).toFixed(2)} ${rate.currency} par unité` : ' · Prix client selon la commission configurée') : '';
                }
                const from = country(origin.value);
                const to = country(destination.value);
                for (const option of origin.options) {
                    if (!option.value) continue;
                    const c = country(option.value);
                    option.disabled = !c || option.value === destination.value || (rate && c !== rate.origin_country);
                }
                for (const option of destination.options) {
                    if (!option.value) continue;
                    const c = country(option.value);
                    option.disabled = !c || option.value === origin.value || (rate && c !== rate.destination_country);
                }
                for (const option of rateSelect.options) {
                    if (!option.value) continue;
                    const r = rates.find(item => String(item.id) === option.value);
                    option.disabled = !r || r.rate_unit !== (r.transport_mode === 'sea' ? 'cbm' : 'kg') || (from && r.origin_country !== from) || (to && r.destination_country !== to);
                }
                const invalid = (origin.selectedOptions[0]?.disabled || destination.selectedOptions[0]?.disabled || rateSelect.selectedOptions[0]?.disabled);
                const message = invalid ? 'Choisissez des QG actifs correspondant au trajet du tarif.' : '';
                origin.setCustomValidity(message);
                destination.setCustomValidity(message);
                rateSelect.setCustomValidity(message);
                form.querySelector('[type="submit"]').disabled = !!invalid;
                form.dispatchEvent(new CustomEvent('freight-unit', { detail: rate?.transport_mode === 'air' ? 'kg' : 'cbm' }));
            }
            for (const select of [origin, destination, rateSelect]) select.addEventListener('change', sync);
            sync();
        });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
