@php
    $variantService = app(\App\Services\ProductVariantService::class);
    $variantRows = old('variants');
    $variantOptions = old('variant_options');

    if ($variantRows === null && isset($product)) {
        $variantRows = $product->variants->map(fn ($variant) => [
            'attributes' => $variant->attributes,
            'sku' => $variant->sku,
            'price_adjustment' => (float) $variant->price_adjustment,
            'stock' => $variant->stock,
            'is_active' => $variant->is_active ? 1 : 0,
        ])->all();
        $variantOptions = $variantService->buildOptions(
            $product->variants->pluck('attributes')->filter()->all(),
            $product->variant_options,
        );
    }

    $variantRows = collect($variantRows ?? [])->map(function ($row) use ($variantService) {
        $row['attributes'] = $variantService->normalizeAttributes(
            $row['attributes'] ?? null, $row['attribute_type'] ?? null, $row['attribute_value'] ?? null,
        );
        return $row;
    })->filter(fn ($row) => $row['attributes'] !== [])->values()->all();

    if (is_string($variantOptions)) {
        $variantOptions = json_decode($variantOptions, true);
    }
    // Anciennes données sans groupes enregistrés : on les reconstitue depuis les variantes.
    $variantOptions = $variantService->buildOptions(array_column($variantRows, 'attributes'), $variantOptions ?? [])
        ?? (is_array($variantOptions) ? $variantOptions : []);
@endphp

