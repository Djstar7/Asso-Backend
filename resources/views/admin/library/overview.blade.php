@extends('admin.layouts.app')

@section('content')
@php
    $fcfa = fn ($v) => number_format((float) $v, 0, ',', ' ') . ' FCFA';
    $number = fn ($v) => number_format((int) $v, 0, ',', ' ');
    $todo = [
        'no_sheet' => ['Sans fiche livre', 'fa-file-circle-exclamation', 'Rangés dans un rayon sans auteur renseigné.'],
        'no_isbn' => ['Sans ISBN', 'fa-barcode', 'L’ISBN évite les doublons à l’import.'],
        'no_image' => ['Sans couverture', 'fa-image', 'Un livre sans photo se vend mal.'],
        'out_of_stock' => ['En rupture', 'fa-box-open', 'Stock à zéro : invisible à l’achat.'],
    ];
@endphp
<div class="p-6">
    @include('admin.library._header', ['title' => 'Bibliothèque', 'subtitle' => 'Les livres en vente sur ASSO, gérés à part du catalogue général.'])

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <div class="bg-dark-100 rounded-xl border border-dark-200 p-5">
            <p class="text-sm text-gray-400">Livres</p>
            <p class="text-3xl font-bold text-white mt-1">{{ $number($stats['total']) }}</p>
            <p class="text-xs text-gray-500 mt-1">{{ $number($stats['active']) }} visibles</p>
        </div>
        <div class="bg-dark-100 rounded-xl border border-dark-200 p-5">
            <p class="text-sm text-gray-400">Rayons</p>
            <p class="text-3xl font-bold text-white mt-1">{{ $number($shelves->count()) }}</p>
            <p class="text-xs text-gray-500 mt-1">{{ $number($shelves->sum('subcategories_count')) }} genres</p>
        </div>
        <div class="bg-dark-100 rounded-xl border border-dark-200 p-5">
            <p class="text-sm text-gray-400">Exemplaires vendus</p>
            <p class="text-3xl font-bold text-white mt-1">{{ $number($sales['copies']) }}</p>
            <p class="text-xs text-gray-500 mt-1">{{ $salesDays }} derniers jours, commandes payées</p>
        </div>
        <div class="bg-dark-100 rounded-xl border border-dark-200 p-5">
            <p class="text-sm text-gray-400">Chiffre d’affaires livres</p>
            <p class="text-3xl font-bold text-white mt-1">{{ $fcfa($sales['revenue']) }}</p>
            <p class="text-xs text-gray-500 mt-1">{{ $salesDays }} derniers jours</p>
        </div>
    </div>

    @if($shelves->isEmpty())
        <div class="bg-dark-100 rounded-xl border border-primary-500/40 p-6 mb-6">
            <p class="text-white font-semibold">Aucun rayon pour l’instant</p>
            <p class="text-gray-400 text-sm mt-1">Créez au moins un rayon (Romans, Jeunesse, Scolaire…) avant d’ajouter ou d’importer des livres.</p>
            <a href="{{ route('admin.library.shelves.index') }}" class="inline-block mt-3 px-4 py-2 bg-primary-500 text-white rounded-lg hover:bg-primary-600">
                <i class="fas fa-layer-group mr-2"></i>Créer un rayon
            </a>
        </div>
    @endif

    <div class="grid grid-cols-1 xl:grid-cols-3 gap-6">
        <section class="bg-dark-100 rounded-xl border border-dark-200 p-6">
            <h2 class="text-lg font-semibold text-white mb-4">À compléter</h2>
            <ul class="space-y-2">
                @foreach($todo as $issue => [$label, $icon, $hint])
                    <li>
                        <a href="{{ route('admin.library.books.index', ['issue' => $issue]) }}"
                           class="flex items-center gap-3 p-3 rounded-lg hover:bg-dark-50 transition-colors">
                            <i class="fas {{ $icon }} w-5 text-center {{ $stats[$issue] > 0 ? 'text-amber-300' : 'text-gray-500' }}"></i>
                            <span class="flex-1">
                                <span class="block text-white text-sm">{{ $label }}</span>
                                <span class="block text-xs text-gray-500">{{ $hint }}</span>
                            </span>
                            <span class="text-sm font-semibold {{ $stats[$issue] > 0 ? 'text-amber-300' : 'text-gray-500' }}">{{ $number($stats[$issue]) }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        </section>

        <section class="bg-dark-100 rounded-xl border border-dark-200 p-6">
            <h2 class="text-lg font-semibold text-white mb-4">Meilleures ventes</h2>
            @forelse($topBooks as $row)
                @php($book = $row->product)
                <div class="flex items-center gap-3 py-2 {{ $loop->first ? '' : 'border-t border-dark-200' }}">
                    <div class="w-10 h-14 rounded bg-dark-50 overflow-hidden flex-shrink-0">
                        @if($book?->primaryImage)
                            <img src="{{ media_url($book->primaryImage->image_path) }}" alt="" class="w-full h-full object-cover">
                        @endif
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="text-white text-sm truncate">{{ $book?->name ?? 'Livre supprimé' }}</p>
                        <p class="text-xs text-gray-400 truncate">{{ $book?->book?->author ?? '—' }}</p>
                    </div>
                    <div class="text-right">
                        <p class="text-sm text-white">{{ $number($row->copies) }} ex.</p>
                        <p class="text-xs text-gray-500">{{ $fcfa($row->revenue) }}</p>
                    </div>
                </div>
            @empty
                <p class="text-sm text-gray-400">Aucune vente payée sur les {{ $salesDays }} derniers jours.</p>
            @endforelse
        </section>

        <section class="bg-dark-100 rounded-xl border border-dark-200 p-6">
            <h2 class="text-lg font-semibold text-white mb-4">Rayons</h2>
            @forelse($shelves as $shelf)
                <a href="{{ route('admin.library.books.index', ['category_id' => $shelf->id]) }}"
                   class="flex items-center justify-between py-2 {{ $loop->first ? '' : 'border-t border-dark-200' }} hover:text-primary-300">
                    <span class="text-white text-sm">{{ $shelf->name }}</span>
                    <span class="text-xs text-gray-400">{{ $number($shelf->products_count) }} livre(s) · {{ $number($shelf->subcategories_count) }} genre(s)</span>
                </a>
            @empty
                <p class="text-sm text-gray-400">Aucun rayon.</p>
            @endforelse
        </section>
    </div>
</div>
@endsection
