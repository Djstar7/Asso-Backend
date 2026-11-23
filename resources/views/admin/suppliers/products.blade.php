@extends('admin.layouts.app')

@section('content')
<div class="p-6">
    <div class="mb-4">
        <h1 class="text-2xl font-bold text-white">Fournisseurs</h1>
        <p class="text-gray-400">Produits CJ importés et leur fiche ASSO. Une fiche importée arrive inactive : relisez-la (nom, description, photos, prix) puis publiez-la.</p>
    </div>
    @include('admin.suppliers._tabs')

    <div class="flex flex-wrap gap-2 mb-4 text-xs">
        <a href="{{ route('admin.suppliers.cj.products') }}" class="px-2 py-1 rounded {{ ! request('status') && ! request('published') ? 'bg-primary-600 text-white' : 'bg-dark-200 text-gray-300' }}">Tous ({{ array_sum($counts) }})</a>
        <a href="{{ route('admin.suppliers.cj.products', ['published' => 'no']) }}" class="px-2 py-1 rounded {{ request('published') === 'no' ? 'bg-primary-600 text-white' : 'bg-dark-200 text-gray-300' }}">À publier</a>
        @foreach(\App\Models\SupplierProduct::STATUS_LABELS as $status => $label)
            @if(($counts[$status] ?? 0) > 0)
                <a href="{{ route('admin.suppliers.cj.products', ['status' => $status]) }}" class="px-2 py-1 rounded {{ request('status') === $status ? 'bg-primary-600 text-white' : 'bg-dark-200 text-gray-300' }}">{{ $label }} ({{ $counts[$status] }})</a>
            @endif
        @endforeach
    </div>

    <div class="bg-dark-100 rounded-xl shadow-lg border border-dark-200 p-6">
        <form method="GET" class="flex gap-2 mb-4">
            @if(request('status'))<input type="hidden" name="status" value="{{ request('status') }}">@endif
            <input name="q" value="{{ request('q') }}" placeholder="Nom, identifiant ou SKU CJ" class="px-3 py-2 bg-dark-200 rounded text-white text-sm w-80">
            <button class="px-3 py-2 text-sm bg-dark-200 text-white rounded-lg"><i class="fas fa-magnifying-glass"></i></button>
        </form>

        @if($products->isEmpty())
            <p class="text-sm text-gray-400">Aucun produit. Importez-en depuis l'onglet « Catalogue CJ » ou avec <code>php artisan cj:import</code>.</p>
        @else
            <form id="publish-form" action="{{ route('admin.suppliers.cj.publish') }}" method="POST">
                @csrf
            </form>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-left text-xs uppercase text-gray-500">
                        <tr>
                            <th class="py-2 pr-2"></th>
                            <th class="py-2 pr-4">Produit</th>
                            <th class="py-2 pr-4">Statut</th>
                            <th class="py-2 pr-4">Coût CJ + transport</th>
                            <th class="py-2 pr-4">Prix ASSO</th>
                            <th class="py-2 pr-4">Fiche ASSO</th>
                            <th class="py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-dark-200">
                        @foreach($products as $row)
                            @php($product = $row->product)
                            <tr class="align-top">
                                <td class="py-2 pr-2">
                                    @if($row->status === 'imported' && $product && $product->status !== 'active')
                                        <input type="checkbox" name="ids[]" value="{{ $row->id }}" form="publish-form" class="rounded bg-dark-200">
                                    @endif
                                </td>
                                <td class="py-2 pr-4">
                                    <p class="text-gray-200">{{ \Illuminate\Support\Str::limit($row->name, 80) }}</p>
                                    <p class="text-xs text-gray-500">{{ $row->external_sku ?? $row->external_id }}</p>
                                </td>
                                <td class="py-2 pr-4">
                                    <span class="text-xs px-2 py-1 rounded {{ $row->status === 'imported' ? 'bg-green-900/40 text-green-300' : ($row->status === 'pending' ? 'bg-dark-200 text-gray-300' : 'bg-yellow-900/40 text-yellow-300') }}">{{ $row->statusLabel() }}</span>
                                    @if($row->last_error)
                                        <p class="text-xs text-red-300 mt-1 max-w-xs break-words">{{ \Illuminate\Support\Str::limit($row->last_error, 200) }}</p>
                                    @endif
                                </td>
                                <td class="py-2 pr-4 text-gray-300">
                                    @if($row->cost_usd !== null)
                                        {{ number_format((float) $row->cost_usd, 2, ',', ' ') }} + {{ number_format((float) $row->freight_usd, 2, ',', ' ') }} USD
                                        <p class="text-xs text-gray-500">{{ $row->logistic_name }} · {{ $row->logistic_aging }} j</p>
                                    @else - @endif
                                </td>
                                <td class="py-2 pr-4 text-white">{{ $product ? number_format((float) $product->price, 0, ',', ' ').' FCFA' : '-' }}</td>
                                <td class="py-2 pr-4">
                                    @if($product)
                                        <a href="{{ route('admin.products.edit', $product) }}" class="text-primary-400 hover:text-primary-300">#{{ $product->id }}</a>
                                        <span class="text-xs {{ $product->status === 'active' ? 'text-green-300' : 'text-gray-400' }}">{{ $product->status === 'active' ? 'en ligne' : 'inactive' }}</span>
                                    @else - @endif
                                </td>
                                <td class="py-2">
                                    <form action="{{ route('admin.suppliers.cj.retry', $row) }}" method="POST">
                                        @csrf
                                        <button class="text-xs text-gray-300 hover:text-white" title="Réimporter depuis CJ"><i class="fas fa-rotate mr-1"></i>Réimporter</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="flex flex-wrap items-center justify-between gap-4 mt-4">
                <button type="submit" form="publish-form" class="px-4 py-2 text-sm bg-gradient-to-r from-primary-500 to-primary-600 text-white rounded-lg"><i class="fas fa-eye mr-1"></i>Publier la sélection</button>
                <div>{{ $products->links() }}</div>
            </div>
        @endif
    </div>
</div>
@endsection
