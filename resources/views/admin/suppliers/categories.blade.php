@extends('admin.layouts.app')

@section('content')
<div class="p-6">
    <div class="mb-4">
        <h1 class="text-2xl font-bold text-white">Fournisseurs</h1>
        <p class="text-gray-400">Reliez chaque catégorie CJ à une catégorie ASSO : les produits de cette catégorie y seront rangés à l'import.</p>
    </div>
    @include('admin.suppliers._tabs')

    <div class="bg-dark-100 rounded-xl shadow-lg border border-dark-200 p-6">
        <div class="flex flex-wrap items-end justify-between gap-4 mb-4">
            <form method="GET" class="flex flex-wrap gap-2 items-end">
                <div>
                    <label class="block text-xs text-gray-400 mb-1" for="q">Rechercher</label>
                    <input id="q" name="q" value="{{ request('q') }}" placeholder="ex. Phone, Women's Clothing" class="px-3 py-2 bg-dark-200 rounded text-white text-sm">
                </div>
                <div>
                    <label class="block text-xs text-gray-400 mb-1" for="mapped">Correspondance</label>
                    <select id="mapped" name="mapped" class="px-3 py-2 bg-dark-200 rounded text-white text-sm">
                        <option value="">Toutes</option>
                        <option value="no" @selected(request('mapped') === 'no')>À relier</option>
                        <option value="yes" @selected(request('mapped') === 'yes')>Reliées</option>
                    </select>
                </div>
                <button class="px-3 py-2 text-sm bg-dark-200 text-white rounded-lg"><i class="fas fa-filter mr-1"></i>Filtrer</button>
            </form>
            <div class="text-right">
                <p class="text-sm text-gray-300 mb-2">{{ $mappedCount }} / {{ $totalCount }} catégories reliées</p>
                <form action="{{ route('admin.suppliers.cj.categories.sync') }}" method="POST">
                    @csrf
                    <button type="submit" @disabled(! $cj->isConnected()) class="px-3 py-2 text-sm bg-gradient-to-r from-primary-500 to-primary-600 text-white rounded-lg disabled:opacity-50">
                        <i class="fas fa-rotate mr-1"></i>Récupérer les catégories CJ
                    </button>
                </form>
            </div>
        </div>

        @if($totalCount === 0)
            <p class="text-sm text-gray-400">Aucune catégorie : cliquez sur « Récupérer les catégories CJ » (connexion CJ requise).</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-left text-xs uppercase text-gray-500">
                        <tr><th class="py-2 pr-4">Catégorie CJ</th><th class="py-2 pr-4 w-96">Catégorie ASSO</th><th class="py-2"></th></tr>
                    </thead>
                    <tbody class="divide-y divide-dark-200">
                        @foreach($categories as $category)
                            <tr>
                                <td class="py-2 pr-4 text-gray-200">{{ $category->path }}</td>
                                <td class="py-2 pr-4" colspan="2">
                                    <form action="{{ route('admin.suppliers.cj.categories.map', $category) }}" method="POST" class="flex gap-2">
                                        @csrf
                                        @method('PUT')
                                        @include('admin.suppliers._target_select', [
                                            'selected' => $category->category_id ? $category->category_id.($category->subcategory_id ? ':'.$category->subcategory_id : '') : null,
                                        ])
                                        <button class="px-3 py-2 text-xs bg-dark-200 text-white rounded-lg whitespace-nowrap"><i class="fas fa-check mr-1"></i>Relier</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $categories->links() }}</div>
        @endif
    </div>
</div>
@endsection
