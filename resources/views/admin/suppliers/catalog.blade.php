@extends('admin.layouts.app')

@section('content')
<div class="p-6">
    <div class="mb-4">
        <h1 class="text-2xl font-bold text-white">Fournisseurs</h1>
        <p class="text-gray-400">Cherchez dans le catalogue CJ, cochez les produits puis importez-les. Les prix affichés ici sont les prix d'achat CJ en dollars, hors transport.</p>
    </div>
    @include('admin.suppliers._tabs')

    <div class="bg-dark-100 rounded-xl shadow-lg border border-dark-200 p-6 mb-6">
        <form method="GET" class="grid grid-cols-1 md:grid-cols-5 gap-3 items-end">
            <div class="md:col-span-2">
                <label class="block text-xs text-gray-400 mb-1" for="keyword">Mot-clé (anglais)</label>
                <input id="keyword" name="keyword" value="{{ $filters['keyword'] ?? '' }}" placeholder="ex. phone case, hoodie" class="w-full px-3 py-2 bg-dark-200 rounded text-white text-sm">
            </div>
            <div>
                <label class="block text-xs text-gray-400 mb-1" for="category">Catégorie CJ</label>
                <select id="category" name="category" class="w-full px-3 py-2 bg-dark-200 rounded text-white text-sm">
                    <option value="">Toutes</option>
                    @foreach($cjCategories as $c)
                        <option value="{{ $c->external_id }}" @selected(($filters['category'] ?? null) === $c->external_id)>{{ $c->path }}</option>
                    @endforeach
                </select>
            </div>
            <div class="grid grid-cols-2 gap-2">
                <div>
                    <label class="block text-xs text-gray-400 mb-1" for="min_price">Prix min (USD)</label>
                    <input id="min_price" name="min_price" type="number" min="0" step="0.01" value="{{ $filters['min_price'] ?? '' }}" class="w-full px-3 py-2 bg-dark-200 rounded text-white text-sm">
                </div>
                <div>
                    <label class="block text-xs text-gray-400 mb-1" for="max_price">Prix max (USD)</label>
                    <input id="max_price" name="max_price" type="number" min="0" step="0.01" value="{{ $filters['max_price'] ?? '' }}" class="w-full px-3 py-2 bg-dark-200 rounded text-white text-sm">
                </div>
            </div>
            <button class="px-4 py-2 text-sm bg-gradient-to-r from-primary-500 to-primary-600 text-white rounded-lg"><i class="fas fa-magnifying-glass mr-1"></i>Rechercher</button>
        </form>
        @if($error)
            <p class="mt-4 text-sm text-red-300"><i class="fas fa-exclamation-triangle mr-1"></i>{{ $error }}</p>
        @endif
    </div>

    @if($result)
        <form action="{{ route('admin.suppliers.cj.import') }}" method="POST" class="bg-dark-100 rounded-xl shadow-lg border border-dark-200 p-6">
            @csrf
            <div class="flex flex-wrap items-end justify-between gap-4 mb-4">
                <p class="text-sm text-gray-300">{{ number_format($result['total'], 0, ',', ' ') }} résultat(s) — page {{ $result['page'] }} / {{ max(1, $result['pages']) }}@if($result['total'] >= 6000) <span class="text-yellow-300">(CJ s'arrête à 6000 : affinez la recherche)</span>@endif</p>
                <div class="flex flex-wrap gap-2 items-end">
                    <div class="w-80">
                        <label class="block text-xs text-gray-400 mb-1">Catégorie ASSO si la catégorie CJ n'est pas reliée</label>
                        @include('admin.suppliers._target_select', ['placeholder' => '— Utiliser la correspondance —'])
                    </div>
                    <button type="submit" class="px-4 py-2 text-sm bg-gradient-to-r from-primary-500 to-primary-600 text-white rounded-lg"><i class="fas fa-file-import mr-1"></i>Importer la sélection</button>
                </div>
            </div>
            @error('pids')<p class="text-sm text-red-300 mb-2">{{ $message }}</p>@enderror
            @error('target')<p class="text-sm text-red-300 mb-2">{{ $message }}</p>@enderror

            <div class="grid grid-cols-2 md:grid-cols-4 xl:grid-cols-5 gap-4">
                @foreach($result['items'] as $item)
                    @php($status = $known[$item['id']] ?? null)
                    <label class="block bg-dark-50 rounded-lg p-3 cursor-pointer hover:ring-1 hover:ring-primary-500">
                        <div class="flex items-start gap-2 mb-2">
                            <input type="checkbox" name="pids[]" value="{{ $item['id'] }}" class="mt-1 rounded bg-dark-200">
                            <span class="text-xs text-gray-200 line-clamp-3">{{ $item['name'] }}</span>
                        </div>
                        @if($item['image'])
                            <img src="{{ $item['image'] }}" alt="" loading="lazy" referrerpolicy="no-referrer" class="w-full aspect-square object-cover rounded mb-2">
                        @endif
                        <p class="text-sm font-semibold text-white">{{ $item['price'] }} USD</p>
                        <p class="text-xs text-gray-400">{{ $item['category'] }}</p>
                        <p class="text-xs text-gray-500">Stock : {{ $item['inventory'] ?? '?' }}</p>
                        @if($status)
                            <p class="text-xs mt-1 {{ $status === 'imported' ? 'text-green-300' : 'text-yellow-300' }}">{{ \App\Models\SupplierProduct::STATUS_LABELS[$status] ?? $status }}</p>
                        @elseif($item['category_id'] && ! isset($mapped[$item['category_id']]))
                            <p class="text-xs mt-1 text-yellow-300">Catégorie CJ non reliée</p>
                        @endif
                    </label>
                @endforeach
            </div>

            <div class="flex justify-between mt-4 text-sm">
                @if($result['page'] > 1)
                    <a class="text-primary-400" href="{{ request()->fullUrlWithQuery(['page' => $result['page'] - 1]) }}"><i class="fas fa-arrow-left mr-1"></i>Page précédente</a>
                @else <span></span> @endif
                @if($result['page'] < $result['pages'])
                    <a class="text-primary-400" href="{{ request()->fullUrlWithQuery(['page' => $result['page'] + 1]) }}">Page suivante<i class="fas fa-arrow-right ml-1"></i></a>
                @endif
            </div>
        </form>
    @endif
</div>
@endsection
