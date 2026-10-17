@extends('admin.layouts.app')

@section('content')
<div class="p-6">
    <!-- Header -->
    <div class="mb-6">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-3">
                <a href="{{ route('admin.deliverers.index') }}" class="text-gray-400 hover:text-primary-500 transition-colors">
                    <i class="fas fa-arrow-left"></i>
                </a>
                <div>
                    <h1 class="text-2xl font-bold text-white">{{ $deliverer->name }}</h1>
                    <p class="text-gray-400">Entreprise de livraison</p>
                </div>
            </div>
            <div class="flex gap-3">
                <a href="{{ route('admin.deliverers.syncs.company', $deliverer) }}"
                   class="px-4 py-2 bg-gradient-to-r from-blue-500 to-blue-600 text-white rounded-lg hover:shadow-lg transition-all">
                    <i class="fas fa-users mr-2"></i> Synchronisations
                    @if($deliverer->codeSyncs->count() > 0)
                        <span class="ml-2 px-2 py-0.5 bg-white/20 rounded-full text-xs">
                            {{ $deliverer->codeSyncs->count() }}
                        </span>
                    @endif
                </a>
                <a href="{{ route('admin.deliverers.edit', $deliverer) }}"
                   class="px-4 py-2 bg-gradient-to-r from-primary-500 to-primary-600 text-white rounded-lg hover:shadow-lg transition-all">
                    <i class="fas fa-edit mr-2"></i> Modifier
                </a>
                <form action="{{ route('admin.deliverers.destroy', $deliverer) }}" method="POST"
                      data-confirm="Supprimer cette entreprise de livraison ? Toutes les zones et tarifs associés seront également supprimés.">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-all">
                        <i class="fas fa-trash mr-2"></i> Supprimer
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Left: Company Card -->
        <div class="lg:col-span-1 space-y-6">
            <!-- Company Card -->
            <div class="bg-dark-100 rounded-xl shadow-lg border border-dark-200 p-6">
                <div class="flex flex-col items-center mb-6">
                    @if($deliverer->logo)
                        <img src="{{ Storage::url($deliverer->logo) }}" alt="{{ $deliverer->name }}"
                             class="w-28 h-28 rounded-xl object-cover border-2 border-dark-300 mb-4 shadow-lg">
                    @else
                        <div class="w-28 h-28 rounded-xl bg-gradient-to-br from-primary-500 to-primary-600 flex items-center justify-center text-white text-4xl font-bold mb-4 shadow-lg">
                            {{ strtoupper(substr($deliverer->name, 0, 2)) }}
                        </div>
                    @endif
                    <h2 class="text-xl font-bold text-white text-center">{{ $deliverer->name }}</h2>

                    <div class="mt-3 flex flex-col gap-2 w-full">
                        @if($deliverer->user_id)
                            <span class="px-4 py-2 inline-flex items-center justify-center text-sm font-semibold rounded-lg bg-green-900/30 text-green-400 border border-green-500/50">
                                <i class="fas fa-check-circle mr-2"></i> Synchronisé
                            </span>
                        @else
                            <span class="px-4 py-2 inline-flex items-center justify-center text-sm font-semibold rounded-lg bg-yellow-900/30 text-yellow-400 border border-yellow-500/50">
                                <i class="fas fa-clock mr-2"></i> En attente
                            </span>
                        @endif

                        <span class="px-4 py-2 inline-flex items-center justify-center text-sm font-semibold rounded-lg {{ $deliverer->is_active ? 'bg-blue-900/30 text-blue-400 border-blue-500/50' : 'bg-gray-900/30 text-gray-400 border-gray-500/50' }} border">
                            <i class="fas fa-{{ $deliverer->is_active ? 'toggle-on' : 'toggle-off' }} mr-2"></i>
                            {{ $deliverer->is_active ? 'Active' : 'Inactive' }}
                        </span>
                    </div>
                </div>

                <div class="border-t border-dark-200 pt-4 space-y-3">
                    <div class="flex items-center justify-between">
                        <span class="text-gray-400"><i class="fas fa-users text-primary-500 mr-2"></i> Coursiers synchronisés</span>
                        <span class="font-medium text-white">{{ $deliverer->activeCodeSyncs()->count() }}</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-gray-400"><i class="fas fa-calendar text-primary-500 mr-2"></i> Créé</span>
                        <span class="font-medium text-white">{{ $deliverer->created_at->displayTz()->format('d/m/Y') }}</span>
                    </div>
                </div>
            </div>

            <!-- Sync Status -->
            @if($deliverer->user_id)
                <div class="bg-dark-100 rounded-xl shadow-lg border border-green-500/30 p-6">
                    <h3 class="text-lg font-semibold text-white mb-4 flex items-center">
                        <i class="fas fa-user-check text-green-500 mr-2"></i> Livreur Synchronisé
                    </h3>
                    <div class="space-y-3">
                        <div>
                            <label class="text-sm text-gray-500">Nom</label>
                            <p class="text-white font-medium">{{ $deliverer->user->name ?? 'N/A' }}</p>
                        </div>
                        <div>
                            <label class="text-sm text-gray-500">Email</label>
                            <p class="text-white font-medium">{{ $deliverer->user->email ?? 'N/A' }}</p>
                        </div>
                        <div>
                            <label class="text-sm text-gray-500">Téléphone</label>
                            <p class="text-white font-medium">{{ $deliverer->user->phone ?? 'N/A' }}</p>
                        </div>
                    </div>
                </div>
            @else
                <!-- Sync Code -->
                @if($deliverer->syncCodes->first())
                    @php $latestCode = $deliverer->syncCodes->first(); @endphp
                    <div class="bg-dark-100 rounded-xl shadow-lg border border-yellow-500/30 p-6">
                        <h3 class="text-lg font-semibold text-white mb-4 flex items-center">
                            <i class="fas fa-key text-yellow-500 mr-2"></i> Code de Synchronisation
                        </h3>
                        <div class="space-y-3">
                            <div class="p-4 bg-dark-50 rounded-lg border border-dark-300 text-center">
                                <code class="text-2xl font-mono text-primary-400 font-bold">{{ $latestCode->sync_code }}</code>
                            </div>
                            <div class="space-y-2 text-sm">
                                <div class="flex justify-between">
                                    <span class="text-gray-500">Statut:</span>
                                    @if($latestCode->is_used)
                                        <span class="text-green-400">Utilisé</span>
                                    @elseif($latestCode->isExpired())
                                        <span class="text-red-400">Expiré</span>
                                    @else
                                        <span class="text-green-400">Valide</span>
                                    @endif
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-gray-500">Envoyé via:</span>
                                    <span class="text-white">
                                        @if($latestCode->sent_via == 'email')
                                            <i class="fas fa-envelope"></i> Email
                                        @endif
                                    </span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-gray-500">Expire le:</span>
                                    <span class="text-white">{{ $latestCode->expires_at->displayTz()->format('d/m/Y') }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                @endif
            @endif

            <!-- Nouveau code : coursier supplémentaire, code expiré, partenaire créé hors formulaire -->
            <form action="{{ route('admin.deliverers.sync-code', $deliverer) }}" method="POST">
                @csrf
                <button type="submit" class="w-full px-4 py-3 bg-dark-100 border border-primary-500/50 text-primary-400 rounded-xl hover:bg-dark-200 transition-all">
                    <i class="fas fa-key mr-2"></i> Générer un code de synchronisation
                </button>
                <p class="mt-2 text-xs text-gray-500 text-center">Le coursier le saisit dans l'app ASSO pour recevoir les livraisons de ce partenaire.</p>
            </form>
        </div>

        <!-- Right: Details -->
        <div class="lg:col-span-2 space-y-6">
            <!-- Contact Info -->
            <div class="bg-dark-100 rounded-xl shadow-lg border border-dark-200 p-6">
                <h3 class="text-xl font-bold text-white mb-4 flex items-center">
                    <i class="fas fa-address-card text-primary-500 mr-2"></i>
                    Informations de Contact
                </h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="text-sm text-gray-500">Email</label>
                        <p class="text-white font-medium">
                            <i class="fas fa-envelope text-primary-500 mr-1"></i>
                            {{ $deliverer->email }}
                        </p>
                    </div>
                    <div>
                        <label class="text-sm text-gray-500">Téléphone</label>
                        <p class="text-white font-medium">
                            <i class="fas fa-phone text-primary-500 mr-1"></i>
                            {{ $deliverer->phone }}
                        </p>
                    </div>
                </div>

                @if($deliverer->description)
                    <div class="mt-4 pt-4 border-t border-dark-200">
                        <label class="text-sm text-gray-500">Description</label>
                        <p class="text-white mt-1">{{ $deliverer->description }}</p>
                    </div>
                @endif
            </div>

            <!-- Services et tarifs (même résumé que « Partenaires logistiques ») -->
            <div class="bg-dark-100 rounded-xl shadow-lg border border-dark-200 p-6">
                <h3 class="text-xl font-bold text-white mb-4 flex items-center">
                    <i class="fas fa-truck-loading text-primary-500 mr-2"></i>
                    Services et tarifs proposés aux acheteurs
                </h3>
                @include('admin.delivery_partners._services_summary', ['partner' => $deliverer])
            </div>

            <!-- Delivery Zones -->
            <div class="bg-dark-100 rounded-xl shadow-lg border border-dark-200 p-6">
                @php
                    $gridZoneCount = $deliverer->cityGrids->sum(fn ($g) => count($g->zones));
                    $zoneColors = ['#ef4444', '#f59e0b', '#10b981', '#3b82f6', '#8b5cf6', '#ec4899', '#14b8a6', '#eab308', '#6366f1', '#84cc16'];
                    $mapPoints = [];
                    $mapCities = [];
                    $cityKey = fn ($c) => \App\Support\CountryCode::key($c);
                    // Urbain : quartiers placés, colorés par zone, et centre de chaque ville couverte.
                    foreach ($deliverer->cityGrids as $grid) {
                        $placed = [];
                        foreach ($grid->zones as $zone) {
                            foreach (\App\Models\DeliveryCityGrid::quartersOf($zone) as $quarter) {
                                if ($quarter['lat'] !== null && $quarter['lng'] !== null) {
                                    $placed[] = [$quarter['lat'], $quarter['lng']];
                                    $mapPoints[] = ['lat' => $quarter['lat'], 'lng' => $quarter['lng'], 'zone' => (int) $zone['code'], 'label' => $quarter['name'] . ' — ' . $zone['label'] . ' (' . $grid->city . ')'];
                                }
                            }
                        }
                        $center = $placed
                            ? [array_sum(array_column($placed, 0)) / count($placed), array_sum(array_column($placed, 1)) / count($placed)]
                            : \App\Support\CityCoordinates::of($grid->city);
                        $radiusKm = $placed && $center
                            ? max(2, max(array_map(fn ($p) => \App\Models\DeliveryCityGrid::distanceKm($center[0], $center[1], $p[0], $p[1]), $placed)) + 1)
                            : 5;
                        $mapCities[$cityKey($grid->city)] = ['name' => $grid->city, 'query' => $grid->city . ', Cameroun', 'lat' => $center[0] ?? null, 'lng' => $center[1] ?? null, 'urban' => true, 'radius_km' => round($radiusKm, 1), 'zones' => count($grid->zones), 'active' => $grid->is_active, 'agency' => false];
                    }
                    // Interurbain / international : villes d'agence et trajets.
                    $mapRoutes = [];
                    foreach ($deliverer->deliveryRoutes as $route) {
                        $ends = [];
                        foreach ([[$route->origin_city, $route->origin_country], [$route->destination_city, $route->destination_country]] as [$city, $country]) {
                            $name = $city ?: \App\Support\CountryCode::name($country);
                            $key = $cityKey($name);
                            $coords = $city ? \App\Support\CityCoordinates::of($city) : null;
                            $mapCities[$key] ??= ['name' => $name, 'query' => $city ? $city . ', ' . \App\Support\CountryCode::name($country) : $name, 'lat' => $coords[0] ?? null, 'lng' => $coords[1] ?? null, 'urban' => false, 'agency' => false];
                            $mapCities[$key]['agency'] = true;
                            $ends[] = $key;
                        }
                        $mapRoutes[] = ['from' => $ends[0], 'to' => $ends[1], 'label' => $route->label(), 'lead_time' => $route->lead_time, 'active' => $route->is_active, 'international' => $route->origin_country !== $route->destination_country];
                    }
                    foreach ($deliverer->deliveryZones as $zone) {
                        if ($zone->center_latitude && $zone->center_longitude) {
                            $mapPoints[] = ['lat' => (float) $zone->center_latitude, 'lng' => (float) $zone->center_longitude, 'zone' => null, 'label' => $zone->name];
                        }
                    }
                    $hasMap = $mapPoints || $mapCities;
                    $agencyLabel = $deliverer->service_mode === 'agency_to_agency' ? 'Agence ' . $deliverer->name : 'Ville desservie';
                @endphp
                <h3 class="text-xl font-bold text-white mb-4 flex items-center">
                    <i class="fas fa-map-marked-alt text-primary-500 mr-2"></i>
                    Zones sur carte ({{ $gridZoneCount + $deliverer->deliveryZones->count() }})
                </h3>

                @if($hasMap)
                    <div class="flex flex-wrap items-center gap-2 mb-2 text-xs">
                        <button type="button" data-map-view="all" class="px-3 py-1 rounded-full bg-primary-500 text-white hover:bg-primary-600"><i class="fas fa-globe-africa mr-1"></i> Tout le réseau</button>
                        @foreach($mapCities as $key => $c)
                            @if($c['urban'])
                                <button type="button" data-map-view="{{ $key }}" class="px-3 py-1 rounded-full border border-blue-500/50 bg-blue-900/30 text-blue-300 hover:bg-blue-800/40"><i class="fas fa-city mr-1"></i> {{ $c['name'] }}</button>
                            @endif
                        @endforeach
                    </div>
                    <div id="deliverer_zones_map" class="h-[480px] rounded-lg border border-dark-300 mb-2 z-0"></div>
                    <div class="flex flex-wrap gap-x-5 gap-y-1 text-xs text-gray-400 mb-4">
                        <span><span class="inline-block w-3 h-3 rounded-full align-middle mr-1" style="background:#3b82f6"></span> Quartier (couleur = zone urbaine)</span>
                        <span><span class="inline-block w-4 h-4 rounded-full align-middle mr-1 border-2 border-dashed border-blue-400 bg-blue-500/10"></span> Ville couverte en livraison urbaine</span>
                        @if($mapRoutes)
                            <span><span class="inline-flex w-5 h-5 rounded-md align-middle mr-1 items-center justify-center bg-purple-600 text-white text-[10px]"><i class="fas fa-warehouse"></i></span> {{ $agencyLabel }}</span>
                            <span><span class="inline-block w-6 align-middle mr-1 border-t-2 border-dashed border-purple-400"></span> Trajet interurbain (délai)</span>
                            @if(collect($mapRoutes)->contains('international', true))
                                <span><span class="inline-block w-6 align-middle mr-1 border-t-2 border-dotted border-amber-400"></span> Trajet international</span>
                            @endif
                        @endif
                    </div>
                    @foreach($deliverer->cityGrids as $grid)
                        <div class="mb-4 p-4 bg-dark-50 rounded-lg border border-dark-300 text-sm">
                            <p class="text-white font-semibold mb-2"><i class="fas fa-city text-primary-500 mr-1"></i> {{ $grid->city }}</p>
                            <div class="space-y-1">
                                @foreach($grid->zones as $zone)
                                    @php $quarters = \App\Models\DeliveryCityGrid::quartersOf($zone); $unplaced = collect($quarters)->whereNull('lat')->count(); @endphp
                                    <div class="flex items-start gap-2">
                                        <span class="inline-block w-3 h-3 rounded-full mt-1 shrink-0" style="background: {{ $zoneColors[((int) $zone['code'] - 1) % count($zoneColors)] }}"></span>
                                        <span class="text-gray-400"><span class="text-gray-200">{{ $zone['label'] }}</span>@if($grid->agency_zone === (int) $zone['code']) <span class="text-primary-400">(agence)</span>@endif : {{ implode(', ', array_column($quarters, 'name')) ?: 'aucun quartier' }}@if($unplaced) <span class="text-yellow-400">— {{ $unplaced }} non placé(s)</span>@endif</span>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                @endif

                @forelse($deliverer->deliveryZones as $zone)
                    <div class="mb-4 last:mb-0 p-4 bg-dark-50 rounded-lg border border-dark-300">
                        <div class="flex items-start justify-between mb-3">
                            <div>
                                <h4 class="text-white font-semibold text-lg flex items-center gap-2">
                                    <i class="fas fa-map-pin text-primary-500"></i>
                                    {{ $zone->name }}
                                </h4>
                                <p class="text-gray-400 text-sm mt-1">
                                    <i class="fas fa-crosshairs mr-1"></i>
                                    Centre: {{ number_format($zone->center_latitude, 6) }}, {{ number_format($zone->center_longitude, 6) }}
                                </p>
                            </div>
                            <span class="px-3 py-1 rounded-full text-xs font-semibold {{ $zone->is_active ? 'bg-green-900/30 text-green-400 border border-green-500/50' : 'bg-gray-900/30 text-gray-400 border border-gray-500/50' }}">
                                {{ $zone->is_active ? 'Active' : 'Inactive' }}
                            </span>
                        </div>

                        @if($zone->pricelist)
                            <div class="mt-3 p-3 bg-dark-100 rounded border border-dark-200">
                                <p class="text-sm text-gray-400 mb-2">
                                    <i class="fas fa-dollar-sign text-primary-500 mr-1"></i>
                                    Type de tarification:
                                    <span class="text-white font-medium">
                                        @if($zone->pricelist->pricing_type == 'fixed')
                                            Prix Fixe
                                        @elseif($zone->pricelist->pricing_type == 'weight_category')
                                            Par Catégorie de Poids
                                        @elseif($zone->pricelist->pricing_type == 'volumetric_weight')
                                            Poids Volumétrique
                                        @endif
                                    </span>
                                </p>

                                <p class="text-sm text-gray-400 mb-2">
                                    <i class="fas fa-coins text-primary-500 mr-1"></i>
                                    Commission ASSO:
                                    <span class="text-white font-medium">
                                        {{ number_format($zone->pricelist->asso_commission, 0, ',', ' ') }} FCFA
                                    </span>
                                </p>

                                <div class="text-xs">
                                    <p class="text-gray-500 mb-1">Données de tarification:</p>
                                    <div class="bg-dark-50 p-2 rounded font-mono text-gray-300 overflow-x-auto">
                                        {{ json_encode($zone->pricelist->pricing_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}
                                    </div>
                                </div>
                            </div>
                        @else
                            <p class="text-gray-500 text-sm">Aucune tarification configurée</p>
                        @endif
                    </div>
                @empty
                    @unless($hasMap)
                        <div class="text-center py-8">
                            <i class="fas fa-map text-gray-600 text-4xl mb-3"></i>
                            <p class="text-gray-500">Aucun quartier placé sur la carte.</p>
                            @if($deliverer->cityGrids->isNotEmpty())
                                <p class="text-gray-500 text-sm mt-1">Placez les quartiers dans <a href="{{ route('admin.delivery-partners.edit', $deliverer) }}" class="text-primary-400 hover:text-primary-300">Partenaires logistiques</a>.</p>
                            @endif
                        </div>
                    @endunless
                @endforelse
            </div>

            <!-- Sync Codes History -->
            @if($deliverer->syncCodes->count() > 0)
                <div class="bg-dark-100 rounded-xl shadow-lg border border-dark-200 p-6">
                    <h3 class="text-xl font-bold text-white mb-4 flex items-center">
                        <i class="fas fa-history text-primary-500 mr-2"></i>
                        Historique des Codes de Synchronisation
                    </h3>

                    <div class="space-y-3">
                        @foreach($deliverer->syncCodes as $code)
                            <div class="p-3 bg-dark-50 rounded-lg border border-dark-300 flex items-center justify-between">
                                <div>
                                    <code class="text-primary-400 font-mono font-bold">{{ $code->sync_code }}</code>
                                    <p class="text-xs text-gray-500 mt-1">
                                        Créé le {{ $code->created_at->displayTz()->format('d/m/Y H:i') }}
                                        • Expire le {{ $code->expires_at->displayTz()->format('d/m/Y') }}
                                    </p>
                                </div>
                                <div>
                                    @if($code->is_used)
                                        <span class="px-2 py-1 bg-green-900/30 text-green-400 text-xs rounded-full border border-green-500/50">
                                            <i class="fas fa-check-circle"></i> Utilisé
                                        </span>
                                    @elseif($code->isExpired())
                                        <span class="px-2 py-1 bg-red-900/30 text-red-400 text-xs rounded-full border border-red-500/50">
                                            <i class="fas fa-times-circle"></i> Expiré
                                        </span>
                                    @else
                                        <span class="px-2 py-1 bg-blue-900/30 text-blue-400 text-xs rounded-full border border-blue-500/50">
                                            <i class="fas fa-clock"></i> Valide
                                        </span>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    </div>
</div>
@endsection

@if($hasMap)
    @push('styles')
        <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
        <style>
            .map-agency { background: #9333ea; color: #fff; border: 2px solid #fff; border-radius: 6px; display: flex; align-items: center; justify-content: center; font-size: 11px; box-shadow: 0 1px 4px rgba(0,0,0,.5); }
            .map-agency.map-agency-urban { background: #2563eb; }
            .map-city-label { background: rgba(17,17,17,.8); color: #fff; border: 0; box-shadow: none; font-weight: 600; }
            .map-city-label::before { display: none; }
            .map-route-label { background: #faf5ff; color: #6b21a8; border: 1px solid #c084fc; font-size: 11px; padding: 0 4px; }
            .map-route-label::before { display: none; }
        </style>
    @endpush
    @push('scripts')
        <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
        <script>
        (async function () {
            const points = @json($mapPoints);
            const cities = @json((object) $mapCities);
            const routes = @json($mapRoutes);
            const colors = @json($zoneColors);
            const agencyLabel = @json($agencyLabel);
            const map = L.map('deliverer_zones_map');
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '© OpenStreetMap' }).addTo(map);
            map.setView([5.7, 12.4], 6);

            // Villes sans position connue : recherche OpenStreetMap, une par seconde.
            for (const c of Object.values(cities).filter(c => c.lat === null)) {
                try {
                    const res = await (await fetch('https://nominatim.openstreetmap.org/search?format=json&limit=1&q=' + encodeURIComponent(c.query))).json();
                    if (res.length) { c.lat = parseFloat(res[0].lat); c.lng = parseFloat(res[0].lon); }
                } catch (e) {}
                await new Promise(r => setTimeout(r, 1100));
            }

            const all = [];
            const byCity = {};
            // Interurbain : trajets en pointillés violets (ambre pour l'international), sous les villes.
            routes.forEach(r => {
                const a = cities[r.from], b = cities[r.to];
                if (!a || !b || a.lat === null || b.lat === null) return;
                const line = L.polyline([[a.lat, a.lng], [b.lat, b.lng]], {
                    color: r.international ? '#f59e0b' : '#a855f7', weight: 3, opacity: r.active ? .9 : .35,
                    dashArray: r.international ? '2 8' : '8 8',
                }).addTo(map);
                line.bindTooltip(r.label + (r.lead_time ? ' — ' + r.lead_time : '') + (r.active ? '' : ' (inactif)'), { sticky: true });
                if (r.lead_time) {
                    L.tooltip({ permanent: true, direction: 'center', className: 'map-route-label' })
                        .setLatLng([(a.lat + b.lat) / 2, (a.lng + b.lng) / 2]).setContent(r.lead_time).addTo(map);
                }
            });
            // Villes : contour de la zone urbaine couverte, marqueur d'agence pour l'interurbain.
            Object.entries(cities).forEach(([key, c]) => {
                if (c.lat === null) return;
                const group = [];
                if (c.urban) {
                    group.push(L.circle([c.lat, c.lng], { radius: c.radius_km * 1000, color: '#60a5fa', weight: 2, dashArray: '6 6', fillColor: '#3b82f6', fillOpacity: .08 })
                        .bindTooltip('Livraison urbaine à ' + c.name + ' — ' + c.zones + ' zones' + (c.active ? '' : ' (grille inactive)')).addTo(map));
                }
                if (c.agency || c.urban) {
                    const m = L.marker([c.lat, c.lng], {
                        icon: L.divIcon({ className: '', html: '<div class="map-agency ' + (c.urban ? 'map-agency-urban' : '') + '" style="width:24px;height:24px"><i class="fas ' + (c.agency ? 'fa-warehouse' : 'fa-city') + '"></i></div>', iconSize: [24, 24], iconAnchor: [12, 12] }),
                        zIndexOffset: 1000,
                    }).addTo(map);
                    m.bindTooltip(c.name, { permanent: true, direction: 'top', offset: [0, -12], className: 'map-city-label' });
                    const lines = [];
                    if (c.agency) lines.push(agencyLabel + ' — ' + c.name);
                    if (c.urban) lines.push('Livraison urbaine : ' + c.zones + ' zones');
                    routes.filter(r => r.from === key || r.to === key).forEach(r => lines.push(r.label + (r.lead_time ? ' (' + r.lead_time + ')' : '')));
                    m.bindPopup(lines.join('<br>'));
                    group.push(m);
                }
                all.push(...group);
                byCity[key] = group;
            });
            // Urbain : quartiers colorés par zone.
            const quarterMarkers = points.map(p => (p.zone
                ? L.circleMarker([p.lat, p.lng], { radius: 7, color: '#111', weight: 1, fillColor: colors[(p.zone - 1) % colors.length], fillOpacity: .9 })
                : L.marker([p.lat, p.lng])
            ).bindTooltip(p.label).addTo(map));
            all.push(...quarterMarkers);

            const fit = (layers, maxZoom) => layers.length && map.fitBounds(L.featureGroup(layers).getBounds().pad(0.15), { maxZoom });
            fit(all, 14);
            document.querySelectorAll('[data-map-view]').forEach(btn => btn.addEventListener('click', () => {
                const key = btn.dataset.mapView;
                if (key === 'all') return fit(all, 14);
                const c = cities[key];
                const inCity = quarterMarkers.filter(m => c && c.lat !== null && m.getLatLng().distanceTo([c.lat, c.lng]) <= (c.radius_km + 5) * 1000);
                fit([...(byCity[key] || []), ...inCity], 15);
            }));
        })();
        </script>
    @endpush
@endif
