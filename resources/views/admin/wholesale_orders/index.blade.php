@extends('admin.layouts.app')

@section('content')
@php
    use App\Support\WholesaleOrderStage;
    $fcfa = fn ($v) => number_format((float) $v, 0, ',', ' ') . ' FCFA';
    $tabs = ['all' => 'Toutes'] + collect(WholesaleOrderStage::STAGES)->map(fn ($s) => $s[0])->all();
@endphp
<div class="p-6">
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-white">Commandes en gros</h1>
        <p class="text-gray-400">Chine, Dubaï, Turquie → entrepôt ASSO de Douala → SOLEX jusqu'au client. ASSO valide chaque commande, l'expédie, enregistre son arrivée à Douala, puis SOLEX livre.</p>
    </div>

    @include('admin.wholesale_orders._flash')

    <!-- Encaissé (commandes payées, hors annulées) -->
    <div class="grid grid-cols-2 lg:grid-cols-5 gap-4 mb-6">
        @foreach([
            ['Commandes payées', number_format($totals['orders'], 0, ',', ' '), 'fa-receipt'],
            ['Produits', $fcfa($totals['products']), 'fa-box'],
            ['Import → Douala', $fcfa($totals['import']), 'fa-ship'],
            ['Livraison SOLEX', $fcfa($totals['local']), 'fa-truck'],
            ['Total encaissé', $fcfa($totals['total']), 'fa-coins'],
        ] as [$label, $value, $icon])
            <div class="bg-dark-100 rounded-xl border border-dark-200 p-4">
                <p class="text-xs uppercase tracking-wider text-gray-500"><i class="fas {{ $icon }} mr-1"></i>{{ $label }}</p>
                <p class="text-lg font-bold text-white mt-1">{{ $value }}</p>
            </div>
        @endforeach
    </div>

    <!-- Étapes -->
    <div class="flex flex-wrap gap-2 mb-4">
        @foreach($tabs as $value => $label)
            @php $active = $stage === $value; @endphp
            <a href="{{ route('admin.wholesale-orders.index', array_filter(['stage' => $value, 'country' => request('country'), 'search' => request('search')])) }}"
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
        <select name="country" onchange="this.form.submit()"
                class="px-3 py-2 bg-dark-100 border border-dark-200 rounded-lg text-white text-sm">
            <option value="">Tous les pays</option>
            @foreach($countries as $code => $country)
                <option value="{{ $code }}" @selected(strtoupper((string) request('country')) === $code)>{{ $country->flag }} {{ $country->name }}</option>
            @endforeach
        </select>
        <input type="text" name="search" value="{{ request('search') }}" placeholder="N° commande, client, téléphone, n° de suivi"
               class="flex-1 min-w-[16rem] px-3 py-2 bg-dark-100 border border-dark-200 rounded-lg text-white text-sm">
        <button class="px-4 py-2 bg-primary-500 text-white rounded-lg text-sm"><i class="fas fa-search"></i></button>
    </form>

    <div class="bg-dark-100 rounded-xl shadow-lg border border-dark-200 overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-dark-200 text-gray-400 text-left">
                <tr>
                    <th class="px-4 py-3">Commande</th>
                    <th class="px-4 py-3">Client</th>
                    <th class="px-4 py-3">Articles</th>
                    <th class="px-4 py-3">Acheminement</th>
                    <th class="px-4 py-3 text-right">Montant</th>
                    <th class="px-4 py-3">Étape</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-dark-200 text-gray-300">
                @forelse($orders as $order)
                    @php
                        $country = $countries->get($order->import_country_code);
                        $first = $order->items->first();
                        $orderStage = WholesaleOrderStage::of($order);
                    @endphp
                    <tr class="hover:bg-dark-50 transition-colors">
                        <td class="px-4 py-3">
                            <a href="{{ route('admin.wholesale-orders.show', $order) }}" class="text-white font-medium hover:text-primary-400">#{{ $order->order_number }}</a>
                            <div class="text-xs text-gray-500">{{ $order->created_at->format('d/m/Y H:i') }}</div>
                        </td>
                        <td class="px-4 py-3">
                            {{ $order->user?->name ?? '—' }}
                            <div class="text-xs text-gray-500">{{ $order->customer_phone ?: $order->user?->phone }}</div>
                        </td>
                        <td class="px-4 py-3">
                            <span class="text-lg mr-1">{{ $country?->flag }}</span>{{ $first?->product?->name ?? 'Produit supprimé' }}
                            <div class="text-xs text-gray-500">
                                {{ $order->items->sum('quantity') }} × {{ $first?->tier_label }}
                                @if($order->items_count > 1) · {{ $order->items_count }} lignes @endif
                            </div>
                        </td>
                        <td class="px-4 py-3">
                            {{ \App\Models\ImportShippingOption::MODE_LABELS[$order->shipping_mode] ?? $order->shipping_mode }} → Douala
                            <div class="text-xs text-gray-500">
                                {{ $order->delivery_breakdown['company_name'] ?? $order->deliveryCompany?->name ?? 'Sans livraison locale' }}
                                @if($order->delivery_breakdown['route_label'] ?? null) · {{ $order->delivery_breakdown['route_label'] }} @endif
                            </div>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <div class="text-white font-semibold">{{ $fcfa($order->total) }}</div>
                            <div class="text-xs {{ $order->payment_status === 'paid' ? 'text-green-400' : ($order->payment_status === 'refunded' ? 'text-blue-300' : 'text-yellow-400') }}">
                                {{ ['paid' => 'Payée', 'pending' => 'Paiement en attente', 'failed' => 'Paiement échoué', 'refunded' => 'Remboursée'][$order->payment_status] ?? $order->payment_status }}
                            </div>
                        </td>
                        <td class="px-4 py-3">
                            <span class="px-2 py-1 rounded-full text-xs border whitespace-nowrap {{ WholesaleOrderStage::badge($orderStage) }}">{{ WholesaleOrderStage::label($orderStage) }}</span>
                        </td>
                        <td class="px-4 py-3 text-right whitespace-nowrap">
                            <a href="{{ route('admin.wholesale-orders.show', $order) }}" class="text-primary-400 hover:text-primary-300"><i class="fas fa-eye mr-1"></i> Gérer</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-8 text-center text-gray-500">Aucune commande en gros.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $orders->links() }}</div>
</div>
@endsection
