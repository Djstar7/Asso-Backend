@extends('admin.layouts.app')

@section('content')
<div class="p-6">
    <!-- Header -->
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-white">Pays importés</h1>
        <p class="text-gray-400">Pays d'origine proposés pour les « produits importés » (Chine, Turquie, Dubaï…). Ajoutez ou retirez un pays sans redéployer l'application.</p>
    </div>

    @if(session('success'))
        <div class="mb-6 p-4 bg-green-900/20 border-l-4 border-green-500 rounded">
            <p class="text-green-300"><i class="fas fa-check-circle mr-2"></i>{{ session('success') }}</p>
        </div>
    @endif

    @if(session('error'))
        <div class="mb-6 p-4 bg-red-900/20 border-l-4 border-red-500 rounded">
            <p class="text-red-300"><i class="fas fa-exclamation-circle mr-2"></i>{{ session('error') }}</p>
        </div>
    @endif

    @if($errors->any())
        <div class="mb-6 p-4 bg-red-900/20 border-l-4 border-red-500 rounded">
            <ul class="text-red-300 text-sm list-disc list-inside">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <!-- Formulaire d'ajout -->
    <div class="bg-dark-100 rounded-xl shadow-lg border border-dark-200 p-6 mb-6">
        <h2 class="text-lg font-semibold text-white mb-4"><i class="fas fa-plus-circle mr-2 text-primary-400"></i>Ajouter un pays</h2>
        <form action="{{ route('admin.import-countries.store') }}" method="POST">
            @csrf
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-end">
                <div>
                    <label class="block text-sm text-gray-400 mb-1">Code ISO2</label>
                    <input type="text" name="code" maxlength="2" required placeholder="IN"
                           value="{{ old('code') }}"
                           class="w-full px-3 py-2 bg-dark-50 border border-dark-200 rounded-lg text-white uppercase focus:border-primary-500 focus:outline-none">
                </div>
                <div>
                    <label class="block text-sm text-gray-400 mb-1">Nom</label>
                    <input type="text" name="name" required placeholder="Inde"
                           value="{{ old('name') }}"
                           class="w-full px-3 py-2 bg-dark-50 border border-dark-200 rounded-lg text-white focus:border-primary-500 focus:outline-none">
                </div>
                <div>
                    <label class="block text-sm text-gray-400 mb-1">Drapeau (emoji)</label>
                    <input type="text" name="flag" maxlength="16" placeholder="🇮🇳"
                           value="{{ old('flag') }}"
                           class="w-full px-3 py-2 bg-dark-50 border border-dark-200 rounded-lg text-white focus:border-primary-500 focus:outline-none">
                </div>
                <div>
                    <label class="block text-sm text-gray-400 mb-1">Ordre</label>
                    <div class="flex gap-2">
                        <input type="number" name="sort_order" min="0" value="{{ old('sort_order', 0) }}"
                               class="w-24 px-3 py-2 bg-dark-50 border border-dark-200 rounded-lg text-white focus:border-primary-500 focus:outline-none">
                        <button type="submit"
                                class="flex-1 px-4 py-2 bg-gradient-to-r from-primary-500 to-primary-600 text-white rounded-lg hover:shadow-lg transition-all">
                            <i class="fas fa-plus mr-1"></i> Ajouter
                        </button>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <!-- Liste -->
    <div class="bg-dark-100 rounded-xl shadow-lg border border-dark-200">
        @if($countries->count() > 0)
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-dark-200">
                    <thead class="bg-dark-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-white uppercase tracking-wider">Pays</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-white uppercase tracking-wider">Code</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-white uppercase tracking-wider">Ordre</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-white uppercase tracking-wider">Statut</th>
                            <th class="px-6 py-3 text-right text-xs font-semibold text-white uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="bg-dark-100 divide-y divide-dark-200">
                        @foreach($countries as $country)
                            <tr class="hover:bg-dark-50 transition-colors">
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-3">
                                        <span class="text-2xl">{{ $country->flag ?: '🏳️' }}</span>
                                        <span class="font-medium text-white">{{ $country->name }}</span>
                                    </div>
                                </td>
                                <td class="px-6 py-4">
                                    <span class="px-2.5 py-1 text-sm font-mono font-semibold rounded bg-blue-500/20 text-blue-300 border border-blue-500/50">{{ $country->code }}</span>
                                </td>
                                <td class="px-6 py-4 text-gray-300">{{ $country->sort_order }}</td>
                                <td class="px-6 py-4">
                                    <form action="{{ route('admin.import-countries.toggle-status', $country) }}" method="POST" class="inline">
                                        @csrf
                                        @method('PATCH')
                                        <button type="submit"
                                                class="px-3 py-1 text-sm font-semibold rounded-full transition-all {{ $country->is_active ? 'bg-green-500/20 text-green-300 border border-green-500/50 hover:bg-green-500/30' : 'bg-gray-500/20 text-gray-400 border border-gray-500/50 hover:bg-gray-500/30' }}">
                                            <i class="fas fa-{{ $country->is_active ? 'check-circle' : 'times-circle' }} mr-1"></i>
                                            {{ $country->is_active ? 'Actif' : 'Inactif' }}
                                        </button>
                                    </form>
                                </td>
                                <td class="px-6 py-4 text-right text-sm">
                                    <div class="flex justify-end gap-2">
                                        <a href="#"
                                            class="px-3 py-1.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors"
                                            title="Gérer les expéditions"
                                            data-shipping
                                            data-id="{{ $country->id }}"
                                            data-name="{{ $country->name }}"
                                            data-flag="{{ $country->flag }}">
                                            <i class="fas fa-ship"></i>
                                        </a>
                                        <a href="#"
                                           class="px-3 py-1.5 bg-gradient-to-r from-primary-500 to-primary-600 text-white rounded-lg hover:shadow-lg transition-all"
                                           title="Modifier"
                                           data-edit
                                           data-id="{{ $country->id }}"
                                           data-code="{{ $country->code }}"
                                           data-name="{{ $country->name }}"
                                           data-flag="{{ $country->flag }}"
                                           data-sort="{{ $country->sort_order }}">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <form action="{{ route('admin.import-countries.destroy', $country) }}" method="POST"
                                              data-confirm="Supprimer le pays {{ $country->name }} ? Les produits liés resteront mais ce pays ne sera plus proposé."
                                              class="inline">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit"
                                                    class="px-3 py-1.5 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors"
                                                    title="Supprimer">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="px-6 py-4 border-t border-dark-200">
                {{ $countries->links() }}
            </div>
        @else
            <div class="text-center py-12 text-gray-400">
                <i class="fas fa-globe text-6xl text-gray-600 mb-4"></i>
                <p class="text-lg">Aucun pays configuré</p>
                <p class="text-sm mt-2">Ajoutez un pays via le formulaire ci-dessus.</p>
            </div>
        @endif
    </div>
