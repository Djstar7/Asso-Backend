    <!-- Grilles urbaines zone à zone -->
    @php
        $legacyGrids = $partner->cityGrids->whereNull('department_code');
        $selectedLegacyGrid = $legacyGrids->firstWhere('id', request('city_grid')) ?? $legacyGrids->first();
    @endphp
    <p class="text-sm text-gray-400 mb-4">Grilles historiques par quartier. Le nouveau réseau par arrondissement se configure dans « Régions et arrondissements ».</p>
    <div class="flex flex-wrap gap-2 mb-4">
        @foreach($legacyGrids as $legacyGrid)
            <a href="{{ route('admin.delivery-partners.edit', [$partner, 'section' => 'cities', 'city_grid' => $legacyGrid->id]) }}" class="px-3 py-2 rounded-lg border text-sm {{ $selectedLegacyGrid?->id === $legacyGrid->id ? 'border-primary-500 text-primary-400' : 'border-dark-300 text-gray-300' }}">{{ $legacyGrid->city }}</a>
        @endforeach
    </div>
    @foreach($legacyGrids->where('id', $selectedLegacyGrid?->id) as $grid)
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

