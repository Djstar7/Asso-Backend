{{-- En-tête commun de l'espace « Ma rubrique » : titre, rubrique affichée, onglets. --}}
@php
    $tabs = [
        'admin.division.dashboard' => ['Tableau de bord', 'fa-gauge'],
        'admin.division.orders.index' => [$division?->isRealEstate() ? 'Réservations' : 'Commandes', 'fa-receipt'],
        'admin.division.products.index' => [$division?->isRealEstate() ? 'Annonces' : 'Produits', 'fa-box'],
        'admin.division.traffic.index' => ['Trafic', 'fa-chart-line'],
        'admin.division.disputes.index' => ['Litiges', 'fa-scale-balanced'],
        'admin.division.earnings.index' => ['Mes gains', 'fa-coins'],
        'admin.division.withdrawal.index' => ['Retrait', 'fa-arrow-up-from-bracket'],
    ];
    if ($division?->isRealEstate()) unset($tabs['admin.division.disputes.index']);
    if (! $division) $tabs = array_intersect_key($tabs, array_flip(['admin.division.earnings.index', 'admin.division.withdrawal.index']));
    if (auth()->user()->isAdmin()) unset($tabs['admin.division.withdrawal.index']);
@endphp
<div class="flex flex-col md:flex-row md:items-end md:justify-between gap-4">
    <div>
        <p class="text-sm text-gray-400">Ma rubrique</p>
        <h1 class="text-2xl font-bold text-white">{{ $title }}{{ $division ? ' · ' . $division->name : '' }}</h1>
        @if(auth()->user()->isAdmin())
            <p class="text-xs text-amber-300 mt-1"><i class="fas fa-eye mr-1"></i>Aperçu administrateur de l'espace du chef.</p>
        @endif
    </div>
    @if($divisions->count() > 1)
        <form method="GET" class="flex gap-2 items-center">
            @foreach(request()->except(['division', 'page']) as $k => $v)
                @if(is_string($v))<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endif
            @endforeach
            <select name="division" onchange="this.form.submit()" class="px-3 py-2 bg-dark-50 text-white border border-dark-200 rounded-lg">
                @foreach($divisions as $d)
                    <option value="{{ $d->id }}" @selected($division?->id === $d->id)>{{ $d->name }}</option>
                @endforeach
            </select>
        </form>
    @endif
</div>
<nav class="flex flex-wrap gap-2 border-b border-dark-200 pb-3">
    @foreach($tabs as $route => [$label, $icon])
        <a href="{{ route($route, $query) }}"
           class="px-3 py-1.5 rounded-lg text-sm {{ request()->routeIs(str_replace('.index', '.*', $route)) || request()->routeIs($route) ? 'bg-primary-600 text-white' : 'bg-dark-100 text-gray-300 hover:bg-dark-200' }}">
            <i class="fas {{ $icon }} mr-1"></i>{{ $label }}
        </a>
    @endforeach
</nav>
