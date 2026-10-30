@extends('admin.layouts.app')

@section('content')
@php
    $in = 'w-full px-3 py-2 bg-dark-50 border border-dark-200 rounded-lg text-white focus:border-primary-500 focus:outline-none';
    $fcfa = fn ($v) => number_format((float) $v, 0, ',', ' ');
@endphp
<div class="p-6">
    @include('admin.library._header', ['title' => 'Livres', 'subtitle' => 'Tous les livres en vente, avec leur fiche (auteur, ISBN, format).'])

    <form method="GET" class="bg-dark-100 rounded-xl border border-dark-200 p-4 mb-6 grid grid-cols-1 md:grid-cols-3 lg:grid-cols-7 gap-3">
        <input type="search" name="search" value="{{ request('search') }}" placeholder="Titre, auteur, éditeur, ISBN" class="{{ $in }} lg:col-span-2" aria-label="Rechercher">
        <select name="category_id" class="{{ $in }}" aria-label="Rayon">
            <option value="">Tous les rayons</option>
            @foreach($shelves as $shelf)
                <option value="{{ $shelf->id }}" @selected(request('category_id') == $shelf->id)>{{ $shelf->name }}</option>
            @endforeach
        </select>
        <select name="shop_id" class="{{ $in }}" aria-label="Boutique">
            <option value="">Toutes les boutiques</option>
            @foreach($shops as $shop)
                <option value="{{ $shop->id }}" @selected(request('shop_id') == $shop->id)>{{ $shop->name }}</option>
            @endforeach
        </select>
        <select name="language" class="{{ $in }}" aria-label="Langue">
            <option value="">Toutes langues</option>
            @foreach(\App\Models\ProductBook::LANGUAGES as $value)
                <option value="{{ $value }}" @selected(request('language') === $value)>{{ \App\Models\ProductBook::languageLabel($value, 'fr') }}</option>
            @endforeach
        </select>
        <select name="format" class="{{ $in }}" aria-label="Format">
            <option value="">Tous formats</option>
            @foreach(\App\Models\ProductBook::FORMATS as $value)
                <option value="{{ $value }}" @selected(request('format') === $value)>{{ \App\Models\ProductBook::formatLabel($value, 'fr') }}</option>
            @endforeach
        </select>
        <select name="issue" class="{{ $in }}" aria-label="À compléter">
            <option value="">Toutes les fiches</option>
            @foreach($issues as $value => $label)
                <option value="{{ $value }}" @selected(request('issue') === $value)>{{ $label }}</option>
            @endforeach
        </select>
        <div class="flex gap-2 lg:col-span-7 justify-end">
            @if(request()->hasAny(['search', 'category_id', 'shop_id', 'language', 'format', 'issue', 'status']))
                <a href="{{ route('admin.library.books.index') }}" class="px-4 py-2 text-gray-300 hover:text-white">Effacer</a>
            @endif
            <button class="px-4 py-2 bg-primary-500 text-white rounded-lg hover:bg-primary-600"><i class="fas fa-filter mr-2"></i>Filtrer</button>
        </div>
    </form>

    <div class="bg-dark-100 rounded-xl border border-dark-200 overflow-x-auto">
        <table class="min-w-full text-sm">
            <thead class="bg-dark-50 text-gray-400 text-left">
                <tr>
                    <th class="px-4 py-3">Livre</th>
                    <th class="px-4 py-3">Rayon</th>
                    <th class="px-4 py-3">Boutique</th>
                    <th class="px-4 py-3">Format</th>
                    <th class="px-4 py-3 text-right">Prix (FCFA)</th>
                    <th class="px-4 py-3 text-right">Stock</th>
                    <th class="px-4 py-3">Statut</th>
                    <th class="px-4 py-3"><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-dark-200">
                @forelse($books as $product)
                    <tr class="hover:bg-dark-50/50">
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-14 rounded bg-dark-50 overflow-hidden flex-shrink-0 flex items-center justify-center">
                                    @if($product->primaryImage)
                                        <img src="{{ media_url($product->primaryImage->image_path) }}" alt="" class="w-full h-full object-cover">
                                    @else
                                        <i class="fas fa-book text-gray-600"></i>
                                    @endif
                                </div>
                                <div class="min-w-0">
                                    <p class="text-white font-medium truncate max-w-xs">{{ $product->name }}</p>
                                    @if($product->book)
                                        <p class="text-xs text-primary-300 truncate">{{ $product->book->author }}</p>
                                        <p class="text-xs text-gray-500">{{ $product->book->isbn ? 'ISBN ' . $product->book->isbn : 'Sans ISBN' }}</p>
                                    @else
                                        <p class="text-xs text-amber-300"><i class="fas fa-triangle-exclamation mr-1"></i>Fiche livre à compléter</p>
                                    @endif
                                </div>
                            </div>
                        </td>
                        <td class="px-4 py-3 text-gray-300">
                            {{ $product->category?->name }}
                            @if($product->subcategory)<span class="block text-xs text-gray-500">{{ $product->subcategory->name }}</span>@endif
                        </td>
                        <td class="px-4 py-3 text-gray-300">{{ $product->shop?->name ?? '—' }}</td>
                        <td class="px-4 py-3 text-gray-300">
                            {{ \App\Models\ProductBook::formatLabel($product->book?->format, 'fr') ?? '—' }}
                            @if($product->book?->language)<span class="block text-xs text-gray-500">{{ \App\Models\ProductBook::languageLabel($product->book->language, 'fr') }}</span>@endif
                        </td>
                        <td class="px-4 py-3 text-right text-white tabular-nums">{{ $fcfa($product->price_xaf ?? $product->price) }}</td>
                        <td class="px-4 py-3 text-right tabular-nums {{ $product->stock > 0 ? 'text-white' : 'text-red-300' }}">{{ $product->stock }}</td>
                        <td class="px-4 py-3">
                            <span class="px-2 py-0.5 rounded text-xs {{ $product->status === 'active' ? 'bg-green-500/20 text-green-300' : 'bg-gray-500/20 text-gray-300' }}">
                                {{ $product->status === 'active' ? 'Visible' : 'Masqué' }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-right whitespace-nowrap">
                            <a href="{{ route('admin.products.edit', $product) }}" class="text-primary-300 hover:text-primary-200" title="Modifier">
                                <i class="fas fa-pen"></i><span class="sr-only">Modifier {{ $product->name }}</span>
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="px-4 py-10 text-center text-gray-400">
                            Aucun livre ne correspond.
                            <a href="{{ route('admin.products.create', ['library' => 1]) }}" class="text-primary-300 hover:underline">Ajouter un livre</a>
                            ou <a href="{{ route('admin.library.import.create') }}" class="text-primary-300 hover:underline">importer un fichier</a>.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $books->links() }}</div>
</div>
@endsection
