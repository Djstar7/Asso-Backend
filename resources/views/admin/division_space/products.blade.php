@extends('admin.layouts.app')

@section('title', 'Produits de ma rubrique')
@section('header', 'Ma rubrique')

@section('content')
@php extract(\App\Support\DivisionPresenter::helpers()) @endphp
<div class="p-6 space-y-6">
    @include('admin.division_space._header', ['title' => 'Produits'])

    <form method="GET" class="bg-dark-100 rounded-xl p-4 grid grid-cols-1 md:grid-cols-4 gap-3">
        @foreach($query as $k => $v)<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endforeach
        <input type="text" name="search" value="{{ request('search') }}" placeholder="Nom du produit" class="md:col-span-2 px-4 py-2 bg-dark-50 text-white border border-dark-200 rounded-lg">
        <select name="status" class="px-4 py-2 bg-dark-50 text-white border border-dark-200 rounded-lg">
            <option value="">Tous</option>
            <option value="active" @selected(request('status') === 'active')>En ligne</option>
            <option value="inactive" @selected(request('status') === 'inactive')>Masqués</option>
        </select>
        <button class="px-6 py-2 bg-primary-500 text-white rounded-lg hover:bg-primary-600"><i class="fas fa-search mr-2"></i>Filtrer</button>
    </form>

    <div class="bg-dark-100 rounded-xl shadow-lg overflow-x-auto">
        <table class="w-full text-sm">
            <thead><tr class="text-left text-gray-400 border-b border-dark-200">
                <th class="px-4 py-3">Produit</th><th class="px-4 py-3">Boutique</th><th class="px-4 py-3">Catégorie</th>
                <th class="px-4 py-3 text-right">Prix vendeur</th><th class="px-4 py-3 text-right">Stock</th>
                <th class="px-4 py-3 text-right">Vues</th><th class="px-4 py-3 text-right">Vendus</th><th class="px-4 py-3">Statut</th>
            </tr></thead>
            <tbody>
                @forelse($products as $product)
                    <tr class="border-b border-dark-200">
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-3">
                                @if($product->primaryImage)
                                    <img src="{{ media_url($product->primaryImage->image_path) }}" alt="" class="w-10 h-10 rounded object-cover">
                                @else
                                    <div class="w-10 h-10 rounded bg-dark-200 flex items-center justify-center"><i class="fas fa-box text-gray-500"></i></div>
                                @endif
                                <span class="text-white">{{ $product->name }}</span>
                            </div>
                        </td>
                        <td class="px-4 py-3 text-gray-300">{{ $product->shop?->name ?? '—' }}</td>
                        <td class="px-4 py-3 text-gray-300">{{ $product->category?->name }}</td>
                        <td class="px-4 py-3 text-right text-gray-200">{{ number_format((float) $product->price, 0, ',', ' ') }} {{ $product->currency ?: 'XAF' }}</td>
                        <td class="px-4 py-3 text-right text-gray-300">{{ $product->stock ?? '—' }}</td>
                        <td class="px-4 py-3 text-right text-gray-300">{{ (int) $product->views }}</td>
                        <td class="px-4 py-3 text-right text-gray-300">{{ (int) $product->sold }}</td>
                        <td class="px-4 py-3"><span class="px-2 py-0.5 text-xs rounded-full {{ $product->status === 'active' ? 'bg-emerald-500/20 text-emerald-300' : 'bg-gray-500/20 text-gray-300' }}">{{ $product->status === 'active' ? 'En ligne' : 'Masqué' }}</span></td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-4 py-10 text-center text-gray-500">Aucun produit dans votre rubrique.</td></tr>
                @endforelse
            </tbody>
        </table>
        <div class="p-4">{{ $products->links() }}</div>
    </div>
</div>
@endsection
