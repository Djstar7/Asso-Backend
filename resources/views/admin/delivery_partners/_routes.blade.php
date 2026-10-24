<section class="bg-dark-100 border border-dark-200 rounded-xl p-4 md:p-6">
    <div class="flex flex-wrap items-start justify-between gap-3 mb-4">
        <div>
            <h2 class="text-xl font-semibold text-white">Trajets nationaux et internationaux</h2>
            <p class="text-sm text-gray-400 mt-1">Trajets au Cameroun, importations et exportations via Solex puis DHL ou FedEx. Les prix se modifient dans chaque trajet.</p>
        </div>
        <a href="{{ route('admin.delivery-partners.edit', [$partner, 'section' => 'routes', 'add_route' => 1]) }}#route-editor" class="text-sm text-primary-400 hover:underline">+ Ajouter un trajet</a>
    </div>
    <form method="GET" action="{{ route('admin.delivery-partners.edit', $partner) }}" class="flex flex-wrap items-center gap-3 mb-4">
        <input type="hidden" name="section" value="routes">
        <label for="network-level" class="text-sm text-gray-300">Type de liaison</label>
        <select id="network-level" name="network_level" onchange="this.form.submit()" class="bg-dark-50 border border-dark-300 rounded-lg px-3 py-2 text-sm">
            @foreach(['' => 'Toutes les liaisons', 'interregional' => 'National — entre régions', 'intraregional' => 'Régional — entre départements', 'legacy' => 'Autres trajets et international'] as $value => $label)
                <option value="{{ $value }}" @selected(request('network_level', '') === $value)>{{ $label }}</option>
            @endforeach
        </select>
        <noscript><button class="text-primary-400">Filtrer</button></noscript>
    </form>
    <p class="text-sm text-gray-400 mb-3">{{ $routesPage->total() }} liaisons · 15 par page. Ouvrez une liaison pour modifier ses tarifs.</p>
    <div class="divide-y divide-dark-300">
        @forelse($routesPage as $listedRoute)
            <div class="py-3 flex flex-wrap justify-between items-center gap-3">
                <div>
                    <p class="text-sm font-medium text-white">{{ $listedRoute->label() }}</p>
                    @if(($listedRoute->pricing_data['mode'] ?? null) === 'per_kg')
                        <p class="text-sm text-primary-400">{{ number_format($listedRoute->pricing_data['price_per_kg'], 0, ',', ' ') }} FCFA / kg</p>
                    @endif
                    @if($listedRoute->export_config['demo_dataset'] ?? null)
                        <p class="text-xs text-yellow-400">Données d’essai · tarifs et couverture provisoires</p>
                    @endif
                    <p class="text-xs text-gray-400 mt-1">{{ ['interregional' => 'National', 'intraregional' => 'Régional'][$listedRoute->network_level] ?? 'Autre trajet' }} · {{ $listedRoute->lead_time ?: 'Délai à renseigner' }} · {{ $listedRoute->is_active ? 'Active' : 'Inactive' }}</p>
                </div>
                <a class="text-sm text-primary-400 hover:underline" href="{{ route('admin.delivery-partners.edit', [$partner, 'section' => 'routes', 'network_level' => request('network_level'), 'page' => $routesPage->currentPage(), 'edit_route' => $listedRoute->id]) }}#route-editor">Modifier →</a>
            </div>
        @empty
            <p class="py-4 text-gray-400 text-sm">Aucune liaison dans cette rubrique.</p>
        @endforelse
    </div>
    <div class="mt-4">{{ $routesPage->links() }}</div>
    @if($selectedRoute || request()->boolean('add_route'))
        <div id="route-editor" class="mt-6 pt-5 border-t border-dark-300 scroll-mt-6">
            <h3 class="text-lg font-semibold text-white mb-4">{{ $selectedRoute ? $selectedRoute->label() : 'Ajouter un trajet' }}</h3>
            @include('admin.delivery_partners._route_form', ['route' => $selectedRoute, 'action' => $selectedRoute ? route('admin.delivery-partners.routes.update', [$partner, $selectedRoute]) : route('admin.delivery-partners.routes.store', $partner), 'method' => $selectedRoute ? 'PUT' : 'POST'])
            @if($selectedRoute && !$selectedRoute->network_key)
                <form action="{{ route('admin.delivery-partners.routes.destroy', [$partner, $selectedRoute]) }}" method="POST" data-confirm="Supprimer ce trajet ?" class="mt-4">
                    @csrf @method('DELETE')
                    <button class="text-sm text-red-400">Supprimer ce trajet</button>
                </form>
            @endif
        </div>
    @endif
</section>
