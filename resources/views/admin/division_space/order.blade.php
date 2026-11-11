@extends('admin.layouts.app')

@section('title', 'Commande ' . $order->order_number)
@section('header', 'Ma rubrique')

@section('content')
@php extract(\App\Support\DivisionPresenter::helpers()) @endphp
<div class="p-6 space-y-6">
    @include('admin.division_space._header', ['title' => 'Commande ' . $order->order_number])

    <div class="grid md:grid-cols-4 gap-4">
        <div class="bg-dark-100 rounded-xl p-4"><p class="text-xs text-gray-400">Date</p><p class="text-white">{{ $order->created_at->displayTz()->format('d/m/Y H:i') }}</p></div>
        <div class="bg-dark-100 rounded-xl p-4"><p class="text-xs text-gray-400">Client</p><p class="text-white">{{ $clientName($order->user) }}</p></div>
        <div class="bg-dark-100 rounded-xl p-4"><p class="text-xs text-gray-400">Statut</p><p class="text-white">{{ $orderStatus($order->status) }}</p></div>
        <div class="bg-dark-100 rounded-xl p-4"><p class="text-xs text-gray-400">Réglée</p><p class="text-white">{{ $order->settled_at ? $order->settled_at->displayTz()->format('d/m/Y') : 'Pas encore' }}{{ $order->refunded_at ? ' · remboursée' : '' }}</p></div>
    </div>

    <div class="bg-dark-100 rounded-xl shadow-lg overflow-x-auto">
        <table class="w-full text-sm">
            <thead><tr class="text-left text-gray-400 border-b border-dark-200">
                <th class="px-4 py-3">Article</th><th class="px-4 py-3 text-right">Quantité</th><th class="px-4 py-3 text-right">Prix acheteur</th>
                <th class="px-4 py-3 text-right">Commission ASSO</th><th class="px-4 py-3 text-right">Votre commission</th>
            </tr></thead>
            <tbody>
                @foreach($items as $item)
                    @php $c = $lineCommissions->get($item->id); @endphp
                    <tr class="border-b border-dark-200">
                        <td class="px-4 py-3 text-white">
                            {{ $item->product_name ?? $item->product?->name ?? 'Produit' }}
                            @if($item->variant_attributes)<div class="text-xs text-gray-400">{{ collect($item->variant_attributes)->map(fn ($v, $k) => "{$k} : {$v}")->implode(' · ') }}</div>@endif
                        </td>
                        <td class="px-4 py-3 text-right text-gray-300">{{ $item->quantity }}</td>
                        <td class="px-4 py-3 text-right text-gray-200">{{ $money($item->total_price) }}</td>
                        <td class="px-4 py-3 text-right text-gray-300">{{ $money(\App\Services\DivisionCommissionService::itemBase($item)) }}</td>
                        <td class="px-4 py-3 text-right">
                            @if($c)
                                <span class="text-white">{{ $money($c->amount) }}</span>
                                <span class="block text-xs text-gray-400">{{ $c->statusLabel() }}</span>
                            @else
                                <span class="text-gray-500">—</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @if($otherLines > 0)
        <p class="text-xs text-gray-500">{{ $otherLines }} autre(s) article(s) d'autres rubriques dans cette commande, non affiché(s).</p>
    @endif
    <a href="{{ route('admin.division.orders.index', $query) }}" class="inline-block text-sm text-gray-400 hover:text-white"><i class="fas fa-arrow-left mr-1"></i>Toutes les commandes</a>
</div>
@endsection
