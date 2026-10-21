@extends('admin.layouts.app')

@section('content')
@php
    use App\Support\LocalOrderStage;
    $fcfa = fn ($v) => number_format((float) $v, 0, ',', ' ') . ' FCFA';
    $card = 'bg-dark-100 rounded-xl border border-dark-200 p-6';
    $row = 'flex justify-between gap-4';
    $input = 'w-full px-3 py-2 bg-dark-50 border border-dark-300 rounded-lg text-white text-sm';
    $paymentMethods = ['kpay_direct' => 'Mobile Money (KPay)', 'stripe_direct' => 'Carte bancaire (Stripe)', 'paypal_direct' => 'PayPal'];
    $paymentStatuses = ['paid' => ['Payée', 'text-green-400'], 'pending' => ['En attente', 'text-yellow-400'], 'failed' => ['Échouée', 'text-red-400'], 'refunded' => ['Remboursée', 'text-blue-300']];
    [$paymentLabel, $paymentColor] = $paymentStatuses[$order->payment_status] ?? [$order->payment_status, 'text-gray-300'];
    $orderShops = $order->items->pluck('product.shop')->filter()->unique('id');
    $stageLabel = $stage === 'to_confirm' ? 'À confirmer' : LocalOrderStage::label($stage);
@endphp
<div class="p-6">
    <div class="mb-6 flex flex-wrap items-center gap-3">
        <a href="{{ route('admin.asso-orders.index') }}" class="text-gray-400 hover:text-primary-500"><i class="fas fa-arrow-left"></i></a>
        <div class="flex-1 min-w-0">
            <h1 class="text-2xl font-bold text-white">Commande #{{ $order->order_number }}</h1>
            <p class="text-gray-400">{{ $orderShops->pluck('name')->implode(', ') ?: 'Boutique ASSO' }} · passée le {{ $order->created_at->displayTz()->format('d/m/Y à H:i') }}@if($delivery['company_name']) · livraison {{ $delivery['company_name'] }}@endif</p>
        </div>
        <span class="px-3 py-1 rounded-full text-sm border {{ LocalOrderStage::badge($stage) }}">{{ $stageLabel }}</span>
    </div>

    @include('admin.wholesale_orders._flash')

    @if($stage === 'cancelled')
        <div class="mb-6 p-4 bg-red-900/20 border-l-4 border-red-500 rounded text-red-300">
            <i class="fas fa-ban mr-2"></i>Annulée le {{ $order->cancelled_at?->displayTz()->format('d/m/Y à H:i') ?? '—' }}@if($order->cancel_reason) : {{ $order->cancel_reason }}@endif
            @if($order->refunded_at) · client remboursé le {{ $order->refunded_at->displayTz()->format('d/m/Y') }}@endif
        </div>
    @endif

    @if(in_array($stage, ['awaiting_payment', 'to_confirm'], true))
        <div class="{{ $card }} mb-6">
            <h2 class="text-lg font-semibold text-white mb-3"><i class="fas fa-tasks mr-2 text-primary-400"></i>Décision</h2>
            @if($stage === 'to_confirm')
                <form action="{{ route('admin.asso-orders.confirm', $order) }}" method="POST" class="mb-4"
                      data-confirm="Confirmer la commande #{{ $order->order_number }} ? Le client recevra son code de livraison{{ $order->isCarrierDelivery() ? '' : ' et la demande de course partira vers les livreurs' }}.">
                    @csrf
                    <button class="px-4 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg text-sm">
                        <i class="fas fa-check mr-1"></i> Confirmer la commande
                    </button>
                    <span class="ml-2 text-xs text-gray-400">
                        Paiement acquis. Vérifiez d'abord la disponibilité chez le fournisseur.
                        @if($order->isCarrierDelivery()) Ensuite, remettez le colis au transporteur (menu Expéditions).@endif
                    </span>
                </form>
            @else
                <p class="mb-4 text-sm text-yellow-300"><i class="fas fa-hourglass-half mr-1"></i>Paiement pas encore confirmé ({{ $paymentMethods[$order->payment_method] ?? 'Wallet ASSO' }}) : la commande ne peut pas encore être confirmée.</p>
            @endif
            <form action="{{ route('admin.asso-orders.reject', $order) }}" method="POST" class="flex flex-wrap gap-2 items-end"
                  data-confirm="Refuser la commande #{{ $order->order_number }} ? Le client sera remboursé.">
                @csrf
                <div class="flex-1 min-w-[16rem]">
                    <label class="block text-xs text-gray-400 mb-1">Motif du refus (envoyé au client)</label>
                    <input type="text" name="reason" required maxlength="500" placeholder="Ex. rupture chez le fournisseur" class="{{ $input }}">
                </div>
                <button class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-lg text-sm"><i class="fas fa-times mr-1"></i> Refuser et rembourser</button>
            </form>
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
                                                @if($item->product && auth()->user()->canAccessAdminRoute('admin.products.show'))
                                                    <a href="{{ route('admin.products.show', $item->product) }}" class="text-white hover:text-primary-400">{{ $item->product->name }}</a>
                                                @else
                                                    <span class="text-white">{{ $item->product?->name ?? 'Produit supprimé' }}</span>
                                                @endif
                                                @if($item->variant_attributes)
                                                    <div class="text-xs text-gray-400">{{ collect($item->variant_attributes)->map(fn ($v, $k) => "{$k} : {$v}")->implode(' · ') }}</div>
                                                @endif
                                                <div class="text-xs text-gray-500">{{ $item->product?->shop?->name ?? '—' }}</div>
                                            </div>
                                        </div>
                                    </td>
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

            @if($order->isCarrierDelivery())
                @include('admin.shipments._tracking_actions')
            @endif

            <!-- Historique -->
            <div class="{{ $card }}">
                <h2 class="text-lg font-semibold text-white mb-4"><i class="fas fa-stream mr-2 text-primary-400"></i>Historique</h2>
                <ol class="relative border-l border-dark-300 ml-2">
                    @forelse($order->trackingEvents as $event)
                        <li class="mb-5 ml-5">
                            <span class="absolute -left-1.5 w-3 h-3 rounded-full bg-primary-500"></span>
                            <div class="text-white font-medium">{{ $event->label }} @if($event->location)<span class="text-gray-400">— {{ $event->location }}</span>@endif</div>
                            <div class="text-xs text-gray-500">{{ $event->occurred_at->displayTz()->format('d/m/Y H:i') }} · {{ ['buyer' => 'client', 'vendor' => 'vendeur', 'admin' => 'ASSO', 'deliverer' => 'coursier', 'system' => 'système'][$event->actor_type] ?? $event->actor_type }}</div>
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
                @if($order->isDepositOrder())
                    <div class="pt-2 text-xs text-yellow-300"><i class="fas fa-hand-holding-usd mr-1"></i>Commande avec acompte : la vérification avec le client se fait dans « Commandes avec acompte ».</div>
                @endif
            </div>

            <!-- Paiement -->
            <div class="{{ $card }} text-sm text-gray-300 space-y-2">
                <h2 class="text-lg font-semibold text-white mb-2"><i class="fas fa-credit-card mr-2 text-primary-400"></i>Paiement</h2>
                <div class="{{ $row }}"><span>Moyen</span><span class="text-white text-right">{{ $paymentMethods[$order->payment_method] ?? (str_starts_with((string) $order->payment_method, 'wallet') ? 'Wallet ASSO' : $order->payment_method) }}</span></div>
                <div class="{{ $row }}"><span>Statut</span><span class="{{ $paymentColor }}">{{ $paymentLabel }}</span></div>
            </div>

            <!-- Client -->
            <div class="{{ $card }} text-sm text-gray-300 space-y-1">
                <h2 class="text-lg font-semibold text-white mb-2"><i class="fas fa-user mr-2 text-primary-400"></i>Client</h2>
                <div class="text-white font-medium">{{ $order->user?->name ?? '—' }}</div>
                <div>À contacter : <span class="text-white">{{ $order->customer_phone ?: ($order->user?->phone ?: '—') }}</span></div>
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
                @if($order->deliveryPerson)
                    <div class="{{ $row }}"><span>Coursier</span><span class="text-white text-right">{{ $order->deliveryPerson->name }} · {{ $order->deliveryPerson->phone }}</span></div>
                @elseif(in_array($stage, ['preparing'], true) && ! $order->isCarrierDelivery())
                    <div class="text-yellow-300 text-xs"><i class="fas fa-hourglass-half mr-1"></i>En attente qu'un livreur accepte la course.</div>
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