</div>

<!-- Modale d'édition -->
<div id="editModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/60 p-4">
    <div class="bg-dark-100 rounded-xl shadow-2xl border border-dark-200 w-full max-w-md p-6">
        <div class="flex items-center justify-between mb-4">
            <h2 class="text-lg font-semibold text-white">Modifier le pays</h2>
            <button type="button" onclick="closeEdit()" class="text-gray-400 hover:text-white"><i class="fas fa-times"></i></button>
        </div>
        <form id="editForm" method="POST">
            @csrf
            @method('PUT')
            <div class="space-y-4">
                <div>
                    <label class="block text-sm text-gray-400 mb-1">Code ISO2</label>
                    <input type="text" name="code" id="edit_code" maxlength="2" required
                           class="w-full px-3 py-2 bg-dark-50 border border-dark-200 rounded-lg text-white uppercase focus:border-primary-500 focus:outline-none">
                </div>
                <div>
                    <label class="block text-sm text-gray-400 mb-1">Nom</label>
                    <input type="text" name="name" id="edit_name" required
                           class="w-full px-3 py-2 bg-dark-50 border border-dark-200 rounded-lg text-white focus:border-primary-500 focus:outline-none">
                </div>
                <div>
                    <label class="block text-sm text-gray-400 mb-1">Drapeau (emoji)</label>
                    <input type="text" name="flag" id="edit_flag" maxlength="16"
                           class="w-full px-3 py-2 bg-dark-50 border border-dark-200 rounded-lg text-white focus:border-primary-500 focus:outline-none">
                </div>
                <div>
                    <label class="block text-sm text-gray-400 mb-1">Ordre d'affichage</label>
                    <input type="number" name="sort_order" id="edit_sort" min="0"
                           class="w-full px-3 py-2 bg-dark-50 border border-dark-200 rounded-lg text-white focus:border-primary-500 focus:outline-none">
                </div>
            </div>
            <div class="flex justify-end gap-2 mt-6">
                <button type="button" onclick="closeEdit()" class="px-4 py-2 bg-dark-200 text-gray-300 rounded-lg hover:bg-dark-50">Annuler</button>
                <button type="submit" class="px-4 py-2 bg-gradient-to-r from-primary-500 to-primary-600 text-white rounded-lg hover:shadow-lg">Enregistrer</button>
            </div>
        </form>
    </div>
</div>

