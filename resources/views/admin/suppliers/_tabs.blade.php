@php
    $tabs = [
        ['route' => 'admin.suppliers.index', 'label' => 'Connexion', 'icon' => 'fa-plug'],
        ['route' => 'admin.suppliers.cj.categories', 'label' => 'Catégories CJ', 'icon' => 'fa-sitemap'],
        ['route' => 'admin.suppliers.cj.catalog', 'label' => 'Catalogue CJ', 'icon' => 'fa-magnifying-glass'],
        ['route' => 'admin.suppliers.cj.products', 'label' => 'Produits importés', 'icon' => 'fa-boxes-stacked'],
    ];
@endphp
<div class="flex flex-wrap gap-2 mb-6 border-b border-dark-200 pb-3">
    @foreach($tabs as $tab)
        <a href="{{ route($tab['route']) }}"
           class="px-3 py-1.5 text-sm rounded-lg {{ request()->routeIs($tab['route']) ? 'bg-primary-600 text-white' : 'bg-dark-200 text-gray-300 hover:text-white' }}">
            <i class="fas {{ $tab['icon'] }} mr-1"></i>{{ $tab['label'] }}
        </a>
    @endforeach
</div>
