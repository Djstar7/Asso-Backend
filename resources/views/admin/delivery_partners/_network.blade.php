@php
    $networkGrids = $partner->cityGrids->whereNotNull('department_code')->sortBy('department_code');
    $territories = \App\Models\DeliveryTerritory::all()->keyBy('code');
    $selectedGrid = $networkGrids->firstWhere('department_code', request('network_department'));
@endphp
@if($networkGrids->isNotEmpty())
<section class="bg-dark-100 rounded-xl border border-dark-200 p-6 mb-6">
    <h2 class="text-xl text-white font-semibold mb-3">Réseau territorial — collecte en boutique</h2>
    <p class="text-gray-400 mb-3">{{ $territories->where('level', 1)->count() }} régions · {{ $territories->where('level', 2)->count() }} départements · {{ $territories->where('level', 3)->count() }} arrondissements. Chaque zone couvre le cercle autour de son centre, indépendamment du nom du quartier. Les centres initiaux et tarifs sont provisoires.</p>
    <form method="POST" action="{{ route('admin.delivery-partners.network.update', $partner) }}" class="mb-4 flex items-center gap-3">
        @csrf @method('PUT')
        <label class="text-white"><input type="checkbox" name="territorial_network_enabled" value="1" @checked($partner->territorial_network_enabled)> Utiliser le réseau territorial pour les nouveaux devis nationaux</label>
        <button class="px-3 py-2 bg-primary-500 text-white rounded">Enregistrer le mode</button>
    </form>
    <p class="text-sm text-gray-400 mb-4">{{ $networkGrids->where('is_active', true)->count() }} départements actifs · {{ $networkGrids->flatMap(fn ($g) => $g->zones)->where('is_active', true)->count() }} cercles actifs · {{ $partner->deliveryRoutes->whereNotNull('network_key')->where('is_active', true)->count() }} liaisons actives. Une collecte est incluse dans les deux offres ; la commission ASSO est appliquée une fois.</p>
    @if(!$selectedGrid)
        @include('admin.delivery_partners._network_overview')
    @else
        <a href="{{ route('admin.delivery-partners.edit', [$partner, 'section' => 'network']) }}" class="inline-block text-primary-400 text-sm mb-4">← Voir les 10 régions et les 58 départements</a>
    <form id="territorial-department" method="GET" action="{{ route('admin.delivery-partners.edit', $partner) }}" class="mb-5 scroll-mt-6">
        <input type="hidden" name="section" value="network">
        <label class="text-gray-300">Région / département</label>
        <select name="network_department" onchange="this.form.submit()" class="bg-dark-50 text-white border border-dark-200 rounded p-2">
            @foreach($networkGrids->groupBy(fn ($g) => $territories->get($g->department_code)?->parent_code) as $regionCode => $group)
                <optgroup label="{{ $territories->get($regionCode)?->name }}">
                @foreach($group as $g)
                    <option value="{{ $g->department_code }}" @selected($g->id === $selectedGrid->id)>{{ $territories->get($g->department_code)?->name }} — {{ $g->city }}</option>
                @endforeach
                </optgroup>
            @endforeach
        </select>
    </form>
    @include('admin.delivery_partners._territorial_grid', ['grid' => $selectedGrid])
    <h3 class="text-white text-lg font-semibold mt-6 mb-3">Liaisons du département et de sa région</h3>
    @php($regionCode = $territories->get($selectedGrid->department_code)?->parent_code)
    @foreach($partner->deliveryRoutes->whereNotNull('network_key')->filter(fn ($r) => in_array($r->origin_territory_code, [$selectedGrid->department_code, $regionCode]) || in_array($r->destination_territory_code, [$selectedGrid->department_code, $regionCode])) as $networkRoute)
        <details class="border border-dark-200 rounded mb-2 p-3">
            <summary class="text-white cursor-pointer">{{ $networkRoute->label() }} — {{ $networkRoute->is_active ? 'Active' : 'À valider' }} · {{ $networkRoute->network_level === 'interregional' ? 'National' : 'Régional' }}</summary>
            <div class="pt-3"><a class="text-primary-400 text-sm hover:underline" href="{{ route('admin.delivery-partners.edit', [$partner, 'section' => 'routes', 'edit_route' => $networkRoute->id]) }}#route-editor">Configurer cette liaison →</a></div>
        </details>
    @endforeach
    @endif
</section>
@endif