<!-- Modale de gestion des expéditions -->
<div id="shippingModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/60 p-4">
    <div class="bg-dark-100 rounded-xl shadow-2xl border border-dark-200 w-full max-w-3xl p-6 max-h-[90vh] overflow-y-auto">
        <div class="flex items-center justify-between mb-4">
            <h2 class="text-lg font-semibold text-white">
                <i class="fas fa-ship text-primary-400 mr-2"></i>
                Expéditions — <span id="shipping_country_label"></span>
            </h2>
            <button type="button" onclick="closeShipping()" class="text-gray-400 hover:text-white">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <div id="shipping_list_container" class="space-y-2 mb-6"></div>

        <div class="border-t border-dark-300 pt-4">
            <h3 class="text-sm font-semibold text-white mb-3">Ajouter / Modifier une option</h3>
            <form id="shippingForm" class="grid grid-cols-1 md:grid-cols-6 gap-3 items-end">
                <div class="md:col-span-1">
                    <label class="block text-xs text-gray-400 mb-1">Mode</label>
                    <select name="mode" id="shipping_mode" required
                            class="w-full px-3 py-2 bg-dark-50 border border-dark-200 rounded-lg text-white text-sm">
                        <option value="air">Avion</option>
                        <option value="sea">Bateau</option>
                        <option value="express">Express</option>
                    </select>
                </div>
                <div class="md:col-span-1">
                    <label class="block text-xs text-gray-400 mb-1">Tarif</label>
                    <select name="rate_type" id="shipping_rate_type" required
                            class="w-full px-3 py-2 bg-dark-50 border border-dark-200 rounded-lg text-white text-sm">
                        <option value="per_kg">Par kg</option>
                        <option value="flat">Forfait</option>
                    </select>
                </div>
                <div class="md:col-span-1">
                    <label class="block text-xs text-gray-400 mb-1">Montant (FCFA)</label>
                    <input type="number" step="0.01" min="0" name="rate_amount" id="shipping_rate_amount" required
                           class="w-full px-3 py-2 bg-dark-50 border border-dark-200 rounded-lg text-white text-sm">
                </div>
                <div class="md:col-span-1">
                    <label class="block text-xs text-gray-400 mb-1">Délai (jours)</label>
                    <input type="number" min="1" name="lead_time_days" id="shipping_lead_time" required
                           class="w-full px-3 py-2 bg-dark-50 border border-dark-200 rounded-lg text-white text-sm">
                </div>
                <div class="md:col-span-2">
                    <label class="block text-xs text-gray-400 mb-1">Note</label>
                    <input type="text" name="expedition_note" id="shipping_note"
                           placeholder="Ex: Commande du 11 au 15, arrivée le 20"
                           class="w-full px-3 py-2 bg-dark-50 border border-dark-200 rounded-lg text-white text-sm">
                </div>
                <div class="md:col-span-6 flex justify-end gap-2">
                    <button type="button" id="shipping_cancel_edit" onclick="resetShippingForm()"
                            class="hidden px-4 py-2 bg-dark-200 text-gray-300 rounded-lg hover:bg-dark-50 text-sm">
                        Annuler modif.
                    </button>
                    <button type="submit"
                            class="px-4 py-2 bg-gradient-to-r from-primary-500 to-primary-600 text-white rounded-lg hover:shadow-lg text-sm">
                        <i class="fas fa-save mr-1"></i> <span id="shipping_submit_label">Ajouter</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<script>
    const editBaseUrl = "{{ url('admin/import-countries') }}";
    function closeEdit() {
        const m = document.getElementById('editModal');
        m.classList.add('hidden');
        m.classList.remove('flex');
    }
    document.querySelectorAll('[data-edit]').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            document.getElementById('editForm').action = editBaseUrl + '/' + this.dataset.id;
            document.getElementById('edit_code').value = this.dataset.code;
            document.getElementById('edit_name').value = this.dataset.name;
            document.getElementById('edit_flag').value = this.dataset.flag;
            document.getElementById('edit_sort').value = this.dataset.sort;
            const m = document.getElementById('editModal');
            m.classList.remove('hidden');
            m.classList.add('flex');
        });
    });

    const shippingBaseUrl = "{{ url('admin/import-countries') }}";
let currentShippingCountryId = null;
let editingShippingOptionId = null;

document.querySelectorAll('[data-shipping]').forEach(function (btn) {
    btn.addEventListener('click', function (e) {
        e.preventDefault();
        currentShippingCountryId = this.dataset.id;
        document.getElementById('shipping_country_label').textContent =
            `${this.dataset.flag ?? ''} ${this.dataset.name}`.trim();
        resetShippingForm();
        loadShippingList();
        const m = document.getElementById('shippingModal');
        m.classList.remove('hidden');
        m.classList.add('flex');
    });
});

