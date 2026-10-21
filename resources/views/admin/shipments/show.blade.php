@extends('admin.layouts.app')

@section('content')
@php
    $d = \App\Support\DeliveryPresenter::forOrder($order);
    $b = $d['breakdown'] ?? [];
    $fcfa = fn ($v) => number_format((float) $v, 0, ',', ' ') . ' F';
    $input = 'w-full px-3 py-2 bg-dark-50 border border-dark-200 rounded-lg text-white focus:border-primary-500 focus:outline-none text-sm';
@endphp
<div class="p-6">
    <div class="mb-6 flex items-center gap-3">
        <a href="{{ route('admin.shipments.index') }}" class="text-gray-400 hover:text-primary-500"><i class="fas fa-arrow-left"></i></a>
        <div>
            <h1 class="text-2xl font-bold text-white">Commande #{{ $order->order_number }}</h1>
            <p class="text-gray-400">{{ $d['company_name'] }} · {{ $d['route_label'] }} · statut : {{ $order->status }}</p>
        </div>
    </div>

    @if($errors->any())
        <div class="mb-6 p-4 bg-red-900/20 border-l-4 border-red-500 rounded">
            <ul class="text-red-300 text-sm list-disc list-inside">
                @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
            </ul>
        </div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2 space-y-6">
            <!-- Historique -->
            <div class="bg-dark-100 rounded-xl border border-dark-200 p-6">
                <h2 class="text-lg font-semibold text-white mb-4"><i class="fas fa-stream mr-2 text-primary-400"></i>Historique de livraison</h2>
                <ol class="relative border-l border-dark-300 ml-2">
                    @forelse($order->trackingEvents as $event)
                        <li class="mb-5 ml-5">
                            <span class="absolute -left-1.5 w-3 h-3 rounded-full bg-primary-500"></span>
                            <div class="text-white font-medium">{{ $event->label }} @if($event->location)<span class="text-gray-400">— {{ $event->location }}</span>@endif</div>
                            <div class="text-xs text-gray-500">{{ $event->occurred_at->displayTz()->format('d/m/Y H:i') }} · {{ $event->actor_type }}</div>
                            @if($event->note)<div class="text-sm text-gray-300 mt-1">{{ $event->note }}</div>@endif
                        </li>
                    @empty
                        <li class="ml-5 text-gray-500">Aucune étape enregistrée.</li>
                    @endforelse
                </ol>
            </div>

            @include('admin.shipments._tracking_actions')

        </div>

        <!-- Détail -->
        <div class="space-y-6">
            <div class="bg-dark-100 rounded-xl border border-dark-200 p-6 text-sm text-gray-300 space-y-2">
                <h2 class="text-lg font-semibold text-white mb-2">Livraison facturée</h2>
                <div class="flex justify-between"><span>Mode</span><span class="text-white text-right">{{ $d['service_mode_label'] }}</span></div>
                <div class="flex justify-between"><span>Délai</span><span class="text-white">{{ $d['lead_time'] ?? '—' }}</span></div>
                <div class="flex justify-between"><span>Poids</span><span class="text-white">{{ $d['weight_kg'] ? rtrim(rtrim(number_format($d['weight_kg'], 3, ',', ''), '0'), ',') . ' kg' : '—' }}</span></div>
                @if($b)
                    <div class="flex justify-between"><span>{{ $b['range_label'] }}</span><span class="text-white">{{ $fcfa($b['range_price']) }}</span></div>
                    @if($b['extra_kg'] > 0)
                        <div class="flex justify-between"><span>{{ $b['extra_kg'] }} kg suppl. × {{ $fcfa($b['extra_per_kg']) }}</span><span class="text-white">{{ $fcfa($b['extra_price']) }}</span></div>
                    @endif
                    @if($b['vat_amount'] > 0)
                        <div class="flex justify-between"><span>TVA {{ $b['vat_rate'] }} %</span><span class="text-white">{{ $fcfa($b['vat_amount']) }}</span></div>
                    @endif
                    @if($b['asso_commission'] > 0)
                        <div class="flex justify-between"><span>Commission ASSO</span><span class="text-white">{{ $fcfa($b['asso_commission']) }}</span></div>
                    @endif
                @endif
                <div class="flex justify-between border-t border-dark-200 pt-2 font-semibold"><span>Total livraison</span><span class="text-white">{{ $fcfa($order->delivery_fee) }}</span></div>
                @if($d['carrier_tracking_url'])
                    <a href="{{ $d['carrier_tracking_url'] }}" target="_blank" rel="noopener" class="block text-primary-400 underline mt-2">Suivre chez le transporteur</a>
                @endif
            </div>
            <div class="bg-dark-100 rounded-xl border border-dark-200 p-6 text-sm text-gray-300 space-y-1">
                <h2 class="text-lg font-semibold text-white mb-2">Destinataire</h2>
                <div>{{ $order->user?->name }}</div>
                <div>{{ $order->customer_phone }}</div>
                <div>{{ $order->delivery_address }}</div>
                @if($order->delivery_address_details)<div class="text-gray-400">{{ $order->delivery_address_details }}</div>@endif
                <h2 class="text-lg font-semibold text-white mt-4 mb-2">Articles</h2>
                @foreach($order->items as $item)
                    <div>{{ $item->quantity }} × {{ $item->product?->name }} <span class="text-gray-500">({{ $item->product?->weight ?: '?' }} kg · {{ $item->seller?->name }})</span></div>
                @endforeach
            </div>
        </div>
    </div>
</div>
@endsection
