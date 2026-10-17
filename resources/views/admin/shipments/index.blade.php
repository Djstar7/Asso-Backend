@extends('admin.layouts.app')

@section('content')
<div class="p-6">
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-white">Expéditions transporteur</h1>
        <p class="text-gray-400">Commandes confiées à SOLEX, DHL, FedEx… Le vendeur valide la commande, remet le colis au transporteur avec le numéro de suivi, puis les étapes s'enchaînent jusqu'à la réception par l'acheteur.</p>
    </div>

    <form method="GET" class="flex flex-wrap gap-3 mb-4">
        <select name="status" onchange="this.form.submit()"
                class="px-3 py-2 bg-dark-100 border border-dark-200 rounded-lg text-white text-sm">
            @foreach(['in_progress' => 'En cours', 'awaiting_seller' => 'En attente du vendeur', 'shipped' => 'Expédiées', 'delivered' => 'Livrées', 'cancelled' => 'Annulées', 'all' => 'Toutes'] as $value => $label)
                <option value="{{ $value }}" @selected($status === $value)>{{ $label }}</option>
            @endforeach
        </select>
        <input type="text" name="search" value="{{ request('search') }}" placeholder="N° commande ou n° de suivi"
               class="px-3 py-2 bg-dark-100 border border-dark-200 rounded-lg text-white text-sm">
        <button class="px-4 py-2 bg-primary-500 text-white rounded-lg text-sm"><i class="fas fa-search"></i></button>
    </form>

    <div class="bg-dark-100 rounded-xl shadow-lg border border-dark-200 overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-dark-200 text-gray-400 text-left">
                <tr>
                    <th class="px-4 py-3">Commande</th>
                    <th class="px-4 py-3">Acheteur</th>
                    <th class="px-4 py-3">Transporteur / trajet</th>
                    <th class="px-4 py-3">Poids</th>
                    <th class="px-4 py-3">N° de suivi</th>
                    <th class="px-4 py-3">Dernière étape</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-dark-200 text-gray-300">
                @forelse($orders as $order)
                    <tr>
                        <td class="px-4 py-3">
                            <div class="text-white font-medium">#{{ $order->order_number }}</div>
                            <div class="text-xs text-gray-500">{{ $order->created_at->displayTz()->format('d/m/Y H:i') }}</div>
                        </td>
                        <td class="px-4 py-3">{{ $order->user?->name }}<div class="text-xs text-gray-500">{{ $order->customer_phone }}</div></td>
                        <td class="px-4 py-3">
                            {{ $order->delivery_breakdown['company_name'] ?? $order->deliveryCompany?->name ?? ($order->is_wholesale ? 'Import' : '—') }}
                            <div class="text-xs text-gray-500">{{ $order->delivery_breakdown['route_label'] ?? '' }}</div>
                        </td>
                        <td class="px-4 py-3">{{ $order->shipping_weight_kg ? rtrim(rtrim(number_format($order->shipping_weight_kg, 3, ',', ''), '0'), ',') . ' kg' : '—' }}</td>
                        <td class="px-4 py-3 font-mono">{{ $order->carrier_tracking_number ?? '—' }}</td>
                        <td class="px-4 py-3">
                            @if($order->status === 'pending')
                                <span class="px-2 py-1 rounded text-xs bg-yellow-900/30 text-yellow-400">En attente de validation du vendeur</span>
                            @elseif(in_array($order->status, ['confirmed', 'preparing'], true) && !$order->carrier_tracking_number)
                                <span class="px-2 py-1 rounded text-xs bg-blue-900/30 text-blue-300">Validée : à remettre au transporteur</span>
                            @else
                                {{ \App\Services\OrderTrackingService::STEPS[$order->tracking_status] ?? $order->status }}
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right">
                            <a href="{{ route('admin.shipments.show', $order) }}" class="text-primary-400 hover:text-primary-300"><i class="fas fa-eye mr-1"></i> Suivi</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-6 text-center text-gray-500">Aucune expédition.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $orders->links() }}</div>
</div>
@endsection
