@extends('admin.layouts.app')

@section('content')
@php
    use App\Models\Order;
    use App\Support\DepositOrderStage;
    $fcfa = fn ($v) => number_format((float) $v, 0, ',', ' ') . ' FCFA';
    $verification = $order->verification_status;
    $open = $order->status !== 'cancelled' && $order->status !== 'delivered';
    $locked = $order->balance_status === Order::BALANCE_LOCKED;
    $canContact = $open && $locked && in_array($verification, [Order::VERIFICATION_PENDING, Order::VERIFICATION_TO_CONTACT, Order::VERIFICATION_ISSUE], true);
    $canValidate = $open && $locked && in_array($verification, [Order::VERIFICATION_TO_CONTACT, Order::VERIFICATION_CONTACTED, Order::VERIFICATION_ISSUE], true);
    $canReport = $open && $locked && in_array($verification, [Order::VERIFICATION_PENDING, Order::VERIFICATION_TO_CONTACT, Order::VERIFICATION_CONTACTED], true);
    $canClose = $open && !$order->settled_at && $order->balance_status !== Order::BALANCE_PAID && $order->payment_status === Order::PAYMENT_PAID;
    $depositCollected = $collected['wallet'] + $collected['direct'];
    $verificationLabels = [
        Order::VERIFICATION_PENDING => 'En attente de la présentation du produit',
        Order::VERIFICATION_TO_CONTACT => 'À contacter',
        Order::VERIFICATION_CONTACTED => 'Client contacté — vérification en cours',
        Order::VERIFICATION_VERIFIED => 'Vérification réussie',
        Order::VERIFICATION_ISSUE => 'Problème signalé',
    ];
    $balanceLabels = [
        Order::BALANCE_LOCKED => 'Bloqué (vérification à faire)',
        Order::BALANCE_UNLOCKED => 'Débloqué — en attente du paiement client',
        Order::BALANCE_PAID => 'Payé',
        Order::BALANCE_CANCELLED => 'Annulé',
    ];
