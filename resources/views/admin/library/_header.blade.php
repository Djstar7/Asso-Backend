{{-- En-tête commun aux pages de la Bibliothèque : titre, actions et onglets. --}}
@php
    $tabs = [
        'admin.library.overview' => ['Vue d’ensemble', 'fa-chart-bar', ['admin.library.overview']],
        'admin.library.books.index' => ['Livres', 'fa-book', ['admin.library.books.*']],
        'admin.library.import.create' => ['Importer', 'fa-file-import', ['admin.library.import.*']],
        'admin.library.shelves.index' => ['Rayons et genres', 'fa-layer-group', ['admin.library.shelves.*']],
    ];
@endphp
<div class="mb-6 flex items-start justify-between flex-wrap gap-3">
    <div>
        <h1 class="text-2xl font-bold text-white">{{ $title }}</h1>
        <p class="text-gray-400">{{ $subtitle }}</p>
    </div>
    <div class="flex gap-2 flex-wrap">
        <a href="{{ route('admin.library.import.create') }}" class="px-4 py-2 bg-dark-200 text-white rounded-lg hover:bg-dark-50">
            <i class="fas fa-file-import mr-2"></i>Importer
        </a>
        <a href="{{ route('admin.products.create', ['library' => 1]) }}" class="px-4 py-2 bg-gradient-to-r from-primary-500 to-primary-600 text-white rounded-lg hover:shadow-lg">
            <i class="fas fa-plus mr-2"></i>Ajouter un livre
        </a>
    </div>
</div>

<nav class="mb-6 flex gap-1 overflow-x-auto border-b border-dark-200" aria-label="Bibliothèque">
    @foreach($tabs as $route => [$label, $icon, $patterns])
        @php($active = request()->routeIs(...$patterns))
        <a href="{{ route($route) }}" @if($active) aria-current="page" @endif
           class="px-4 py-2 -mb-px whitespace-nowrap border-b-2 text-sm {{ $active ? 'border-primary-500 text-primary-300 font-semibold' : 'border-transparent text-gray-400 hover:text-white' }}">
            <i class="fas {{ $icon }} mr-1"></i>{{ $label }}
        </a>
    @endforeach
</nav>