<div class="bg-dark-100 rounded-xl shadow-lg overflow-hidden" id="variant_builder">
    {{-- En-tête --}}
    <div class="p-6 border-b border-dark-300 bg-gradient-to-r from-primary-500/10 to-transparent">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h3 class="text-lg font-bold text-white flex items-center">
                    <i class="fas fa-swatchbook text-primary-500 mr-2"></i>Couleurs, tailles & autres choix
                </h3>
                <p class="mt-1 text-sm text-gray-400">
                    Ajoutez les options que le client pourra choisir (ex. couleur d'un téléphone, couleur + pointure d'une chaussure).
                    Toutes les combinaisons sont créées automatiquement, il ne reste qu'à saisir les quantités.
                </p>
            </div>
            <div id="vb_total_badge" class="hidden shrink-0 px-4 py-2 rounded-lg bg-dark-50 border border-dark-300 text-center">
                <div class="text-xs text-gray-400">Stock total</div>
                <div class="text-xl font-bold text-primary-500" id="vb_total_stock">0</div>
            </div>
        </div>
    </div>

    <div class="p-6 space-y-6">
        {{-- Étape 1 : options --}}
        <div>
            <div class="flex items-center gap-2 mb-3">
                <span class="w-6 h-6 rounded-full bg-primary-600 text-white text-xs font-bold flex items-center justify-center">1</span>
                <h4 class="font-semibold text-white">Options proposées au client</h4>
            </div>

            <div id="vb_groups" class="space-y-4"></div>

            <div class="mt-4 flex flex-wrap items-center gap-2" id="vb_add_buttons">
                <span class="text-sm text-gray-400 mr-1">Ajouter :</span>
                <button type="button" data-add-group="Couleur" class="vb-add px-3 py-2 rounded-lg bg-dark-50 border border-dark-300 text-sm text-white hover:border-primary-500 hover:text-primary-400 transition">
                    <i class="fas fa-palette mr-1 text-pink-400"></i> Couleur
                </button>
                <button type="button" data-add-group="Taille" class="vb-add px-3 py-2 rounded-lg bg-dark-50 border border-dark-300 text-sm text-white hover:border-primary-500 hover:text-primary-400 transition">
                    <i class="fas fa-tshirt mr-1 text-blue-400"></i> Taille
                </button>
                <button type="button" data-add-group="Pointure" class="vb-add px-3 py-2 rounded-lg bg-dark-50 border border-dark-300 text-sm text-white hover:border-primary-500 hover:text-primary-400 transition">
                    <i class="fas fa-shoe-prints mr-1 text-amber-400"></i> Pointure
                </button>
                <button type="button" data-add-group="Stockage" class="vb-add px-3 py-2 rounded-lg bg-dark-50 border border-dark-300 text-sm text-white hover:border-primary-500 hover:text-primary-400 transition">
                    <i class="fas fa-memory mr-1 text-green-400"></i> Stockage
                </button>
                <button type="button" data-add-group="Dimensions" class="vb-add px-3 py-2 rounded-lg bg-dark-50 border border-dark-300 text-sm text-white hover:border-primary-500 hover:text-primary-400 transition">
                    <i class="fas fa-ruler-combined mr-1 text-cyan-400"></i> Dimensions
                </button>
                <button type="button" data-add-group="" class="vb-add px-3 py-2 rounded-lg bg-dark-50 border border-dashed border-dark-300 text-sm text-gray-300 hover:border-primary-500 hover:text-primary-400 transition">
                    <i class="fas fa-plus mr-1"></i> Autre option
                </button>
            </div>
            <p id="vb_empty" class="mt-3 text-sm text-gray-500">
                <i class="fas fa-info-circle mr-1"></i>Sans option, le produit est vendu tel quel avec la quantité générale.
            </p>
        </div>

        {{-- Étape 2 : combinaisons --}}
        <div id="vb_matrix_section" class="hidden">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3 mb-3">
                <div class="flex items-center gap-2">
                    <span class="w-6 h-6 rounded-full bg-primary-600 text-white text-xs font-bold flex items-center justify-center">2</span>
                    <h4 class="font-semibold text-white">Quantité par combinaison</h4>
                    <span id="vb_count" class="text-xs text-gray-400"></span>
                </div>
                <div class="flex items-center gap-2">
                    <input type="number" min="0" id="vb_bulk_stock" placeholder="Qté" class="w-24 px-3 py-2 bg-dark-50 border border-dark-300 rounded-lg text-white text-sm">
                    <button type="button" id="vb_bulk_apply" class="px-3 py-2 bg-dark-50 border border-dark-300 rounded-lg text-sm text-white hover:border-primary-500">
                        <i class="fas fa-fill-drip mr-1"></i>Appliquer à toutes
                    </button>
                </div>
            </div>
            <div class="overflow-x-auto rounded-lg border border-dark-300">
                <table class="w-full text-sm">
                    <thead class="bg-dark-50 text-gray-400 text-xs uppercase tracking-wide">
                        <tr>
                            <th class="px-4 py-3 text-left">Combinaison</th>
                            <th class="px-4 py-3 text-left w-32">Quantité</th>
                            <th class="px-4 py-3 text-left w-40">Supplément prix</th>
                            <th class="px-4 py-3 text-left w-40">Référence</th>
                            <th class="px-4 py-3 text-center w-24">Visible</th>
                        </tr>
                    </thead>
                    <tbody id="vb_rows" class="divide-y divide-dark-300"></tbody>
                </table>
            </div>
            <p class="mt-2 text-xs text-gray-500">
                Le supplément s'ajoute au prix de base (ex. +5 000 pour la version 256 Go). Une quantité à 0 affiche « épuisé » au client.
            </p>
            <p class="mt-1 text-xs text-gray-500">
                <i class="fas fa-info-circle mr-1"></i>Produit vendu en gros : la quantité est ignorée, toutes les options restent commandables. Le client répartit sa commande entre les options, et la quantité minimale du palier s'applique au total.
            </p>
        </div>

        <div id="vb_hidden_inputs"></div>
        @error('variants')<p class="text-sm text-red-400">{{ $message }}</p>@enderror
        @error('variants.*')<p class="text-sm text-red-400">{{ $message }}</p>@enderror
    </div>
</div>

