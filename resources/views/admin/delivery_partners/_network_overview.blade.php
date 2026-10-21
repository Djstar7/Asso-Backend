@php
    $regions = $territories->where('level', 1)->sortBy('name');
    $nationalRoutes = $partner->deliveryRoutes->where('network_level', 'interregional');
@endphp
<div class="mb-6">
    <h3 class="text-lg font-semibold text-white mb-2">Les {{ $regions->count() }} régions du réseau</h3>
    <p class="text-sm text-gray-400 mb-4">Région → département → arrondissement. Chaque arrondissement possède son propre centre GPS et son rayon de couverture en kilomètres. Une adresse située dans un cercle actif est couverte ; les limites administratives ne définissent pas le périmètre de livraison.</p>
    <div class="grid grid-cols-1 xl:grid-cols-2 gap-4">
        @foreach($regions as $region)
            @php
                $departments = $territories->where('level', 2)->where('parent_code', $region->code)->sortBy('name');
                $regionGrids = $networkGrids->whereIn('department_code', $departments->pluck('code'))->keyBy('department_code');
                $regionZones = $regionGrids->flatMap(fn ($g) => $g->zones);
                $regionRoutes = $nationalRoutes->filter(fn ($r) => $r->origin_territory_code === $region->code || $r->destination_territory_code === $region->code);
            @endphp
            <article class="bg-dark-50 border border-dark-300 rounded-xl p-4" data-network-region="{{ $region->code }}">
                <div class="flex flex-wrap items-start justify-between gap-2 mb-3">
                    <div>
                        <h4 class="font-semibold text-white">{{ $region->name }}</h4>
                        <p class="text-xs text-gray-400">Chef-lieu : {{ $region->metadata['hub_city'] ?? 'À renseigner' }}</p>
                    </div>
                    <span class="text-xs rounded-full px-2 py-1 bg-primary-500/10 text-primary-300">{{ $regionGrids->where('is_active', true)->count() }}/{{ $departments->count() }} départements actifs</span>
                </div>
                <p class="text-sm text-gray-300 mb-3">{{ $departments->count() }} départements · {{ $regionZones->count() }} zones · {{ $regionZones->where('is_active', true)->count() }} zones actives</p>
                <div class="space-y-2">
                    @foreach($departments as $department)
                        @php($departmentGrid = $regionGrids->get($department->code))
                        <details class="border border-dark-300 rounded-lg p-3" data-network-department="{{ $department->code }}">
                            <summary class="cursor-pointer text-sm text-white">{{ $department->name }} — {{ count($departmentGrid?->zones ?? []) }} zones · {{ $departmentGrid?->is_active ? 'Actif' : 'À valider' }}</summary>
                            <div class="pt-3">
                                @if($departmentGrid)
                                    <a class="inline-flex text-sm text-primary-400 hover:underline mb-3" href="{{ route('admin.delivery-partners.edit', [$partner, 'section' => 'network', 'network_department' => $department->code]) }}#territorial-department">Configurer les centres, rayons et tarifs →</a>
                                    <ul class="space-y-2 text-xs text-gray-300">
                                        @foreach($departmentGrid->zones as $zone)
                                            <li class="flex flex-wrap justify-between gap-x-3 gap-y-1" data-network-zone="{{ $zone['territory_code'] ?? '' }}">
                                                <span>{{ $zone['label'] }}</span>
                                                <span class="text-gray-400">Rayon : {{ $zone['radius_km'] ?? '—' }} km · {{ !empty($zone['is_active']) ? 'Active' : 'À valider' }}</span>
                                            </li>
                                        @endforeach
                                    </ul>
                                @else
                                    <p class="text-sm text-amber-300">Grille départementale manquante.</p>
                                @endif
                            </div>
                        </details>
                    @endforeach
                </div>
                <details class="mt-3 border-t border-dark-300 pt-3">
                    <summary class="text-sm text-primary-300 cursor-pointer">{{ $regionRoutes->count() }} liaisons nationales · {{ $regionRoutes->where('is_active', true)->count() }} actives</summary>
                    <ul class="mt-3 space-y-2 text-xs text-gray-300">
                        @foreach($regionRoutes as $regionRoute)
                            @php($otherRegionCode = $regionRoute->origin_territory_code === $region->code ? $regionRoute->destination_territory_code : $regionRoute->origin_territory_code)
                            <li class="flex flex-wrap justify-between gap-2">
                                <span>{{ $region->name }} {{ $regionRoute->bidirectional ? '↔' : '→' }} {{ $territories->get($otherRegionCode)?->name }}</span>
                                <span class="text-gray-400">{{ $regionRoute->is_active ? 'Active' : 'À valider' }}</span>
                            </li>
                        @endforeach
                    </ul>
                </details>
            </article>
        @endforeach
    </div>
</div>
