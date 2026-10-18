@extends('admin.layouts.app')

@section('content')
<div class="p-6">
    <div class="mb-6 flex items-center gap-3">
        <a href="{{ route('admin.delivery-partners.index') }}" class="text-gray-400 hover:text-primary-500"><i class="fas fa-arrow-left"></i></a>
        <div>
            <h1 class="text-2xl font-bold text-white">{{ $partner->name }}</h1>
            <p class="text-gray-400">Conditions et grilles affichées à l'acheteur avant la commande.</p>
        </div>
    </div>

    <!-- Services proposés (même résumé que « Livreurs ») -->
    <div class="mb-6">
        @include('admin.delivery_partners._services_summary', ['partner' => $partner, 'compact' => true])
    </div>

    <!-- Paiements : Wallet du compte interne du partenaire, versé comme le retrait ASSO -->
    <div class="bg-dark-100 rounded-xl shadow-lg border border-dark-200 p-6 mb-6 flex flex-wrap items-center justify-between gap-4">
        <div>
            <h2 class="text-lg font-semibold text-white mb-1"><i class="fas fa-wallet mr-2 text-primary-400"></i>Paiements du partenaire</h2>
            <p class="text-sm text-gray-400">Ses courses sont créditées sur son Wallet à chaque règlement. Solde à verser : <span class="text-white font-semibold">{{ number_format($walletBalance, 0, ',', ' ') }} FCFA</span></p>
        </div>
        @if(auth()->user()->isAdmin())
            <a href="{{ route('admin.partner-payouts.show', $partner) }}" class="px-4 py-2 bg-gradient-to-r from-primary-500 to-primary-600 text-white rounded-lg hover:shadow-lg">
                <i class="fas fa-paper-plane mr-1"></i>Payer (Mobile Money / IBAN)
            </a>
        @endif
    </div>

    <!-- Coursiers : l'app ASSO reçoit les livraisons à domicile du partenaire -->
    @php
        $activeSyncs = $partner->activeCodeSyncs()->with('user')->get();
        $latestCode = $partner->syncCodes()->latest()->first();
    @endphp
    <div class="bg-dark-100 rounded-xl shadow-lg border border-dark-200 p-6 mb-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h2 class="text-lg font-semibold text-white mb-1"><i class="fas fa-motorcycle mr-2 text-primary-400"></i>Coursiers dans l'app ASSO</h2>
                <p class="text-sm text-gray-400">Ils reçoivent les livraisons à domicile de {{ $partner->name }} et les clôturent avec le code à 6 chiffres de l'acheteur.</p>
                <div class="mt-3 text-sm">
                    @forelse($activeSyncs as $sync)
                        <span class="inline-block mr-2 mb-1 px-2 py-1 rounded bg-green-900/30 text-green-300 border border-green-500/40">
                            <i class="fas fa-user-check mr-1"></i>{{ $sync->user?->name }} {{ $sync->user?->phone ? '· ' . $sync->user->phone : '' }}
                        </span>
                    @empty
                        <span class="text-yellow-400"><i class="fas fa-exclamation-triangle mr-1"></i>Aucun coursier synchronisé : les commandes à domicile ne seront prises en charge par personne.</span>
                    @endforelse
                </div>
                @if($latestCode && !$latestCode->is_used && !$latestCode->isExpired())
                    <p class="mt-2 text-sm text-gray-400">Code en cours : <code class="text-primary-400 font-bold">{{ $latestCode->sync_code }}</code> (jusqu'au {{ $latestCode->expires_at->displayTz()->format('d/m/Y') }})</p>
                @endif
            </div>
            <div class="flex flex-col gap-2">
                <form action="{{ route('admin.deliverers.sync-code', $partner) }}" method="POST">
                    @csrf
                    <button type="submit" class="px-4 py-2 bg-primary-500 text-white rounded-lg hover:bg-primary-600 text-sm w-full">
                        <i class="fas fa-key mr-1"></i> Générer un code de synchronisation
                    </button>
                </form>
                <a href="{{ route('admin.deliverers.show', $partner) }}" class="px-4 py-2 bg-dark-50 border border-dark-300 text-gray-300 rounded-lg hover:text-white text-sm text-center">
                    <i class="fas fa-id-card mr-1"></i> Fiche livreur et historique
                </a>
            </div>
        </div>
    </div>

    @if($errors->any())
        <div class="mb-6 p-4 bg-red-900/20 border-l-4 border-red-500 rounded">
            <ul class="text-red-300 text-sm list-disc list-inside">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <!-- Partenaire -->
    <div class="bg-dark-100 rounded-xl shadow-lg border border-dark-200 p-6 mb-6">
        <h2 class="text-lg font-semibold text-white mb-4"><i class="fas fa-building mr-2 text-primary-400"></i>Partenaire</h2>
        <form action="{{ route('admin.delivery-partners.update', $partner) }}" method="POST">
            @csrf
            @method('PUT')
            @include('admin.delivery_partners._partner_fields', ['partner' => $partner])
            <div class="mt-4 flex items-center justify-between">
                <label class="flex items-center gap-2 text-sm text-gray-300">
                    <input type="checkbox" name="is_active" value="1" @checked($partner->is_active) class="w-4 h-4 rounded bg-dark-50">
                    Partenaire actif (proposé aux acheteurs)
                </label>
                <button type="submit" class="px-4 py-2 bg-primary-500 text-white rounded-lg hover:bg-primary-600">
                    <i class="fas fa-save mr-2"></i> Enregistrer
                </button>
            </div>
        </form>
    </div>

    <!-- Grilles urbaines zone à zone -->
    @foreach($partner->cityGrids as $grid)
        <div class="bg-dark-100 rounded-xl shadow-lg border border-dark-200 p-6 mb-6">
            <div class="flex items-start justify-between gap-3">
                <h2 class="text-lg font-semibold text-white mb-1"><i class="fas fa-city mr-2 text-primary-400"></i>Livraison urbaine à {{ $grid->city }} (zone à zone)@unless($grid->is_active) <span class="ml-2 px-2 py-0.5 rounded text-xs bg-gray-800 text-gray-400 align-middle">Inactive</span>@endunless</h2>
                <form action="{{ route('admin.delivery-partners.city-grids.destroy', [$partner, $grid]) }}" method="POST"
                      data-confirm="Supprimer la livraison urbaine à {{ $grid->city }} ? Ses zones, quartiers, véhicules et prix seront effacés.">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="px-3 py-1.5 text-sm text-red-400 border border-red-500/40 rounded-lg hover:bg-red-900/30 whitespace-nowrap"><i class="fas fa-trash mr-1"></i> Supprimer cette ville</button>
                </form>
            </div>
            <p class="text-sm text-gray-400 mb-4">Une offre par véhicule capable de porter le colis, avec son délai estimé. Livrée à domicile par un coursier {{ $partner->name }} synchronisé.</p>
            @include('admin.delivery_partners._city_grid', ['grid' => $grid])
        </div>
    @endforeach

    <!-- Nouvelle ville en zone à zone -->
    <div class="bg-dark-100 rounded-xl shadow-lg border border-dashed border-dark-300 p-6 mb-6">
        <h2 class="text-lg font-semibold text-white mb-1"><i class="fas fa-plus-circle mr-2 text-primary-400"></i>Couvrir une nouvelle ville (zones et quartiers)</h2>
        <p class="text-sm text-gray-400 mb-4">Même modèle que les autres villes : zones, quartiers placés sur la carte, véhicules et prix zone à zone. Choisissez une ville existante comme modèle pour reprendre ses véhicules, délais et prix (à ajuster), puis tapez les quartiers.</p>
        @php
            // Villes desservies par les trajets mais sans grille urbaine : à couvrir en priorité.
            $routeCities = $partner->deliveryRoutes
                ->flatMap(fn ($r) => [$r->origin_city, $r->destination_city])
                ->filter()
                ->unique(fn ($c) => mb_strtolower($c))
                ->reject(fn ($c) => $partner->cityGrids->contains(fn ($g) => $g->coversCity($c)))
                ->sort()
                ->values();
            $firstGrid = $partner->cityGrids->first();
        @endphp
        <form action="{{ route('admin.delivery-partners.city-grids.store', $partner) }}" method="POST" x-data="{ city: @js(old('city', '')) }">
            @csrf
            <div class="grid grid-cols-1 md:grid-cols-4 gap-3 items-end">
                <div>
                    <label class="block text-xs text-gray-400 mb-1">Ville</label>
                    <input type="text" name="city" x-model="city" required placeholder="Yaoundé" list="route_cities_{{ $partner->id }}" class="w-full px-3 py-2 bg-dark-50 border border-dark-200 rounded-lg text-white text-sm">
                    <datalist id="route_cities_{{ $partner->id }}">
                        @foreach($routeCities as $c)<option value="{{ $c }}">@endforeach
                    </datalist>
                </div>
                <div>
                    <label class="block text-xs text-gray-400 mb-1">Nombre de zones</label>
                    <input type="number" name="zones_count" min="1" max="30" value="{{ old('zones_count', $firstGrid ? count($firstGrid->zones) : 7) }}" required class="w-full px-3 py-2 bg-dark-50 border border-dark-200 rounded-lg text-white text-sm">
                </div>
                <div>
                    <label class="block text-xs text-gray-400 mb-1">Modèle (véhicules, délais et prix)</label>
                    <select name="template_grid_id" class="w-full px-3 py-2 bg-dark-50 border border-dark-200 rounded-lg text-white text-sm">
                        @foreach($partner->cityGrids as $g)
                            <option value="{{ $g->id }}" @selected($loop->first)>Comme {{ $g->city }}</option>
                        @endforeach
                        <option value="">Vierge (4 véhicules, sans prix)</option>
                    </select>
                </div>
                <div>
                    <button type="submit" class="w-full px-4 py-2 bg-primary-500 text-white rounded-lg hover:bg-primary-600 text-sm"><i class="fas fa-city mr-1"></i> Créer la grille</button>
                </div>
            </div>
            @if($routeCities->isNotEmpty())
                <div class="mt-3 text-xs text-gray-400">
                    Villes de vos trajets sans livraison urbaine :
                    @foreach($routeCities as $c)
                        <button type="button" @click="city = @js($c)" class="ml-1 mb-1 px-2 py-0.5 rounded-full border border-purple-500/50 bg-purple-900/30 text-purple-300 hover:bg-purple-800/40">{{ $c }}</button>
                    @endforeach
                </div>
            @endif
        </form>
    </div>

    <!-- Trajets -->
    <div class="bg-dark-100 rounded-xl shadow-lg border border-dark-200 p-6 mb-6">
        <h2 class="text-lg font-semibold text-white mb-1"><i class="fas fa-route mr-2 text-primary-400"></i>Trajets interurbains et internationaux</h2>
        <p class="text-sm text-gray-400 mb-4">
            Poids du colis = somme des poids des fiches produit × quantités. Au-delà de la dernière tranche, chaque kg entamé est facturé au prix du kg supplémentaire.
            @if($partner->prices_exclude_vat)
                Grille hors taxe : l'acheteur paie en plus {{ rtrim(rtrim(number_format($vatRate, 2, ',', ''), '0'), ',') }} % de TVA.
            @endif
            @if($partner->service_mode === 'agency_to_agency')
                L'acheteur choisit le retrait en agence, ou la livraison à domicile depuis l'agence d'arrivée (prix de la grille urbaine ajouté).
            @endif
        </p>

        @forelse($partner->deliveryRoutes as $route)
            <div x-data="{ open: false }" class="border border-dark-200 rounded-lg mb-3">
                <div class="flex items-center justify-between px-4 py-3">
                    <div class="text-sm">
                        <span class="text-white font-medium">{{ $route->label() }}</span>
                        <span class="text-gray-400 ml-2">{{ $route->lead_time }}</span>
                        @unless($route->is_active)<span class="ml-2 px-2 py-0.5 rounded text-xs bg-gray-800 text-gray-400">Inactif</span>@endunless
                        <div class="text-gray-400 mt-1">
                            @foreach(\App\Support\WeightGrid::describe($route->pricing_data ?? []) as $range)
                                {{ $range['label'] }} : <span class="text-gray-200">{{ number_format($range['price'], 0, ',', ' ') }} F</span> ·
                            @endforeach
                            @if(($route->pricing_data['extra_per_kg'] ?? 0) > 0)
                                kg suppl. : <span class="text-gray-200">{{ number_format($route->pricing_data['extra_per_kg'], 0, ',', ' ') }} F</span>
                            @endif
                        </div>
                    </div>
                    <div class="flex items-center gap-3 whitespace-nowrap">
                        <button type="button" @click="open = !open" class="text-primary-400 hover:text-primary-300 text-sm"><i class="fas fa-edit mr-1"></i> Modifier</button>
                        <form action="{{ route('admin.delivery-partners.routes.destroy', [$partner, $route]) }}" method="POST"
                              data-confirm="Supprimer le trajet {{ $route->label() }} ?">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="text-red-400 hover:text-red-300 text-sm"><i class="fas fa-trash"></i></button>
                        </form>
                    </div>
                </div>
                <div x-show="open" x-cloak class="px-4 pb-4 border-t border-dark-200 pt-4">
                    @include('admin.delivery_partners._route_form', [
                        'route' => $route,
                        'action' => route('admin.delivery-partners.routes.update', [$partner, $route]),
                        'method' => 'PUT',
                    ])
                </div>
            </div>
        @empty
            <p class="text-gray-500 text-sm mb-4">Aucun trajet : ce partenaire n'est proposé que pour ses zones urbaines.</p>
        @endforelse

        <div class="mt-6 p-4 bg-dark-50 rounded-lg border border-dashed border-dark-300">
            <h3 class="text-white font-medium mb-3"><i class="fas fa-plus-circle mr-1 text-primary-400"></i> Ajouter un trajet</h3>
            @include('admin.delivery_partners._route_form', [
                'route' => null,
                'action' => route('admin.delivery-partners.routes.store', $partner),
            ])
        </div>
    </div>
</div>
@endsection
