@extends('admin.layouts.app')

@section('content')
@php($in = 'w-full px-3 py-2 bg-dark-50 border border-dark-200 rounded-lg text-white focus:border-primary-500 focus:outline-none')
<div class="p-6" x-data="{ bulk: false }">
    <div class="mb-6 flex items-center justify-between flex-wrap gap-3">
        <div>
            <h1 class="text-2xl font-bold text-white">Annonces immobilières</h1>
            <p class="text-gray-400">Maisons et terrains gérés par les cabinets partenaires, ainsi que les annonces publiées par les bailleurs.</p>
        </div>
        <div class="flex gap-2">
            <button type="button" @click="bulk = !bulk" class="px-4 py-2 bg-dark-200 text-white rounded-lg hover:bg-dark-50">
                <i class="fas fa-layer-group mr-2"></i> Saisie rapide de terrains
            </button>
            <a href="{{ route('admin.real-estate.listings.create', ['type' => 'rent']) }}" class="px-4 py-2 bg-gradient-to-r from-primary-500 to-primary-600 text-white rounded-lg hover:shadow-lg">
                <i class="fas fa-plus mr-2"></i> Nouvelle maison
            </a>
            <a href="{{ route('admin.real-estate.listings.create', ['type' => 'land']) }}" class="px-4 py-2 bg-dark-200 text-white rounded-lg hover:bg-dark-50">
                <i class="fas fa-plus mr-2"></i> Nouveau terrain
            </a>
        </div>
    </div>

    @include('admin.real_estate._alerts')

    <!-- Saisie rapide : une ligne par localité (affiche du cabinet) -->
    <div x-show="bulk" x-cloak class="bg-dark-100 rounded-xl border border-primary-500/40 p-6 mb-6"
         x-data="{ rows: [{},{},{},{}] }">
        <h2 class="text-lg font-semibold text-white mb-1"><i class="fas fa-layer-group mr-2 text-primary-400"></i>Ajouter les terrains d'un cabinet</h2>
        <p class="text-sm text-gray-400 mb-4">Une ligne par localité, comme sur l'affiche du cabinet. Surfaces séparées par des virgules (ex. 300, 400, 800). Les terrains sont créés en brouillon : ajoutez les photos puis publiez-les.</p>
        <form action="{{ route('admin.real-estate.listings.bulk-lands') }}" method="POST">
            @csrf
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                <div>
                    <label class="block text-sm text-gray-400 mb-1">Cabinet</label>
                    <select name="cabinet_id" required class="{{ $in }}">
                        @foreach($cabinets as $cab)
                            <option value="{{ $cab->id }}">{{ $cab->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-sm text-gray-400 mb-1">Ville</label>
                    <input name="city" required value="Yaoundé" class="{{ $in }}">
                </div>
                <div>
                    <label class="block text-sm text-gray-400 mb-1">Acompte (%)</label>
                    <input name="deposit_percent" type="number" min="1" max="100" step="0.5" placeholder="Réglage par défaut" class="{{ $in }}">
                </div>
            </div>
            <table class="min-w-full text-sm">
                <thead><tr class="text-gray-400 text-left">
                    <th class="py-2 pr-2">Localité / quartier</th><th class="py-2 pr-2">Surfaces (m²)</th><th class="py-2 pr-2">Prix de référence (FCFA)</th><th class="py-2 pr-2">Prix</th><th class="py-2">Titré</th>
                </tr></thead>
                <tbody>
                <template x-for="(row, i) in rows" :key="i">
                    <tr>
                        <td class="py-1 pr-2"><input :name="`rows[${i}][district]`" placeholder="Nkoufoulou" class="{{ $in }}"></td>
                        <td class="py-1 pr-2"><input :name="`rows[${i}][surfaces]`" placeholder="300, 400, 800, 1200" class="{{ $in }}"></td>
                        <td class="py-1 pr-2"><input :name="`rows[${i}][price]`" type="number" min="0" placeholder="10000" class="{{ $in }}"></td>
                        <td class="py-1 pr-2"><select :name="`rows[${i}][price_mode]`" class="{{ $in }}"><option value="per_m2">par m²</option><option value="per_lot">par lot</option></select></td>
                        <td class="py-1 text-center"><input type="checkbox" value="1" :name="`rows[${i}][titled]`" checked class="rounded"></td>
                    </tr>
                </template>
                </tbody>
            </table>
            <div class="mt-4 flex justify-between">
                <button type="button" @click="rows.push({})" class="px-3 py-2 text-sm bg-dark-200 text-white rounded-lg"><i class="fas fa-plus mr-1"></i> Ligne</button>
                <button type="submit" class="px-4 py-2 bg-primary-500 text-white rounded-lg hover:bg-primary-600"><i class="fas fa-save mr-2"></i> Créer les terrains</button>
            </div>
        </form>
    </div>

    <!-- Filtres -->
    <form method="GET" class="bg-dark-100 rounded-xl border border-dark-200 p-4 mb-6 grid grid-cols-1 md:grid-cols-4 gap-3">
        <input name="q" value="{{ request('q') }}" placeholder="Titre, référence, ville, quartier" class="{{ $in }}">
        <select name="type" class="{{ $in }}">
            <option value="">Maisons et terrains</option>
            <option value="rent" @selected(request('type') === 'rent')>Maisons à louer</option>
            <option value="land" @selected(request('type') === 'land')>Terrains à vendre</option>
        </select>
        <select name="status" class="{{ $in }}">
            <option value="">Tous les statuts</option>
            @foreach(['pending_review' => 'À relire', 'published' => 'Publiées', 'draft' => 'Brouillons', 'rejected' => 'Refusées', 'archived' => 'Archivées'] as $key => $label)
                <option value="{{ $key }}" @selected(request('status') === $key)>{{ $label }} ({{ $counts[$key] ?? 0 }})</option>
            @endforeach
        </select>
        <select name="cabinet_id" class="{{ $in }}">
            <option value="">Tous les cabinets</option>
            @foreach($cabinets as $cab)
                <option value="{{ $cab->id }}" @selected(request('cabinet_id') == $cab->id)>{{ $cab->name }}</option>
            @endforeach
        </select>
        <button class="px-4 py-2 bg-primary-500 text-white rounded-lg hover:bg-primary-600"><i class="fas fa-search mr-2"></i>Filtrer</button>
    </form>

    <div class="bg-dark-100 rounded-xl border border-dark-200 overflow-x-auto">
        <table class="min-w-full divide-y divide-dark-200">
            <thead class="bg-dark-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-white uppercase">Annonce</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-white uppercase">Bailleur / cabinet</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-white uppercase">Prix de référence → affiché</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-white uppercase">Statut</th>
                    <th class="px-4 py-3 text-right text-xs font-semibold text-white uppercase">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-dark-200">
            @forelse($listings as $l)
                <tr class="hover:bg-dark-50">
                    <td class="px-4 py-3">
                        <div class="flex items-center gap-3">
                            @if($cover = $l->coverUrl())
                                <img src="{{ $cover }}" class="w-14 h-14 rounded-lg object-cover" alt="">
                            @else
                                <div class="w-14 h-14 rounded-lg bg-dark-50 flex items-center justify-center text-gray-500"><i class="fas {{ $l->isLand() ? 'fa-mountain' : 'fa-home' }}"></i></div>
                            @endif
                            <div>
                                <p class="text-white font-medium">{{ $l->title }} @if($l->is_featured)<i class="fas fa-star text-primary-400 ml-1" title="À la une"></i>@endif</p>
                                <p class="text-xs text-gray-400">{{ $l->reference }} · {{ $l->isLand() ? 'Terrain' : 'Location' }} · {{ $l->city }}{{ $l->district ? ', '.$l->district : '' }}</p>
                            </div>
                        </div>
                    </td>
                    <td class="px-4 py-3 text-sm text-gray-300">
                        @if($l->cabinet)
                            <i class="fas fa-landmark mr-1 text-gray-500"></i>{{ $l->cabinet->name }}
                        @else
                            <i class="fas fa-user mr-1 text-gray-500"></i>{{ $l->owner?->name ?? '—' }}
                            @if($l->owner) <div class="mt-1">@include('admin.real_estate._status', ['status' => $l->owner->landlord_verification_status ?: 'none'])</div> @endif
                        @endif
                    </td>
                    <td class="px-4 py-3 text-sm text-gray-300">
                        {{ number_format($l->minNetPrice(), 0, ',', ' ') }} → <span class="text-white font-semibold">{{ number_format($pricing->displayed($l->minNetPrice(), $l->type), 0, ',', ' ') }}</span> FCFA
                        @if(! $l->isLand())<span class="text-gray-500">/ mois</span>@endif
                    </td>
                    <td class="px-4 py-3">@include('admin.real_estate._status', ['status' => $l->status])</td>
                    <td class="px-4 py-3 text-right">
                        <div class="flex items-center justify-end gap-2">
                            <a href="{{ route('admin.real-estate.listings.edit', $l) }}" class="px-3 py-1.5 text-sm bg-primary-500/20 text-primary-300 rounded-lg hover:bg-primary-500/30">
                                <i class="fas {{ $l->status === 'pending_review' ? 'fa-search' : 'fa-edit' }} mr-1"></i>{{ $l->status === 'pending_review' ? 'Relire' : 'Ouvrir' }}
                            </a>
                            @include('admin.real_estate.listings._delete', ['listing' => $l])
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="px-4 py-10 text-center text-gray-400">Aucune annonce.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $listings->links() }}</div>
</div>
@endsection
