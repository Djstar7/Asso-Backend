@extends('admin.layouts.app')

@section('title', __('admin_nav.shipments.title', [], auth()->user()->preferredLocale()))

@section('content')
@php($navigationLocale = auth()->user()->preferredLocale())
<div class="p-6">
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-white">{{ __('admin_nav.shipments.title', [], $navigationLocale) }}</h1>
        <p class="text-gray-400">{{ __('admin_nav.shipments.description', [], $navigationLocale) }}</p>
    </div>

    <nav class="flex flex-wrap gap-2 mb-4" aria-label="{{ __('admin_nav.shipments.scope', [], $navigationLocale) }}">
        <a href="{{ route('admin.shipments.index', array_filter(['scope' => 'all', 'status' => $status, 'search' => request('search')])) }}"
           @if($scope !== 'export') aria-current="page" @endif
           class="px-3 py-2 rounded-lg text-sm {{ $scope !== 'export' ? 'bg-primary-500 text-white' : 'bg-dark-200 text-gray-300 hover:text-white' }}">{{ __('admin_nav.shipments.all', [], $navigationLocale) }}</a>
        <a href="{{ route('admin.shipments.index', array_filter(['scope' => 'export', 'status' => $status, 'search' => request('search')])) }}"
           @if($scope === 'export') aria-current="page" @endif
           class="px-3 py-2 rounded-lg text-sm {{ $scope === 'export' ? 'bg-primary-500 text-white' : 'bg-dark-200 text-gray-300 hover:text-white' }}">{{ __('admin_nav.shipments.exports', [], $navigationLocale) }}</a>
    </nav>
    @if($scope === 'export')
        <p class="mb-4 text-sm text-gray-400">{{ __('admin_nav.shipments.export_note', [], $navigationLocale) }}</p>
    @endif

    <form method="GET" class="flex flex-wrap gap-3 mb-4">
        <select name="status" aria-label="{{ __('admin_nav.shipments.status', [], $navigationLocale) }}" onchange="this.form.submit()"
                class="px-3 py-2 bg-dark-100 border border-dark-200 rounded-lg text-white text-sm">
            @foreach($statusOptions as $value => $label)
                <option value="{{ $value }}" @selected($status === $value)>{{ $label }}</option>
            @endforeach
        </select>
        <input type="hidden" name="scope" value="{{ $scope }}">
        <input type="text" name="search" value="{{ request('search') }}" placeholder="{{ __('admin_nav.shipments.search', [], $navigationLocale) }}" aria-label="{{ __('admin_nav.shipments.search', [], $navigationLocale) }}"
               class="px-3 py-2 bg-dark-100 border border-dark-200 rounded-lg text-white text-sm">
        <button aria-label="{{ __('admin_nav.shipments.filter', [], $navigationLocale) }}" class="px-4 py-2 bg-primary-500 text-white rounded-lg text-sm"><i class="fas fa-search"></i></button>
    </form>

    <div class="bg-dark-100 rounded-xl shadow-lg border border-dark-200 overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-dark-200 text-gray-400 text-left">
                <tr>
                    <th class="px-4 py-3">Commande</th>
                    <th class="px-4 py-3">Acheteur</th>
                    <th class="px-4 py-3">Transporteur / trajet</th>
                    <th class="px-4 py-3">Paiement</th>
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
                        <td class="px-4 py-3">{{ ['paid' => 'Payée', 'pending' => 'En attente', 'failed' => 'Échoué', 'refunded' => 'Remboursée'][$order->payment_status] ?? $order->payment_status }}</td>
                        <td class="px-4 py-3">{{ $order->shipping_weight_kg ? rtrim(rtrim(number_format($order->shipping_weight_kg, 3, ',', ''), '0'), ',') . ' kg' : '—' }}</td>
                        <td class="px-4 py-3 font-mono">{{ $order->carrier_tracking_number ?? '—' }}</td>
                        <td class="px-4 py-3">
                            @if($order->payment_status !== 'paid')
                                <span class="text-yellow-300">Paiement à confirmer</span>
                            @elseif($order->status === 'pending')
                                <span class="px-2 py-1 rounded text-xs bg-yellow-900/30 text-yellow-400">En attente de validation du vendeur</span>
                            @elseif($order->tracking_status && !in_array($order->tracking_status, ['pending', 'confirmed', 'preparing'], true))
                                {{ \App\Services\OrderTrackingService::stepLabel($order->tracking_status) }}
                            @elseif($order->isInternationalExport() && in_array($order->status, ['confirmed', 'preparing'], true))
                                <span class="text-blue-300">À expédier · collecte Solex à programmer</span>
                            @elseif(in_array($order->status, ['confirmed', 'preparing'], true) && !$order->carrier_tracking_number)
                                <span class="px-2 py-1 rounded text-xs bg-blue-900/30 text-blue-300">Validée : à remettre au transporteur</span>
                            @else
                                {{ \App\Services\OrderTrackingService::STEPS[$order->tracking_status] ?? $order->status }}
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right">
                            <a href="{{ route('admin.shipments.show', $order) }}" class="text-primary-400 hover:text-primary-300"><i class="fas fa-eye mr-1"></i> Voir le suivi</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-4 py-6 text-center text-gray-500">Aucune expédition.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $orders->links() }}</div>
</div>
@endsection