@endphp
<div class="p-6 space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <a href="{{ route('admin.deposit-orders.index') }}" class="text-sm text-gray-400 hover:text-white"><i class="fas fa-arrow-left mr-1"></i> Commandes avec acompte</a>
            <h1 class="text-2xl font-bold text-white mt-1">Commande #{{ $order->order_number }}</h1>
            <p class="text-gray-400 text-sm">Passée le {{ $order->created_at->format('d/m/Y à H:i') }}</p>
        </div>
        <span class="px-3 py-1.5 rounded-full text-sm border {{ DepositOrderStage::badge($stage) }}">{{ DepositOrderStage::label($stage) }}</span>
    </div>

    @include('admin.wholesale_orders._flash')

    <div class="grid lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2 space-y-6">
            <!-- Montants -->
            <div class="grid grid-cols-3 gap-4">
                @foreach([
                    ['Prix total', $order->total, 'fa-tag'],
                    ['Acompte', $order->deposit_amount, 'fa-hand-holding-usd'],
                    ['Solde', $order->balance_amount, 'fa-coins'],
                ] as [$label, $value, $icon])
                    <div class="bg-dark-100 rounded-xl border border-dark-200 p-4">
                        <p class="text-xs uppercase tracking-wider text-gray-500"><i class="fas {{ $icon }} mr-1"></i>{{ $label }}</p>
                        <p class="text-lg font-bold text-white mt-1">{{ $fcfa($value) }}</p>
                    </div>
                @endforeach
            </div>

            <!-- Articles -->
            <div class="bg-dark-100 rounded-xl border border-dark-200 p-6">
                <h2 class="text-lg font-semibold text-white mb-4">Articles</h2>
                <div class="divide-y divide-dark-200">
                    @foreach($order->items as $item)
                        <div class="py-3 flex items-center gap-4">
                            @if($item->product?->primaryImage)
                                <img src="{{ media_url($item->product->primaryImage->image_path) }}" class="w-14 h-14 rounded-lg object-cover" alt="">
                            @endif
                            <div class="flex-1">
                                <p class="text-white">{{ $item->product?->name ?? 'Produit supprimé' }}</p>
                                <p class="text-xs text-gray-500">Vendeur : {{ $item->seller?->name ?? '—' }} · {{ $item->quantity }} × {{ $fcfa($item->unit_price) }}</p>
                            </div>
                            <p class="text-white font-medium">{{ $fcfa($item->total_price) }}</p>
                        </div>
                    @endforeach
                </div>
                <div class="mt-3 pt-3 border-t border-dark-200 text-sm text-gray-400 flex justify-between">
                    <span>Livraison (payée avec l'acompte)</span><span>{{ $fcfa($order->delivery_fee) }}</span>
                </div>
            </div>

            <!-- Historique -->
            <div class="bg-dark-100 rounded-xl border border-dark-200 p-6">
                <h2 class="text-lg font-semibold text-white mb-4">Historique</h2>
                <ol class="space-y-3">
                    @foreach($order->trackingEvents as $event)
                        <li class="flex gap-3 text-sm">
                            <span class="text-gray-500 w-32 flex-shrink-0">{{ $event->occurred_at?->format('d/m/Y H:i') }}</span>
                            <span class="text-gray-200">{{ $event->label }}@if($event->location) — {{ $event->location }}@endif
                                @if($event->note)<span class="block text-xs text-gray-500">{{ $event->note }}</span>@endif
                            </span>
                        </li>
                    @endforeach
                </ol>
            </div>
        </div>

        <div class="space-y-6">
            <!-- Client & récupération -->
            <div class="bg-dark-100 rounded-xl border border-dark-200 p-6 text-sm space-y-2">
                <h2 class="text-lg font-semibold text-white mb-2">Client</h2>
                <p class="text-white">{{ $order->user?->name ?? '—' }}</p>
                <p class="text-gray-300"><i class="fas fa-phone mr-2 text-gray-500"></i>{{ $order->customer_phone ?: $order->user?->phone }}</p>
                <p class="text-gray-300"><i class="fas fa-box-open mr-2 text-gray-500"></i>{{ $delivery['delivery_option_label'] ?? $order->delivery_breakdown['delivery_option_label'] ?? '—' }} · {{ $order->delivery_breakdown['company_name'] ?? $order->deliveryCompany?->name }}</p>
                @if($order->delivery_address)
                    <p class="text-gray-300"><i class="fas fa-map-marker-alt mr-2 text-gray-500"></i>{{ $order->delivery_address }}@if($order->delivery_address_details), {{ $order->delivery_address_details }}@endif</p>
                @endif
            </div>

            <!-- Vérification ASSO -->
            <div class="bg-dark-100 rounded-xl border border-amber-500/40 p-6 text-sm space-y-3">
                <h2 class="text-lg font-semibold text-white"><i class="fas fa-user-check text-amber-400 mr-2"></i>Vérification ASSO</h2>
                <p class="text-gray-300">Statut : <span class="text-white">{{ $verificationLabels[$verification] ?? $verification }}</span></p>
                <p class="text-gray-300">Solde : <span class="text-white">{{ $balanceLabels[$order->balance_status] ?? $order->balance_status }}</span></p>
                @if($order->verifier)
                    <p class="text-gray-400 text-xs">Par {{ $order->verifier->name }} le {{ $order->verified_at?->format('d/m/Y à H:i') }}</p>
                @endif
                @if($order->verification_note)
                    <p class="text-gray-400 text-xs bg-dark-200 rounded p-2">Note interne : {{ $order->verification_note }}</p>
                @endif

                @if($canContact)
                    <form method="POST" action="{{ route('admin.deposit-orders.contact', $order) }}" class="space-y-2">
                        @csrf
                        <textarea name="note" rows="2" placeholder="Note interne (facultatif)" class="w-full px-3 py-2 bg-dark-200 border border-dark-300 rounded-lg text-white text-sm"></textarea>
                        <button class="w-full px-4 py-2 bg-dark-200 border border-amber-500/50 text-amber-200 rounded-lg"><i class="fas fa-phone mr-1"></i> Client contacté</button>
                    </form>
                @endif
                @if($canValidate)
                    <form method="POST" action="{{ route('admin.deposit-orders.validate', $order) }}" class="space-y-2">
                        @csrf
                        <textarea name="note" rows="2" placeholder="Note interne (facultatif)" class="w-full px-3 py-2 bg-dark-200 border border-dark-300 rounded-lg text-white text-sm"></textarea>
                        <button class="w-full px-4 py-2 bg-green-600 hover:bg-green-700 text-white font-semibold rounded-lg"><i class="fas fa-check mr-1"></i> VALIDER LA VÉRIFICATION</button>
                        <p class="text-xs text-gray-500">Débloque le paiement du solde et prévient le client.</p>
                    </form>
                @endif
                @if($canReport)
                    <form method="POST" action="{{ route('admin.deposit-orders.issue', $order) }}" class="space-y-2">
                        @csrf
                        <textarea name="note" rows="2" required placeholder="Problème constaté" class="w-full px-3 py-2 bg-dark-200 border border-dark-300 rounded-lg text-white text-sm"></textarea>
                        <button class="w-full px-4 py-2 bg-dark-200 border border-red-500/50 text-red-300 rounded-lg"><i class="fas fa-exclamation-triangle mr-1"></i> Signaler un problème</button>
                    </form>
                @endif
                @if($verification === Order::VERIFICATION_PENDING && $open)
                    <p class="text-xs text-gray-500">La vérification se fait à la présentation du produit (livraison ou retrait).</p>
                @endif
            </div>

            <!-- Clôture au cas par cas -->
            @if($canClose)
                <div class="bg-dark-100 rounded-xl border border-red-500/30 p-6 text-sm">
                    <h2 class="text-lg font-semibold text-white mb-1">Clôturer la commande</h2>
                    <p class="text-xs text-gray-400 mb-3">Acompte encaissé : {{ $fcfa($depositCollected) }}. Ce qui n'est ni rendu au client, ni versé au vendeur ou au livreur reste à ASSO. Irréversible.</p>
                    <form method="POST" action="{{ route('admin.deposit-orders.close', $order) }}" class="space-y-2"
                          onsubmit="return confirm('Clôturer la commande et répartir l\'acompte ?')">
                        @csrf
                        @foreach([
                            ['refund_amount', 'Rendu au client (Wallet)', old('refund_amount', $depositCollected)],
                            ['vendor_amount', 'Versé au vendeur', old('vendor_amount', 0)],
                            ['delivery_amount', 'Versé au livreur', old('delivery_amount', 0)],
                        ] as [$name, $label, $value])
                            <label class="block text-gray-300">{{ $label }}
                                <input type="number" name="{{ $name }}" min="0" step="1" value="{{ $value }}" class="mt-1 w-full px-3 py-2 bg-dark-200 border border-dark-300 rounded-lg text-white">
                            </label>
                        @endforeach
                        <textarea name="note" rows="2" required placeholder="Motif de la clôture" class="w-full px-3 py-2 bg-dark-200 border border-dark-300 rounded-lg text-white text-sm">{{ old('note') }}</textarea>
                        <button class="w-full px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-lg"><i class="fas fa-ban mr-1"></i> Clôturer et répartir l'acompte</button>
                    </form>
                </div>
            @endif
        </div>
    </div>
</div>
@endsection