function closeShipping() {
    const m = document.getElementById('shippingModal');
    m.classList.add('hidden');
    m.classList.remove('flex');
}

function loadShippingList() {
    fetch(`${shippingBaseUrl}/${currentShippingCountryId}/shipping-options`)
        .then(r => r.json())
        .then(res => {
            const container = document.getElementById('shipping_list_container');
            const options = res.shipping_options ?? [];

            if (!options.length) {
                container.innerHTML = '<p class="text-sm text-gray-500">Aucune option configurée pour ce pays.</p>';
                return;
            }

            container.innerHTML = options.map(o => `
                <div class="flex items-center justify-between bg-dark-50 p-3 rounded-lg">
                    <div class="text-sm text-white">
                        <strong>${o.mode.toUpperCase()}</strong> —
                        ${o.rate_type === 'flat'
                            ? Number(o.rate_amount).toLocaleString() + ' FCFA (forfait)'
                            : Number(o.rate_amount).toLocaleString() + ' FCFA/kg'}
                        — ${o.lead_time_days}j
                        ${o.expedition_note ? '· ' + o.expedition_note : ''}
                        ${!o.is_active ? '<span class="ml-2 text-xs text-gray-500">(inactif)</span>' : ''}
                    </div>
                    <div class="flex gap-2 flex-shrink-0">
                        <button type="button" onclick='editShippingOption(${JSON.stringify(o)})'
                                class="px-2 py-1 bg-primary-600 text-white text-xs rounded hover:bg-primary-700">
                            <i class="fas fa-edit"></i>
                        </button>
                        <button type="button" onclick="toggleShippingOption(${o.id})"
                                class="px-2 py-1 bg-yellow-600 text-white text-xs rounded hover:bg-yellow-700">
                            <i class="fas fa-power-off"></i>
                        </button>
                        <button type="button" onclick="deleteShippingOption(${o.id})"
                                class="px-2 py-1 bg-red-600 text-white text-xs rounded hover:bg-red-700">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                </div>
            `).join('');
        });
}

document.getElementById('shippingForm').addEventListener('submit', function (e) {
    e.preventDefault();

    const payload = {
        mode: document.getElementById('shipping_mode').value,
        rate_type: document.getElementById('shipping_rate_type').value,
        rate_amount: document.getElementById('shipping_rate_amount').value,
        lead_time_days: document.getElementById('shipping_lead_time').value,
        expedition_note: document.getElementById('shipping_note').value,
    };

    const url = editingShippingOptionId
        ? `${shippingBaseUrl}/${currentShippingCountryId}/shipping-options/${editingShippingOptionId}`
        : `${shippingBaseUrl}/${currentShippingCountryId}/shipping-options`;

    fetch(url, {
        method: editingShippingOptionId ? 'PUT' : 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            'Accept': 'application/json',
        },
        body: JSON.stringify(payload),
    })
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                resetShippingForm();
                loadShippingList();
            } else {
                alert("Erreur lors de l'enregistrement.");
            }
        })
        .catch(() => alert("Erreur réseau lors de l'enregistrement."));
});

function editShippingOption(option) {
    editingShippingOptionId = option.id;
    document.getElementById('shipping_mode').value = option.mode;
    document.getElementById('shipping_rate_type').value = option.rate_type;
    document.getElementById('shipping_rate_amount').value = option.rate_amount;
    document.getElementById('shipping_lead_time').value = option.lead_time_days;
    document.getElementById('shipping_note').value = option.expedition_note ?? '';
    document.getElementById('shipping_submit_label').textContent = 'Mettre à jour';
    document.getElementById('shipping_cancel_edit').classList.remove('hidden');
}

function resetShippingForm() {
    editingShippingOptionId = null;
    document.getElementById('shippingForm').reset();
    document.getElementById('shipping_submit_label').textContent = 'Ajouter';
    document.getElementById('shipping_cancel_edit').classList.add('hidden');
}

function toggleShippingOption(id) {
    fetch(`${shippingBaseUrl}/${currentShippingCountryId}/shipping-options/${id}/toggle-status`, {
        method: 'PATCH',
        headers: {
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            'Accept': 'application/json',
        },
    }).then(() => loadShippingList());
}

function deleteShippingOption(id) {
    window.customConfirm("Supprimer cette option d'expédition ?", function () {
        fetch(`${shippingBaseUrl}/${currentShippingCountryId}/shipping-options/${id}`, {
            method: 'DELETE',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                'Accept': 'application/json',
            },
        }).then(() => loadShippingList());
    });
}
</script>
@endsection
