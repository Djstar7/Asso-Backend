@extends('admin.layouts.app')

@section('content')
@php
    use App\Support\DepositOrderStage;
    $fcfa = fn ($v) => number_format((float) $v, 0, ',', ' ') . ' FCFA';
    $tabs = collect(DepositOrderStage::STAGES)->map(fn ($s) => $s[0])->all() + ['all' => 'Toutes'];
@endphp
<div class="p-6">
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-white">Commandes avec acompte</h1>
        <p class="text-gray-400">Produits sur commande : acompte payé à la commande. À la livraison ou au retrait, un employé ASSO vérifie la marchandise avec le client, puis valide pour débloquer le paiement du solde.</p>
    </div>

    @include('admin.wholesale_orders._flash')

    <div class="flex flex-wrap gap-2 mb-4">
        @foreach($tabs as $value => $label)
            @php $active = $stage === $value; @endphp
            <a href="{{ route('admin.deposit-orders.index', array_filter(['stage' => $value, 'search' => request('search')])) }}"
               class="px-3 py-1.5 rounded-full text-sm border transition-all {{ $active ? 'bg-primary-500 border-primary-500 text-white' : 'bg-dark-100 border-dark-200 text-gray-300 hover:border-primary-500' }}">
                {{ $label }}
                @if($value !== 'all')
                    <span class="ml-1 {{ $active ? 'text-white' : 'text-gray-500' }}">{{ $counts[$value] }}</span>
                @endif
            </a>
        @endforeach
    </div>

    <form method="GET" class="flex flex-wrap gap-3 mb-4">
        <input type="hidden" name="stage" value="{{ $stage }}">
        <input type="text" name="search" value="{{ request('search') }}" placeholder="N° commande, client, téléphone"
               class="flex-1 min-w-[16rem] px-3 py-2 bg-dark-100 border border-dark-200 rounded-lg text-white text-sm">
        <button class="px-4 py-2 bg-primary-500 text-white rounded-lg text-sm"><i class="fas fa-search"></i></button>
    </form>

    <div class="bg-dark-100 rounded-xl shadow-lg border border-dark-200 overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-dark-200 text-gray-400 text-left">
                <tr>
                    <th class="px-4 py-3">Commande</th>
                    <th class="px-4 py-3">Client</th>
                    <th class="px-4 py-3">Produit</th>
                    <th class="px-4 py-3">Récupération</th>
                    <th class="px-4 py-3 text-right">Acompte / Solde</th>
                    <th class="px-4 py-3">Étape</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-dark-200 text-gray-300">
                @forelse($orders as $order)
                    @php
                        $first = $order->items->first();
                        $orderStage = DepositOrderStage::of($order);
                    @endphp
                    <tr class="hover:bg-dark-50 transition-colors">
                        <td class="px-4 py-3">
                            <a href="{{ route('admin.deposit-orders.show', $order) }}" class="text-white font-medium hover:text-primary-400">#{{ $order->order_number }}</a>
                            <div class="text-xs text-gray-500">{{ $order->created_at->format('d/m/Y H:i') }}</div>
                        </td>
                        <td class="px-4 py-3">
                            {{ $order->user?->name ?? '—' }}
                            <div class="text-xs text-gray-500">{{ $order->customer_phone ?: $order->user?->phone }}</div>
                        </td>
                        <td class="px-4 py-3">
                            {{ $first?->product?->name ?? 'Produit supprimé' }}
                            @if($order->items_count > 1)<div class="text-xs text-gray-500">{{ $order->items_count }} lignes</div>@endif
                        </td>
                        <td class="px-4 py-3">
                            {{ $order->delivery_breakdown['delivery_option_label'] ?? ($order->isCarrierDelivery() ? 'Transporteur' : 'Livraison à domicile') }}
                            <div class="text-xs text-gray-500">{{ $order->delivery_breakdown['company_name'] ?? $order->deliveryCompany?->name }}</div>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <div class="text-white font-semibold">{{ $fcfa($order->deposit_amount) }}</div>
                            <div class="text-xs text-gray-500">solde {{ $fcfa($order->balance_amount) }}</div>
                        </td>
                        <td class="px-4 py-3">
                            <span class="px-2 py-1 rounded-full text-xs border whitespace-nowrap {{ DepositOrderStage::badge($orderStage) }}">{{ DepositOrderStage::label($orderStage) }}</span>
                        </td>
                        <td class="px-4 py-3 text-right whitespace-nowrap">
                            <a href="{{ route('admin.deposit-orders.show', $order) }}" class="text-primary-400 hover:text-primary-300"><i class="fas fa-eye mr-1"></i> Gérer</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-8 text-center text-gray-500">Aucune commande avec acompte à cette étape.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $orders->links() }}</div>
</div>
@endsection