@push('scripts')
<script>
(function () {
    const PRESETS = {
        'couleur': ['Noir', 'Blanc', 'Gris', 'Rouge', 'Bleu', 'Bleu marine', 'Vert', 'Jaune', 'Orange', 'Rose', 'Violet', 'Marron', 'Beige', 'Doré', 'Argent'],
        'taille': ['XS', 'S', 'M', 'L', 'XL', 'XXL', '3XL'],
        'pointure': ['36', '37', '38', '39', '40', '41', '42', '43', '44', '45', '46'],
        'stockage': ['64 Go', '128 Go', '256 Go', '512 Go', '1 To'],
        'dimensions': [],
    };
    const COLOR_HEX = @json(\App\Services\ProductVariantService::DEFAULT_COLOR_HEX);
    const COLOR_NAMES = @json(\App\Services\ProductVariantService::COLOR_OPTION_NAMES);

    let groups = (@json($variantOptions) || []).map(g => ({
        name: g.name, type: g.type === 'color' ? 'color' : 'text',
        values: (g.values || []).map(v => ({ value: String(v.value), hex: v.hex || null })),
    }));
    // Données saisies par combinaison (conservées quand on ajoute/retire une option).
    const rowData = {};
    (@json($variantRows) || []).forEach(row => {
        rowData[signature(row.attributes)] = {
            stock: row.stock ?? 0, price_adjustment: row.price_adjustment ?? 0,
            sku: row.sku ?? '', is_active: String(row.is_active ?? 1) !== '0',
        };
    });

    const $ = id => document.getElementById(id);
    const esc = v => String(v ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const isColorName = name => COLOR_NAMES.includes(String(name).trim().toLowerCase());
    const cap = s => s ? s.charAt(0).toUpperCase() + s.slice(1) : s;

    function guessHex(name) {
        const v = String(name).trim().toLowerCase();
        const keys = Object.keys(COLOR_HEX).sort((a, b) => b.length - a.length);
        const key = keys.find(k => v === k || v.includes(k));
        return key ? COLOR_HEX[key] : '#9E9E9E';
    }

    function signature(attributes) {
        const norm = {};
        Object.keys(attributes || {}).forEach(k => norm[k.trim().toLowerCase()] = String(attributes[k]).trim().toLowerCase());
        return JSON.stringify(Object.keys(norm).sort().map(k => [k, norm[k]]));
    }

    function combinations() {
        const valid = groups.filter(g => g.name.trim() && g.values.length);
        if (!valid.length) return [];
        return valid.reduce((acc, g) => acc.flatMap(combo => g.values.map(v => ({ ...combo, [g.name.trim()]: v.value }))), [{}]);
    }

    function hexFor(groupName, value) {
        const g = groups.find(x => x.name === groupName);
        if (!g || g.type !== 'color') return null;
        return g.values.find(v => v.value === value)?.hex || guessHex(value);
    }

    // ---------- Rendu des groupes ----------
    function renderGroups() {
        $('vb_groups').innerHTML = groups.map((g, gi) => {
            const presets = (PRESETS[g.name.trim().toLowerCase()] || [])
                .filter(p => !g.values.some(v => v.value.toLowerCase() === p.toLowerCase()));
            const chips = g.values.map((v, vi) => g.type === 'color'
                ? `<span class="group inline-flex items-center gap-2 pl-1 pr-2 py-1 rounded-full bg-dark-50 border border-dark-300 text-white text-sm">
                      <label class="relative w-7 h-7 rounded-full border-2 border-white/30 cursor-pointer shadow" style="background:${esc(v.hex || guessHex(v.value))}" title="Changer la teinte">
                        <input type="color" value="${esc(v.hex || guessHex(v.value))}" data-hex="${gi}:${vi}" class="absolute inset-0 opacity-0 cursor-pointer">
                      </label>
                      ${esc(v.value)}
                      <button type="button" data-remove-value="${gi}:${vi}" class="w-5 h-5 rounded-full text-gray-400 hover:bg-red-600 hover:text-white" title="Retirer"><i class="fas fa-times text-xs"></i></button>
                   </span>`
                : `<span class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full bg-primary-600/20 border border-primary-500/40 text-white text-sm font-medium">
                      ${esc(v.value)}
                      <button type="button" data-remove-value="${gi}:${vi}" class="w-5 h-5 rounded-full text-gray-300 hover:bg-red-600 hover:text-white" title="Retirer"><i class="fas fa-times text-xs"></i></button>
                   </span>`).join('');

            const presetButtons = presets.map(p => g.type === 'color'
                ? `<button type="button" data-preset="${gi}" data-value="${esc(p)}" title="${esc(p)}" class="inline-flex items-center gap-1.5 px-2 py-1 rounded-full border border-dark-300 text-xs text-gray-300 hover:border-primary-500 hover:text-white">
                      <span class="w-4 h-4 rounded-full border border-white/20" style="background:${esc(guessHex(p))}"></span>${esc(p)}</button>`
                : `<button type="button" data-preset="${gi}" data-value="${esc(p)}" class="px-2.5 py-1 rounded-md border border-dark-300 text-xs text-gray-300 hover:border-primary-500 hover:text-white">+ ${esc(p)}</button>`).join('');

            return `
            <div class="rounded-xl border border-dark-300 bg-dark-50/60 p-4">
              <div class="flex items-center gap-3">
                <div class="w-9 h-9 rounded-lg flex items-center justify-center ${g.type === 'color' ? 'bg-pink-500/15 text-pink-400' : 'bg-primary-500/15 text-primary-400'}">
                  <i class="fas ${g.type === 'color' ? 'fa-palette' : 'fa-tag'}"></i>
                </div>
                <input value="${esc(g.name)}" data-group-name="${gi}" placeholder="Nom de l'option (ex. Matière)"
                       class="flex-1 px-3 py-2 bg-dark-100 border border-dark-300 rounded-lg text-white font-semibold">
                <label class="hidden md:flex items-center gap-2 text-xs text-gray-400 whitespace-nowrap">
                  <input type="checkbox" data-group-color="${gi}" ${g.type === 'color' ? 'checked' : ''}> Pastilles de couleur
                </label>
                <button type="button" data-remove-group="${gi}" class="px-3 py-2 rounded-lg text-gray-400 hover:bg-red-600 hover:text-white" title="Supprimer cette option">
                  <i class="fas fa-trash"></i>
                </button>
              </div>
              <div class="mt-3 flex flex-wrap items-center gap-2">
                ${chips || '<span class="text-sm text-gray-500 italic">Aucune valeur pour l\'instant</span>'}
              </div>
              <div class="mt-3 flex gap-2">
                ${g.type === 'color' ? `<input type="color" data-new-hex="${gi}" value="#1E88E5" class="w-11 h-10 p-1 bg-dark-100 border border-dark-300 rounded-lg cursor-pointer" title="Teinte de la nouvelle couleur">` : ''}
                <input data-new-value="${gi}" placeholder="${g.type === 'color' ? 'Nom de la couleur (ex. Bleu nuit)' : 'Nouvelle valeur'} puis Entrée"
                       class="flex-1 px-3 py-2 bg-dark-100 border border-dark-300 rounded-lg text-white text-sm">
                <button type="button" data-add-value="${gi}" class="px-4 py-2 bg-primary-600 hover:bg-primary-700 text-white text-sm rounded-lg"><i class="fas fa-plus"></i></button>
              </div>
              ${presetButtons ? `<div class="mt-3 flex flex-wrap gap-1.5"><span class="text-xs text-gray-500 mr-1 self-center">Suggestions :</span>${presetButtons}</div>` : ''}
            </div>`;
        }).join('');

        $('vb_empty').classList.toggle('hidden', groups.length > 0);
        renderMatrix();
    }

    // ---------- Rendu du tableau des combinaisons ----------
    function renderMatrix() {
        const combos = combinations();
        $('vb_matrix_section').classList.toggle('hidden', combos.length === 0);
        $('vb_count').textContent = combos.length ? `(${combos.length} combinaison${combos.length > 1 ? 's' : ''})` : '';

        $('vb_rows').innerHTML = combos.map(combo => {
            const sig = signature(combo);
            const data = rowData[sig] ??= { stock: 0, price_adjustment: 0, sku: '', is_active: true };
            const labels = Object.entries(combo).map(([name, value]) => {
                const hex = hexFor(name, value);
                return `<span class="inline-flex items-center gap-1.5 px-2 py-1 rounded-md bg-dark-50 border border-dark-300 text-white">
                    ${hex ? `<span class="w-3.5 h-3.5 rounded-full border border-white/30" style="background:${esc(hex)}"></span>` : ''}
                    <span class="text-gray-400 text-xs">${esc(name)}</span> ${esc(value)}</span>`;
            }).join(' ');
            return `
            <tr class="${data.is_active ? '' : 'opacity-50'}" data-row="${esc(sig)}">
              <td class="px-4 py-3"><div class="flex flex-wrap gap-1.5">${labels}</div></td>
              <td class="px-4 py-3"><input type="number" min="0" value="${esc(data.stock)}" data-field="stock" class="w-24 px-3 py-2 bg-dark-50 border border-dark-300 rounded-lg text-white ${Number(data.stock) === 0 ? 'border-amber-600/60' : ''}"></td>
              <td class="px-4 py-3"><input type="number" step="0.01" value="${esc(data.price_adjustment)}" data-field="price_adjustment" class="w-32 px-3 py-2 bg-dark-50 border border-dark-300 rounded-lg text-white"></td>
              <td class="px-4 py-3"><input value="${esc(data.sku)}" data-field="sku" placeholder="Facultatif" class="w-32 px-3 py-2 bg-dark-50 border border-dark-300 rounded-lg text-white"></td>
              <td class="px-4 py-3 text-center">
                <label class="relative inline-flex items-center cursor-pointer">
                  <input type="checkbox" data-field="is_active" class="sr-only peer" ${data.is_active ? 'checked' : ''}>
                  <div class="w-10 h-5 bg-dark-300 rounded-full peer-checked:bg-primary-600 transition after:content-[''] after:absolute after:top-0.5 after:left-0.5 after:bg-white after:rounded-full after:h-4 after:w-4 after:transition peer-checked:after:translate-x-5"></div>
                </label>
              </td>
            </tr>`;
        }).join('');

        syncHiddenInputs();
    }

    // ---------- Champs envoyés au serveur ----------
    function syncHiddenInputs() {
        const combos = combinations();
        let html = `<input type="hidden" name="variant_options" value="${esc(JSON.stringify(groups.filter(g => g.name.trim() && g.values.length)))}">`;
        let total = 0;
        combos.forEach((combo, i) => {
            const d = rowData[signature(combo)];
            Object.entries(combo).forEach(([name, value]) => {
                html += `<input type="hidden" name="variants[${i}][attributes][${esc(name)}]" value="${esc(value)}">`;
            });
            html += `<input type="hidden" name="variants[${i}][stock]" value="${esc(parseInt(d.stock, 10) || 0)}">`;
            html += `<input type="hidden" name="variants[${i}][price_adjustment]" value="${esc(parseFloat(d.price_adjustment) || 0)}">`;
            html += `<input type="hidden" name="variants[${i}][sku]" value="${esc(d.sku)}">`;
            html += `<input type="hidden" name="variants[${i}][is_active]" value="${d.is_active ? 1 : 0}">`;
            total += parseInt(d.stock, 10) || 0;
        });
        $('vb_hidden_inputs').innerHTML = html;

        const hasVariants = combos.length > 0;
        $('vb_total_badge').classList.toggle('hidden', !hasVariants);
        $('vb_total_stock').textContent = total;
        const stock = document.querySelector('input[name="stock"]');
        if (stock) {
            stock.readOnly = hasVariants;
            stock.classList.toggle('opacity-60', hasVariants);
            stock.title = hasVariants ? 'Calculé automatiquement à partir des combinaisons' : '';
            if (hasVariants) stock.value = total;
        }
    }

    // ---------- Actions ----------
    function addGroup(name) {
        name = cap(String(name || '').trim());
        if (name && groups.some(g => g.name.toLowerCase() === name.toLowerCase())) {
            document.querySelector(`[data-new-value="${groups.findIndex(g => g.name.toLowerCase() === name.toLowerCase())}"]`)?.focus();
            return;
        }
        groups.push({ name, type: isColorName(name) ? 'color' : 'text', values: [] });
        renderGroups();
        const gi = groups.length - 1;
        document.querySelector(name ? `[data-new-value="${gi}"]` : `[data-group-name="${gi}"]`)?.focus();
    }

    function addValue(gi, value, hex = null) {
        const g = groups[gi];
        value = String(value || '').trim();
        if (!g || !value) return;
        value.split(/[,;]+/).map(v => v.trim()).filter(Boolean).forEach(v => {
            if (!g.values.some(x => x.value.toLowerCase() === v.toLowerCase())) {
                g.values.push({ value: v, hex: g.type === 'color' ? (hex || guessHex(v)) : null });
            }
        });
        renderGroups();
        document.querySelector(`[data-new-value="${gi}"]`)?.focus();
    }

    const root = $('variant_builder');

    root.addEventListener('click', e => {
        const t = e.target.closest('button');
        if (!t) return;
        if (t.dataset.addGroup !== undefined) return addGroup(t.dataset.addGroup);
        if (t.dataset.removeGroup !== undefined) {
            const g = groups[+t.dataset.removeGroup];
            if (g.values.length && !confirm(`Supprimer l'option « ${g.name} » et ses ${g.values.length} valeur(s) ?`)) return;
            groups.splice(+t.dataset.removeGroup, 1);
            return renderGroups();
        }
        if (t.dataset.removeValue !== undefined) {
            const [gi, vi] = t.dataset.removeValue.split(':').map(Number);
            groups[gi].values.splice(vi, 1);
            return renderGroups();
        }
        if (t.dataset.preset !== undefined) return addValue(+t.dataset.preset, t.dataset.value);
        if (t.dataset.addValue !== undefined) {
            const gi = +t.dataset.addValue;
            return addValue(gi, document.querySelector(`[data-new-value="${gi}"]`).value, document.querySelector(`[data-new-hex="${gi}"]`)?.value);
        }
        if (t.id === 'vb_bulk_apply') {
            const qty = parseInt($('vb_bulk_stock').value, 10);
            if (Number.isNaN(qty) || qty < 0) return $('vb_bulk_stock').focus();
            combinations().forEach(c => rowData[signature(c)].stock = qty);
            return renderMatrix();
        }
    });

    root.addEventListener('keydown', e => {
        if (e.key !== 'Enter') return;
        if (e.target.dataset.newValue !== undefined) {
            e.preventDefault();
            const gi = +e.target.dataset.newValue;
            addValue(gi, e.target.value, document.querySelector(`[data-new-hex="${gi}"]`)?.value);
        } else if (e.target.dataset.groupName !== undefined || e.target.closest('#vb_rows') || e.target.id === 'vb_bulk_stock') {
            e.preventDefault(); // ne jamais soumettre le formulaire depuis l'éditeur
        }
    });

    root.addEventListener('input', e => {
        const t = e.target;
        if (t.dataset.hex !== undefined) {
            const [gi, vi] = t.dataset.hex.split(':').map(Number);
            groups[gi].values[vi].hex = t.value.toUpperCase();
            t.parentElement.style.background = t.value;
            return renderMatrix();
        }
        if (t.dataset.field && t.dataset.field !== 'is_active') {
            rowData[t.closest('tr').dataset.row][t.dataset.field] = t.value;
            return syncHiddenInputs();
        }
    });

    root.addEventListener('change', e => {
        const t = e.target;
        if (t.dataset.groupName !== undefined) {
            const gi = +t.dataset.groupName;
            const name = cap(t.value.trim());
            // Renommer une option conserve les quantités déjà saisies.
            const old = groups[gi].name;
            Object.keys(rowData).forEach(sig => {
                const pairs = JSON.parse(sig).map(([k, v]) => [k === old.toLowerCase() ? name.toLowerCase() : k, v]);
                const next = JSON.stringify(pairs.sort((a, b) => a[0].localeCompare(b[0])));
                if (next !== sig) { rowData[next] = rowData[sig]; }
            });
            groups[gi].name = name;
            if (isColorName(name)) groups[gi].type = 'color';
            return renderGroups();
        }
        if (t.dataset.groupColor !== undefined) {
            const g = groups[+t.dataset.groupColor];
            g.type = t.checked ? 'color' : 'text';
            g.values.forEach(v => v.hex = t.checked ? (v.hex || guessHex(v.value)) : null);
            return renderGroups();
        }
        if (t.dataset.field === 'is_active') {
            rowData[t.closest('tr').dataset.row].is_active = t.checked;
            return renderMatrix();
        }
    });

    document.addEventListener('DOMContentLoaded', renderGroups);
})();
</script>
@endpush
