@extends('admin.layouts.app')

@section('content')
@php
    use App\Support\LocalOrderStage;
    $fcfa = fn ($v) => number_format((float) $v, 0, ',', ' ') . ' FCFA';
    $card = 'bg-dark-100 rounded-xl border border-dark-200 p-6';
    $row = 'flex justify-between gap-4';
    $paymentMethods = ['kpay_direct' => 'Mobile Money (KPay)', 'stripe_direct' => 'Carte bancaire (Stripe)', 'paypal_direct' => 'PayPal'];
    $paymentStatuses = ['paid' => ['Payée', 'text-green-400'], 'pending' => ['En attente', 'text-yellow-400'], 'failed' => ['Échouée', 'text-red-400'], 'refunded' => ['Remboursée', 'text-blue-300']];
    [$paymentLabel, $paymentColor] = $paymentStatuses[$order->payment_status] ?? [$order->payment_status, 'text-gray-300'];
    $sellers = $order->items->pluck('seller')->filter()->unique('id');
@endphp
<div class="p-6">
    <div class="mb-6 flex flex-wrap items-center gap-3">
        <a href="{{ route('admin.local-orders.index') }}" class="text-gray-400 hover:text-primary-500"><i class="fas fa-arrow-left"></i></a>
        <div class="flex-1 min-w-0">
            <h1 class="text-2xl font-bold text-white">🇨🇲 Commande locale #{{ $order->order_number }}</h1>
            <p class="text-gray-400">Passée le {{ $order->created_at->format('d/m/Y à H:i') }}@if($delivery['company_name']) · livraison {{ $delivery['company_name'] }}@endif</p>
        </div>
        <span class="px-3 py-1 rounded-full text-sm border {{ LocalOrderStage::badge($stage) }}">{{ LocalOrderStage::label($stage) }}</span>
    </div>

    @if($stage === 'cancelled')
        <div class="mb-6 p-4 bg-red-900/20 border-l-4 border-red-500 rounded text-red-300">
            <i class="fas fa-ban mr-2"></i>Annulée le {{ $order->cancelled_at?->format('d/m/Y à H:i') ?? '—' }}@if($order->cancel_reason) : {{ $order->cancel_reason }}@endif
            @if($order->refunded_at) · client remboursé le {{ $order->refunded_at->format('d/m/Y') }}@endif
        </div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2 space-y-6">
            <!-- Articles -->
            <div class="{{ $card }}">
                <h2 class="text-lg font-semibold text-white mb-4"><i class="fas fa-boxes mr-2 text-primary-400"></i>Articles</h2>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="text-gray-400 text-left border-b border-dark-200">
                            <tr>
                                <th class="pb-2 pr-3">Produit</th>
                                <th class="pb-2 pr-3">Origine</th>
                                <th class="pb-2 pr-3 text-right">Qté</th>
                                <th class="pb-2 pr-3 text-right">Prix unitaire</th>
                                <th class="pb-2 text-right">Total</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-dark-200/50 text-gray-300">
                            @foreach($order->items as $item)
                                @php $image = $item->product?->primaryImage?->image_path; @endphp
                                <tr>
                                    <td class="py-3 pr-3">
                                        <div class="flex items-center gap-3">
                                            @if($image)
                                                <img src="{{ media_url($image) }}" alt="" class="w-10 h-10 rounded object-cover flex-shrink-0">
                                            @else
                                                <div class="w-10 h-10 rounded bg-dark-200 flex items-center justify-center flex-shrink-0"><i class="fas fa-box text-gray-500"></i></div>
                                            @endif
                                            <div>
                                                @if($item->product)
                                                    <a href="{{ route('admin.products.show', $item->product) }}" class="text-white hover:text-primary-400">{{ $item->product->name }}</a>
                                                @else
                                                    <span class="text-gray-500">Produit supprimé</span>
                                                @endif
                                                @if($item->variant_attributes)
                                                    <div class="text-xs text-gray-400">{{ collect($item->variant_attributes)->map(fn ($v, $k) => "{$k} : {$v}")->implode(' · ') }}</div>
                                                @endif
                                                <div class="text-xs text-gray-500">Vendeur : {{ $item->seller?->name ?? '—' }}</div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="py-3 pr-3">{{ $item->product?->originLabel() ?? '—' }}</td>
                                    <td class="py-3 pr-3 text-right text-white">{{ number_format($item->quantity, 0, ',', ' ') }}</td>
                                    <td class="py-3 pr-3 text-right">{{ $fcfa($item->unit_price) }}</td>
                                    <td class="py-3 text-right text-white font-medium">{{ $fcfa($item->total_price) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if($order->notes)
                    <div class="mt-4 p-3 bg-dark-50 rounded-lg text-sm text-gray-300"><i class="fas fa-sticky-note mr-1 text-gray-500"></i>{{ $order->notes }}</div>
                @endif
            </div>

            <!-- Historique -->
            <div class="{{ $card }}">
                <h2 class="text-lg font-semibold text-white mb-4"><i class="fas fa-stream mr-2 text-primary-400"></i>Historique</h2>
                <ol class="relative border-l border-dark-300 ml-2">
                    @forelse($order->trackingEvents as $event)
                        <li class="mb-5 ml-5">
                            <span class="absolute -left-1.5 w-3 h-3 rounded-full bg-primary-500"></span>
                            <div class="text-white font-medium">{{ $event->label }} @if($event->location)<span class="text-gray-400">— {{ $event->location }}</span>@endif</div>
                            <div class="text-xs text-gray-500">{{ $event->occurred_at->format('d/m/Y H:i') }} · {{ ['buyer' => 'client', 'vendor' => 'vendeur', 'admin' => 'admin ASSO', 'deliverer' => 'coursier', 'system' => 'système'][$event->actor_type] ?? $event->actor_type }}</div>
                            @if($event->note)<div class="text-sm text-gray-300 mt-1">{{ $event->note }}</div>@endif
                        </li>
                    @empty
                        <li class="ml-5 text-gray-500">Aucune étape enregistrée.</li>
                    @endforelse
                </ol>
            </div>
        </div>

        <div class="space-y-6">
            <!-- Montants -->
            <div class="{{ $card }} text-sm text-gray-300 space-y-2">
                <h2 class="text-lg font-semibold text-white mb-2"><i class="fas fa-file-invoice-dollar mr-2 text-primary-400"></i>Montants</h2>
                <div class="{{ $row }}"><span>Produits</span><span class="text-white">{{ $fcfa($order->subtotal) }}</span></div>
                <div class="{{ $row }}"><span>Livraison</span><span class="text-white">{{ $fcfa($order->delivery_fee) }}</span></div>
                <div class="{{ $row }} border-t border-dark-200 pt-2 text-base font-semibold"><span class="text-white">Total payé par le client</span><span class="text-primary-400">{{ $fcfa($order->total) }}</span></div>
                @if($order->vendor_net_amount !== null)
                    <div class="{{ $row }}"><span>Net vendeur</span><span class="text-white">{{ $fcfa($order->vendor_net_amount) }}</span></div>
                @endif
                @if($order->sale_commission !== null)
                    <div class="{{ $row }}"><span>Commission ASSO</span><span class="text-white">{{ $fcfa($order->sale_commission) }}</span></div>
                @endif
            </div>

            <!-- Paiement -->
            <div class="{{ $card }} text-sm text-gray-300 space-y-2">
                <h2 class="text-lg font-semibold text-white mb-2"><i class="fas fa-credit-card mr-2 text-primary-400"></i>Paiement</h2>
                <div class="{{ $row }}"><span>Moyen</span><span class="text-white text-right">{{ $paymentMethods[$order->payment_method] ?? (str_starts_with((string) $order->payment_method, 'wallet') ? 'Wallet ASSO' : $order->payment_method) }}</span></div>
                <div class="{{ $row }}"><span>Statut</span><span class="{{ $paymentColor }}">{{ $paymentLabel }}</span></div>
                @if($order->payment_reference)
                    <div class="{{ $row }}"><span>Référence</span><span class="text-white font-mono text-xs break-all text-right">{{ $order->payment_reference }}</span></div>
                @endif
            </div>

            <!-- Client -->
            <div class="{{ $card }} text-sm text-gray-300 space-y-1">
                <h2 class="text-lg font-semibold text-white mb-2"><i class="fas fa-user mr-2 text-primary-400"></i>Client</h2>
                @if($order->user)
                    <a href="{{ route('admin.users.show', $order->user) }}" class="text-white hover:text-primary-400 font-medium">{{ $order->user->name }}</a>
                    <div>{{ $order->user->email }}</div>
                    <div>Compte : {{ $order->user->phone ?: '—' }}</div>
                @endif
                <div>À contacter : <span class="text-white">{{ $order->customer_phone ?: '—' }}</span></div>
            </div>

            <!-- Vendeur(s) -->
            <div class="{{ $card }} text-sm text-gray-300 space-y-2">
                <h2 class="text-lg font-semibold text-white mb-2"><i class="fas fa-store mr-2 text-primary-400"></i>{{ $sellers->count() > 1 ? 'Vendeurs' : 'Vendeur' }}</h2>
                @forelse($sellers as $seller)
                    <div>
                        <a href="{{ route('admin.users.show', $seller) }}" class="text-white hover:text-primary-400 font-medium">{{ $seller->name }}</a>
                        <div>{{ $seller->phone ?: '—' }}</div>
                    </div>
                @empty
                    <p class="text-gray-500">—</p>
                @endforelse
            </div>

            <!-- Livraison -->
            <div class="{{ $card }} text-sm text-gray-300 space-y-2">
                <h2 class="text-lg font-semibold text-white mb-2"><i class="fas fa-truck mr-2 text-primary-400"></i>Livraison</h2>
                @if($delivery['company_name'])
                    <div class="{{ $row }}"><span>Partenaire</span><span class="text-white">{{ $delivery['company_name'] }}</span></div>
                @endif
                @if($delivery['delivery_option_label'] ?? null)
                    <div class="{{ $row }}"><span>Option</span><span class="text-white text-right">{{ $delivery['delivery_option_label'] }}</span></div>
                @endif
                @if($delivery['route_label'])
                    <div class="{{ $row }}"><span>Trajet</span><span class="text-white text-right">{{ $delivery['route_label'] }}</span></div>
                @endif
                @if($order->deliveryPerson)
                    <div class="{{ $row }}"><span>Coursier</span><span class="text-white text-right">{{ $order->deliveryPerson->name }} · {{ $order->deliveryPerson->phone }}</span></div>
                @endif
                @if($delivery['carrier_tracking_number'])
                    <div class="{{ $row }}"><span>N° de suivi</span><span class="text-white font-mono">{{ $delivery['carrier_tracking_number'] }}</span></div>
                @endif
                <div class="pt-2 border-t border-dark-200">
                    <div class="text-white">{{ $order->delivery_address ?: 'Adresse non renseignée' }}</div>
                    @if($order->delivery_address_details)<div class="text-gray-400">{{ $order->delivery_address_details }}</div>@endif
                    @if($order->delivery_latitude && $order->delivery_longitude)
                        <a href="https://www.google.com/maps?q={{ $order->delivery_latitude }},{{ $order->delivery_longitude }}" target="_blank" rel="noopener" class="inline-block mt-1 text-primary-400 hover:text-primary-300"><i class="fas fa-map-marker-alt mr-1"></i>Voir sur la carte</a>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
