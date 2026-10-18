@extends('admin.layouts.app')

@section('content')
<div class="p-6">
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-white">Natures de colis &amp; assurance</h1>
        <p class="text-gray-400">L'expéditeur choisit la nature de son colis dans cette liste et en déclare la valeur, en avion comme en maritime. Supplément d'assurance = valeur déclarée × taux, sans descendre sous le minimum. Le supplément revient à ASSO ; un sinistre se traite à la main, jusqu'à la valeur déclarée.</p>
    </div>

    @if(session('success'))
        <div class="mb-6 p-4 bg-green-900/20 border-l-4 border-green-500 rounded">
            <p class="text-green-300"><i class="fas fa-check-circle mr-2"></i>{{ session('success') }}</p>
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

    <!-- Seuil avion / maritime -->
    <div class="bg-dark-100 rounded-xl shadow-lg border border-dark-200 p-6 mb-6">
        <h2 class="text-lg font-semibold text-white mb-1"><i class="fas fa-balance-scale mr-2 text-primary-400"></i>Seuil avion / maritime</h2>
        <p class="text-sm text-gray-400 mb-4">Affiché dans l'app : « Avion : idéal pour les colis de moins de X kg », « Maritime : au-delà de X kg ». Indicatif, ne bloque aucune réservation.</p>
        <form action="{{ route('admin.diaspo.parcel-natures.settings') }}" method="POST" class="flex items-end gap-3">
            @csrf
            <div>
                <label class="block text-sm text-gray-300 mb-1">Seuil (kg)</label>
                <input type="number" step="0.5" min="1" name="air_max_kg_hint" value="{{ old('air_max_kg_hint', $airMaxKgHint) }}"
                       class="w-32 px-3 py-2 bg-dark-50 border border-dark-200 rounded-lg text-white focus:border-primary-500 focus:outline-none">
            </div>
            <button type="submit" class="px-4 py-2 bg-gradient-to-r from-primary-500 to-primary-600 text-white rounded-lg hover:shadow-lg">Enregistrer</button>
        </form>
    </div>

    <!-- Ajout -->
    <div class="bg-dark-100 rounded-xl shadow-lg border border-dark-200 p-6 mb-6">
        <h2 class="text-lg font-semibold text-white mb-4"><i class="fas fa-plus-circle mr-2 text-primary-400"></i>Ajouter une nature</h2>
        <form action="{{ route('admin.diaspo.parcel-natures.store') }}" method="POST" class="grid grid-cols-1 md:grid-cols-6 gap-3 items-end">
            @csrf
            <div class="md:col-span-2">
                <label class="block text-sm text-gray-300 mb-1">Nom</label>
                <input type="text" name="name" required value="{{ old('name') }}" placeholder="Téléphone"
                       class="w-full px-3 py-2 bg-dark-50 border border-dark-200 rounded-lg text-white focus:border-primary-500 focus:outline-none">
            </div>
            <div>
                <label class="block text-sm text-gray-300 mb-1">Nom (anglais)</label>
                <input type="text" name="translations[en][name]" value="{{ old('translations.en.name') }}" placeholder="Phone"
                       class="w-full px-3 py-2 bg-dark-50 border border-dark-200 rounded-lg text-white focus:border-primary-500 focus:outline-none">
            </div>
            <div>
                <label class="block text-sm text-gray-300 mb-1">Taux (%)</label>
                <input type="number" step="0.01" min="0" max="100" name="insurance_rate" required value="{{ old('insurance_rate') }}"
                       class="w-full px-3 py-2 bg-dark-50 border border-dark-200 rounded-lg text-white focus:border-primary-500 focus:outline-none">
            </div>
            <div>
                <label class="block text-sm text-gray-300 mb-1">Minimum (FCFA)</label>
                <input type="number" step="1" min="0" name="min_insurance_amount" required value="{{ old('min_insurance_amount', 0) }}"
                       class="w-full px-3 py-2 bg-dark-50 border border-dark-200 rounded-lg text-white focus:border-primary-500 focus:outline-none">
            </div>
            <div class="flex gap-2">
                <input type="number" min="0" name="sort_order" value="{{ old('sort_order', 0) }}" title="Ordre"
                       class="w-20 px-3 py-2 bg-dark-50 border border-dark-200 rounded-lg text-white focus:border-primary-500 focus:outline-none">
                <button type="submit" class="flex-1 px-4 py-2 bg-gradient-to-r from-primary-500 to-primary-600 text-white rounded-lg hover:shadow-lg">Ajouter</button>
            </div>
        </form>
    </div>

    <!-- Liste (modifiable en ligne) -->
    <div class="bg-dark-100 rounded-xl shadow-lg border border-dark-200 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-dark-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-white uppercase tracking-wider">Nom</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-white uppercase tracking-wider">Anglais</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-white uppercase tracking-wider">Taux (%)</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-white uppercase tracking-wider">Minimum (FCFA)</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-white uppercase tracking-wider">Ordre</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-white uppercase tracking-wider">Statut</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold text-white uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-dark-200">
                    @forelse($natures as $nature)
                        <tr>
                            <form id="nature-{{ $nature->id }}" action="{{ route('admin.diaspo.parcel-natures.update', $nature) }}" method="POST">
                                @csrf
                                @method('PUT')
                            </form>
                            <td class="px-4 py-3">
                                <input form="nature-{{ $nature->id }}" type="text" name="name" required value="{{ $nature->getTranslation('name', 'fr') }}"
                                       class="w-full px-2 py-1 bg-dark-50 border border-dark-200 rounded text-white">
                            </td>
                            <td class="px-4 py-3">
                                <input form="nature-{{ $nature->id }}" type="text" name="translations[en][name]" value="{{ $nature->getTranslation('name', 'en') }}"
                                       class="w-full px-2 py-1 bg-dark-50 border border-dark-200 rounded text-white">
                            </td>
                            <td class="px-4 py-3">
                                <input form="nature-{{ $nature->id }}" type="number" step="0.01" min="0" max="100" name="insurance_rate" required value="{{ (float) $nature->insurance_rate }}"
                                       class="w-24 px-2 py-1 bg-dark-50 border border-dark-200 rounded text-white">
                            </td>
                            <td class="px-4 py-3">
                                <input form="nature-{{ $nature->id }}" type="number" step="1" min="0" name="min_insurance_amount" required value="{{ (float) $nature->min_insurance_amount }}"
                                       class="w-32 px-2 py-1 bg-dark-50 border border-dark-200 rounded text-white">
                            </td>
                            <td class="px-4 py-3">
                                <input form="nature-{{ $nature->id }}" type="number" min="0" name="sort_order" value="{{ $nature->sort_order }}"
                                       class="w-16 px-2 py-1 bg-dark-50 border border-dark-200 rounded text-white">
                            </td>
                            <td class="px-4 py-3">
                                <form action="{{ route('admin.diaspo.parcel-natures.toggle-status', $nature) }}" method="POST" class="inline">
                                    @csrf
                                    @method('PATCH')
                                    <button type="submit" class="px-3 py-1 text-sm font-semibold rounded-full {{ $nature->is_active ? 'bg-green-500/20 text-green-300 border border-green-500/50' : 'bg-gray-500/20 text-gray-400 border border-gray-500/50' }}">
                                        {{ $nature->is_active ? 'Active' : 'Inactive' }}
                                    </button>
                                </form>
                            </td>
                            <td class="px-4 py-3 text-right whitespace-nowrap">
                                <button form="nature-{{ $nature->id }}" type="submit" class="px-3 py-1.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700">Enregistrer</button>
                                <form action="{{ route('admin.diaspo.parcel-natures.destroy', $nature) }}" method="POST" class="inline" onsubmit="return confirm('Supprimer cette nature ?')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="px-3 py-1.5 bg-red-600 text-white rounded-lg hover:bg-red-700"><i class="fas fa-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-4 py-8 text-center text-gray-400">Aucune nature de colis.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
