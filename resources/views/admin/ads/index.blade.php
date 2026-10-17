@extends('admin.layouts.app')

@section('title', 'Asso Ads')
@section('header', 'Asso Ads')

@section('content')
@php
    $fcfa = fn ($v) => number_format((float) $v, 0, ',', ' ') . ' FCFA';
    $num = fn ($v) => number_format((int) $v, 0, ',', ' ');
    $delivery = ($totals->quota ?? 0) > 0
        ? round(($totals->impressions ?? 0) / $totals->quota * 100, 1)
        : 0;
    $ctr = ($totals->impressions ?? 0) > 0
        ? round(($totals->clicks ?? 0) / $totals->impressions * 100, 2)
        : 0;
@endphp
<div class="p-6 space-y-6">
    <!-- En-tête -->
    <div class="flex flex-col lg:flex-row lg:items-end lg:justify-between gap-4">
        <div>
            <h1 class="text-3xl font-bold text-gray-100">Asso Ads</h1>
            <p class="text-gray-400 mt-1">
                Produits sponsorisés par les vendeurs : ce qui est vendu, ce qui est diffusé.
            </p>
        </div>
        <a href="{{ route('admin.packages.index', ['type' => 'boost']) }}"
           class="px-4 py-2 bg-dark-100 border border-dark-200 hover:bg-dark-50 text-gray-100 rounded-lg transition-colors text-sm">
            <i class="fas fa-tags mr-2"></i>
            Grille tarifaire ({{ $packagesCount }} forfait{{ $packagesCount > 1 ? 's' : '' }})
        </a>
    </div>

    @if(!$settings['enabled'])
        <div class="bg-amber-500/10 border border-amber-500/40 text-amber-300 rounded-xl p-4 flex items-start gap-3">
            <i class="fas fa-pause-circle mt-0.5"></i>
            <div>
                <p class="font-semibold">Diffusion suspendue</p>
                <p class="text-sm text-amber-300/80">
                    Aucun produit sponsorisé n'est servi actuellement. Les campagnes en cours ne consomment
                    pas leur quota et reprendront à la réactivation.
                </p>
            </div>
        </div>
    @endif

    <!-- Chiffres clés -->
    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4">
        <div class="bg-dark-100 border border-dark-200 rounded-xl p-5">
            <p class="text-gray-400 text-sm">Revenus sponsoring</p>
            <p class="text-2xl font-bold text-white mt-1">{{ $fcfa($totals->revenue ?? 0) }}</p>
            <p class="text-gray-500 text-xs mt-1">{{ $num($totals->campaigns ?? 0) }} campagne(s) vendue(s)</p>
        </div>
        <div class="bg-dark-100 border border-dark-200 rounded-xl p-5">
            <p class="text-gray-400 text-sm">En diffusion</p>
            <p class="text-2xl font-bold text-white mt-1">{{ $num($totals->running ?? 0) }}</p>
            <p class="text-gray-500 text-xs mt-1">campagnes actives</p>
        </div>
        <div class="bg-dark-100 border border-dark-200 rounded-xl p-5">
            <p class="text-gray-400 text-sm">Impressions délivrées</p>
            <p class="text-2xl font-bold text-white mt-1">{{ $num($totals->impressions ?? 0) }}</p>
            <p class="text-gray-500 text-xs mt-1">
                sur {{ $num($totals->quota ?? 0) }} achetées — {{ $delivery }} %
            </p>
        </div>
        <div class="bg-dark-100 border border-dark-200 rounded-xl p-5">
            <p class="text-gray-400 text-sm">Taux de clic</p>
            <p class="text-2xl font-bold text-white mt-1">{{ $ctr }} %</p>
            <p class="text-gray-500 text-xs mt-1">{{ $num($totals->clicks ?? 0) }} ouverture(s) de fiche</p>
        </div>
    </div>

    <!-- Réglages de diffusion -->
    <div class="bg-dark-100 border border-dark-200 rounded-xl">
        <div class="p-6 border-b border-dark-200">
            <h3 class="text-lg font-semibold text-white">Diffusion</h3>
            <p class="text-gray-400 text-sm mt-1">
                Les prix, durées et quotas d'audience se règlent dans
                <a href="{{ route('admin.packages.index', ['type' => 'boost']) }}" class="text-primary-400 hover:underline">Packages → Boost Sponsoring</a>.
                Ici, c'est la façon dont les annonces apparaissent dans l'application.
            </p>
        </div>

        <form method="POST" action="{{ route('admin.ads.settings') }}" class="p-6 grid grid-cols-1 md:grid-cols-3 gap-6">
            @csrf

            <div class="md:col-span-3 flex items-center gap-3">
                <input type="checkbox" id="enabled" name="enabled" value="1" @checked($settings['enabled'])
                       class="w-4 h-4 text-primary-600 bg-dark-50 border-dark-200 rounded focus:ring-primary-500">
                <label for="enabled" class="text-sm font-medium text-gray-300">
                    Diffuser les produits sponsorisés
                    <span class="block text-xs text-gray-500">
                        Décocher suspend l'affichage sans annuler les campagnes payées ni consommer leur quota.
                    </span>
                </label>
            </div>

            <div>
                <label for="slots_per_page" class="block text-sm font-medium text-gray-300 mb-2">
                    Emplacements par page
                </label>
                <input type="number" id="slots_per_page" name="slots_per_page" min="0" max="10"
                       value="{{ old('slots_per_page', $settings['slots_per_page']) }}"
                       class="w-full px-4 py-2 bg-dark-50 border border-dark-200 rounded-lg text-gray-100 focus:ring-2 focus:ring-primary-500">
                <p class="text-xs text-gray-500 mt-1">Nombre d'annonces insérées dans une page de résultats. 0 = aucune.</p>
                @error('slots_per_page')<p class="text-red-500 text-sm mt-1">{{ $message }}</p>@enderror
            </div>

            <div class="md:col-span-2">
                <label for="slot_positions" class="block text-sm font-medium text-gray-300 mb-2">
                    Positions dans la page
                </label>
                <input type="text" id="slot_positions" name="slot_positions"
                       value="{{ old('slot_positions', $settings['slot_positions']) }}"
                       placeholder="0,5"
                       class="w-full px-4 py-2 bg-dark-50 border border-dark-200 rounded-lg text-gray-100 focus:ring-2 focus:ring-primary-500">
                <p class="text-xs text-gray-500 mt-1">
                    Rangs séparés par des virgules, en partant de 0 : « 0,5 » place une annonce en tête
                    et une autre après le cinquième produit.
                </p>
                @error('slot_positions')<p class="text-red-500 text-sm mt-1">{{ $message }}</p>@enderror
            </div>

            <div class="md:col-span-3 flex justify-end pt-2 border-t border-dark-200">
                <button type="submit"
                        class="px-6 py-2 bg-gradient-to-r from-primary-500 to-primary-600 text-white rounded-lg hover:shadow-lg transition-all">
                    <i class="fas fa-save mr-2"></i>
                    Enregistrer
                </button>
            </div>
        </form>
    </div>

    <!-- Campagnes -->
    <div class="bg-dark-100 border border-dark-200 rounded-xl">
        <div class="p-6 border-b border-dark-200 flex flex-col md:flex-row md:items-center md:justify-between gap-4">
            <h3 class="text-lg font-semibold text-white">Campagnes</h3>
            <form method="GET" action="{{ route('admin.ads.index') }}" class="flex flex-wrap gap-2">
                <input type="text" name="search" value="{{ $search }}" placeholder="Rechercher un produit"
                       class="px-3 py-2 bg-dark-50 border border-dark-200 rounded-lg text-gray-100 text-sm">
                <select name="status" class="px-3 py-2 bg-dark-50 border border-dark-200 rounded-lg text-gray-100 text-sm">
                    <option value="">Tous les statuts</option>
                    @foreach($statuses as $key => $label)
                        <option value="{{ $key }}" @selected($status === $key)>{{ $label }}</option>
                    @endforeach
                </select>
                <button class="px-4 py-2 bg-primary-600 hover:bg-primary-700 text-white rounded-lg text-sm">Filtrer</button>
            </form>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-dark-50 text-gray-400 uppercase text-xs">
                    <tr>
                        <th class="px-4 py-3 text-left">Produit</th>
                        <th class="px-4 py-3 text-left">Vendeur</th>
                        <th class="px-4 py-3 text-left">Forfait</th>
                        <th class="px-4 py-3 text-right">Portée</th>
                        <th class="px-4 py-3 text-right">Clics</th>
                        <th class="px-4 py-3 text-right">Montant</th>
                        <th class="px-4 py-3 text-left">Échéance</th>
                        <th class="px-4 py-3 text-left">Statut</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-dark-200">
                    @forelse($campaigns as $campaign)
                        @php
                            $progress = $campaign->progressPercent();
                        @endphp
                        <tr class="hover:bg-dark-50/50">
                            <td class="px-4 py-3">
                                <p class="text-gray-100 font-medium">{{ $campaign->product->name ?? 'Produit supprimé' }}</p>
                                <p class="text-gray-500 text-xs">{{ $campaign->shop->name ?? '—' }}</p>
                            </td>
                            <td class="px-4 py-3 text-gray-300">{{ $campaign->user->name ?? '—' }}</td>
                            <td class="px-4 py-3 text-gray-300">{{ $campaign->package->name ?? '—' }}</td>
                            <td class="px-4 py-3 text-right">
                                <p class="text-gray-100">
                                    {{ $num($campaign->impressions_served) }} / {{ $num($campaign->impressions_quota) }}
                                </p>
                                <div class="h-1.5 bg-dark-200 rounded-full mt-1 w-28 ml-auto overflow-hidden">
                                    <div class="h-full bg-primary-500 rounded-full" style="width: {{ $progress }}%"></div>
                                </div>
                            </td>
                            <td class="px-4 py-3 text-right text-gray-300">
                                {{ $num($campaign->clicks) }}
                                <span class="block text-xs text-gray-500">{{ $campaign->clickThroughRate() }} %</span>
                            </td>
                            <td class="px-4 py-3 text-right text-gray-100">{{ $fcfa($campaign->amount_xaf) }}</td>
                            <td class="px-4 py-3 text-gray-300">
                                {{ $campaign->ends_at->displayTz()->format('d/m/Y') }}
                                @if($campaign->status === \App\Models\ProductBoost::ACTIVE)
                                    <span class="block text-xs text-gray-500">
                                        {{ $campaign->remainingDays() }} j restant(s)
                                    </span>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                @php
                                    $badge = match($campaign->status) {
                                        \App\Models\ProductBoost::ACTIVE => 'bg-green-500/15 text-green-400',
                                        \App\Models\ProductBoost::COMPLETED => 'bg-blue-500/15 text-blue-400',
                                        \App\Models\ProductBoost::EXPIRED => 'bg-gray-500/15 text-gray-400',
                                        default => 'bg-red-500/15 text-red-400',
                                    };
                                @endphp
                                <span class="px-2 py-1 rounded-md text-xs {{ $badge }}">
                                    {{ $campaign->statusLabel() }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-right whitespace-nowrap">
                                <a href="{{ route('admin.ads.show', $campaign) }}"
                                   class="text-primary-400 hover:text-primary-300 mr-3">
                                    <i class="fas fa-eye"></i>
                                </a>
                                @if($campaign->status === \App\Models\ProductBoost::ACTIVE)
                                    <form method="POST" action="{{ route('admin.ads.cancel', $campaign) }}" class="inline">
                                        @csrf
                                        <button type="submit" class="text-red-400 hover:text-red-300" title="Arrêter la campagne">
                                            <i class="fas fa-stop-circle"></i>
                                        </button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="px-4 py-12 text-center text-gray-500">
                                Aucune campagne pour le moment.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($campaigns->hasPages())
            <div class="p-4 border-t border-dark-200">
                {{ $campaigns->links() }}
            </div>
        @endif
    </div>
</div>
@endsection
