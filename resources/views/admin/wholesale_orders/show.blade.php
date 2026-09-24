@extends('admin.layouts.app')

@section('content')
@php
    use App\Support\WholesaleOrderStage;
    $fcfa = fn ($v) => number_format((float) $v, 0, ',', ' ') . ' FCFA';
    $kg = fn ($v) => rtrim(rtrim(number_format((float) $v, 3, ',', ' '), '0'), ',') . ' kg';
    $input = 'w-full px-3 py-2 bg-dark-50 border border-dark-200 rounded-lg text-white focus:border-primary-500 focus:outline-none text-sm';
    $card = 'bg-dark-100 rounded-xl border border-dark-200 p-6';
    $row = 'flex justify-between gap-4';

    $countryName = $country?->name ?? $order->import_country_code;
    $modeLabel = \App\Models\ImportShippingOption::MODE_LABELS[$order->shipping_mode] ?? $order->shipping_mode;
    $importFee = (float) ($order->import_shipping_fee ?? $order->delivery_fee);
    $localFee = (float) $order->delivery_fee - $importFee;
    $b = $delivery['breakdown'] ?? [];
    $hasLocal = $order->delivery_company_id !== null;

    // Parcours : combien d'étapes sont franchies.
    $journey = ['Payée', 'Validée par ASSO', "Expédiée depuis {$countryName}", 'Arrivée à Douala', 'Livraison SOLEX', 'Livrée'];
    $done = match ($stage) {
        'to_validate' => 1,
        'to_ship' => 2,
        'in_transit' => 3,
        'last_mile' => ($order->delivery_person_id || $order->tracking_status === 'out_for_delivery') ? 5 : 4,
        'delivered' => 6,
        default => 0,
    };
    // Étape suivante proposée dans le formulaire de suivi.
    $nextStep = match ($stage) {
        'to_ship' => 'handed_to_carrier',
        'in_transit' => $order->tracking_status === 'handed_to_carrier' ? 'in_transit' : 'arrived_hub',
        'last_mile' => 'delivered',
        default => null,
    };
    $paymentMethods = ['kpay_direct' => 'Mobile Money (KPay)', 'stripe_direct' => 'Carte bancaire (Stripe)', 'paypal_direct' => 'PayPal'];
    $paymentStatuses = ['paid' => ['Payée', 'text-green-400'], 'pending' => ['En attente', 'text-yellow-400'], 'failed' => ['Échouée', 'text-red-400'], 'refunded' => ['Remboursée', 'text-blue-300']];
    [$paymentLabel, $paymentColor] = $paymentStatuses[$order->payment_status] ?? [$order->payment_status, 'text-gray-300'];
@endphp
<div class="p-6">
    <div class="mb-6 flex flex-wrap items-center gap-3">
        <a href="{{ route('admin.wholesale-orders.index') }}" class="text-gray-400 hover:text-primary-500"><i class="fas fa-arrow-left"></i></a>
        <div class="flex-1 min-w-0">
            <h1 class="text-2xl font-bold text-white">
                <span class="mr-1">{{ $country?->flag }}</span>Commande en gros #{{ $order->order_number }}
            </h1>
            <p class="text-gray-400">Passée le {{ $order->created_at->format('d/m/Y à H:i') }} · {{ $countryName }} → Douala → {{ $delivery['company_name'] ?? 'client' }}</p>
        </div>
        <span class="px-3 py-1 rounded-full text-sm border {{ WholesaleOrderStage::badge($stage) }}">{{ WholesaleOrderStage::label($stage) }}</span>
    </div>

    @include('admin.wholesale_orders._flash')

    <!-- Parcours -->
    @if($stage === 'cancelled')
        <div class="mb-6 p-4 bg-red-900/20 border-l-4 border-red-500 rounded text-red-300">
            <i class="fas fa-ban mr-2"></i>Annulée le {{ $order->cancelled_at?->format('d/m/Y à H:i') ?? '—' }}@if($order->cancel_reason) : {{ $order->cancel_reason }}@endif
            @if($order->refunded_at) · client remboursé le {{ $order->refunded_at->format('d/m/Y') }}@endif
        </div>
    @else
        <div class="{{ $card }} mb-6">
            <ol class="grid grid-cols-2 md:grid-cols-6 gap-3">
                @foreach($journey as $i => $label)
                    @php $state = $i < $done ? 'done' : ($i === $done ? 'current' : 'todo'); @endphp
                    <li class="flex items-center gap-2">
                        <span class="flex items-center justify-center w-7 h-7 rounded-full text-xs font-bold flex-shrink-0
                            {{ $state === 'done' ? 'bg-green-500 text-white' : ($state === 'current' ? 'bg-primary-500 text-white' : 'bg-dark-200 text-gray-500') }}">
                            @if($state === 'done')<i class="fas fa-check"></i>@else{{ $i + 1 }}@endif
                        </span>
                        <span class="text-sm {{ $state === 'todo' ? 'text-gray-500' : 'text-white' }}">{{ $label }}</span>
                    </li>
                @endforeach
            </ol>
        </div>
    @endif

    <!-- Actions à faire maintenant -->
    @if($order->status === 'pending')
        <div class="{{ $card }} mb-6">
            <h2 class="text-lg font-semibold text-white mb-3"><i class="fas fa-tasks mr-2 text-primary-400"></i>Décision</h2>
            @if($stage === 'to_validate')
                <form action="{{ route('admin.wholesale-orders.confirm', $order) }}" method="POST" class="mb-4"
                      data-confirm="Valider la commande #{{ $order->order_number }} ? Le paiement est réglé : vendeur, SOLEX et ASSO sont crédités.">
                    @csrf
                    <button class="px-4 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg text-sm">
                        <i class="fas fa-check mr-1"></i> Valider la commande
                    </button>
                    <span class="ml-2 text-xs text-gray-400">Paiement acquis. Ensuite, commandez chez le fournisseur et expédiez vers Douala.</span>
                </form>
            @else
                <p class="mb-4 text-sm text-yellow-300"><i class="fas fa-hourglass-half mr-1"></i>Paiement pas encore confirmé ({{ $paymentMethods[$order->payment_method] ?? 'Wallet ASSO' }}) : la commande ne peut pas encore être validée.</p>
            @endif
            <form action="{{ route('admin.wholesale-orders.reject', $order) }}" method="POST" class="flex flex-wrap gap-2 items-end"
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
                                <th class="pb-2 pr-3">Palier</th>
                                <th class="pb-2 pr-3 text-right">Qté</th>
                                <th class="pb-2 pr-3 text-right">Prix unitaire</th>
                                <th class="pb-2 pr-3 text-right">Poids</th>
                                <th class="pb-2 text-right">Total</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-dark-200/50 text-gray-300">
                            @php $totalWeight = 0; @endphp
                            @foreach($order->items as $item)
                                @php
                                    $tier = $tiers->get($item->price_tier_id);
                                    $unitWeight = ($tier?->weight_kg > 0 ? (float) $tier->weight_kg : null) ?? $item->product?->weightKg();
                                    $lineWeight = $unitWeight ? $unitWeight * $item->quantity : null;
                                    $totalWeight += $lineWeight ?? 0;
                                    $image = $item->product?->primaryImage?->image_path;
                                @endphp
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
                                            </div>
                                        </div>
                                    </td>
                                    <td class="py-3 pr-3">{{ $item->tier_label ?? '—' }}</td>
                                    <td class="py-3 pr-3 text-right text-white">{{ number_format($item->quantity, 0, ',', ' ') }}</td>
                                    <td class="py-3 pr-3 text-right">{{ $fcfa($item->unit_price) }}</td>
                                    <td class="py-3 pr-3 text-right">
                                        @if($lineWeight)
                                            {{ $kg($lineWeight) }}
                                            <div class="text-xs text-gray-500">{{ $kg($unitWeight) }} / unité</div>
                                        @else
                                            <span class="text-yellow-400">Non renseigné</span>
                                        @endif
                                    </td>
                                    <td class="py-3 text-right text-white font-medium">{{ $fcfa($item->total_price) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot class="border-t border-dark-200 text-gray-300">
                            <tr>
                                <td colspan="4" class="pt-3 text-right">Poids du colis</td>
                                <td class="pt-3 pr-3 text-right text-white">{{ $order->shipping_weight_kg ? $kg($order->shipping_weight_kg) : ($totalWeight ? $kg($totalWeight) : '—') }}</td>
                                <td class="pt-3 text-right text-white font-semibold">{{ $fcfa($order->subtotal) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                @if($order->notes)
                    <div class="mt-4 p-3 bg-dark-50 rounded-lg text-sm text-gray-300"><i class="fas fa-sticky-note mr-1 text-gray-500"></i>{{ $order->notes }}</div>
                @endif
            </div>

            <!-- Ajouter une étape -->
            @unless(in_array($order->status, ['cancelled', 'delivered', 'pending'], true))
                <div class="{{ $card }}">
                    <h2 class="text-lg font-semibold text-white mb-1"><i class="fas fa-plus-circle mr-2 text-primary-400"></i>Mettre à jour l'acheminement</h2>
                    <p class="text-xs text-gray-400 mb-4">
                        Remis au transporteur (avec son n° de suivi) → en transit → dédouanement → arrivé à l'entrepôt ASSO de Douala.
                        @if($order->leavesFromImportHub() && $order->hasLastMileDelivery())
                            À l'arrivée à Douala, les coursiers {{ $delivery['company_name'] }} sont prévenus et l'un d'eux vient chercher le colis.
                        @elseif($hasLocal)
                            Ensuite, remettez le colis à l'agence {{ $delivery['company_name'] }} de Douala pour le trajet vers {{ $order->deliveryRoute?->destination_city ?? 'la ville du client' }}.
                        @endif
                        Le client est prévenu à chaque étape.
                    </p>
                    <form action="{{ route('admin.shipments.step', $order) }}" method="POST" class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        @csrf
                        <div>
                            <label class="block text-xs text-gray-400 mb-1">Étape</label>
                            <select name="step" class="{{ $input }}">
                                @foreach($steps as $value => $label)
                                    <option value="{{ $value }}" @selected($value === $nextStep)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs text-gray-400 mb-1">N° de suivi {{ $shipping?->carrier ?: 'transporteur' }}</label>
                            <input type="text" name="carrier_tracking_number" value="{{ $order->carrier_tracking_number }}" class="{{ $input }}">
                        </div>
                        <div>
                            <label class="block text-xs text-gray-400 mb-1">Lieu</label>
                            <input type="text" name="location" placeholder="Ex. Port de Douala, entrepôt ASSO…" class="{{ $input }}">
                        </div>
                        <div>
                            <label class="block text-xs text-gray-400 mb-1">Note pour le client</label>
                            <input type="text" name="note" class="{{ $input }}">
                        </div>
                        <div class="md:col-span-2 text-right">
                            <button class="px-4 py-2 bg-primary-500 text-white rounded-lg hover:bg-primary-600 text-sm"><i class="fas fa-save mr-1"></i> Enregistrer et prévenir le client</button>
                        </div>
                    </form>
                </div>
            @endunless

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
                <div class="{{ $row }}"><span>{{ $delivery['import_leg']['label'] ?? "Expédition {$countryName} → Douala ({$modeLabel})" }}</span><span class="text-white">{{ $fcfa($importFee) }}</span></div>
                @if($hasLocal)
                    <div class="{{ $row }}"><span>Livraison {{ $delivery['company_name'] }} → client</span><span class="text-white">{{ $fcfa($localFee) }}</span></div>
                    @if($b)
                        <div class="pl-3 text-xs text-gray-400 space-y-1">
                            @foreach($b['legs'] ?? [] as $leg)
                                <div class="{{ $row }}"><span>{{ $leg['label'] }}</span><span>{{ $fcfa($leg['price']) }} HT</span></div>
                            @endforeach
                            @if(empty($b['legs']))
                                <div class="{{ $row }}"><span>{{ $b['range_label'] ?? 'Course' }}</span><span>{{ $fcfa($b['carrier_price_ht'] ?? 0) }} HT</span></div>
                            @endif
                            @if(($b['vat_amount'] ?? 0) > 0)
                                <div class="{{ $row }}"><span>TVA {{ $b['vat_rate'] }} %</span><span>{{ $fcfa($b['vat_amount']) }}</span></div>
                            @endif
                            @if(($b['asso_commission'] ?? 0) > 0)
                                <div class="{{ $row }}"><span>Commission ASSO</span><span>{{ $fcfa($b['asso_commission']) }}</span></div>
                            @endif
                        </div>
                    @endif
                @endif
                <div class="{{ $row }} border-t border-dark-200 pt-2 text-base font-semibold"><span class="text-white">Total payé par le client</span><span class="text-primary-400">{{ $fcfa($order->total) }}</span></div>

                <h3 class="text-sm font-semibold text-white pt-4">Répartition à la validation</h3>
                <div class="{{ $row }}"><span>Vendeur ({{ $order->items->first()?->seller?->name ?? 'ASSO' }})</span><span class="text-white">{{ $fcfa($order->vendor_net_amount ?? $order->subtotal) }}</span></div>
                @if($hasLocal)
                    <div class="{{ $row }}"><span>{{ $delivery['company_name'] }} (course TTC)</span><span class="text-white">{{ $fcfa($order->base_delivery_price) }}</span></div>
                    <div class="{{ $row }}"><span>Commission ASSO livraison</span><span class="text-white">{{ $fcfa($order->delivery_commission) }}</span></div>
                @endif
                <div class="{{ $row }}"><span>Import jusqu'à Douala (ASSO)</span><span class="text-white">{{ $fcfa($importFee) }}</span></div>
                <p class="text-xs {{ $order->settled_at ? 'text-green-400' : 'text-gray-500' }}">
                    {{ $order->settled_at ? 'Réglée le ' . $order->settled_at->format('d/m/Y à H:i') : 'Pas encore réglée : elle le sera à la validation.' }}
                </p>
            </div>

            <!-- Paiement -->
            <div class="{{ $card }} text-sm text-gray-300 space-y-2">
                <h2 class="text-lg font-semibold text-white mb-2"><i class="fas fa-credit-card mr-2 text-primary-400"></i>Paiement</h2>
                <div class="{{ $row }}"><span>Moyen</span><span class="text-white text-right">{{ $paymentMethods[$order->payment_method] ?? (str_starts_with((string) $order->payment_method, 'wallet') ? 'Wallet ASSO' : $order->payment_method) }}</span></div>
                <div class="{{ $row }}"><span>Statut</span><span class="{{ $paymentColor }}">{{ $paymentLabel }}</span></div>
                @if($order->payment_reference)
                    <div class="{{ $row }}"><span>Référence</span><span class="text-white font-mono text-xs break-all text-right">{{ $order->payment_reference }}</span></div>
                @endif
                @if($order->payment_amount && $order->payment_currency && $order->payment_currency !== 'XAF')
                    <div class="{{ $row }}"><span>Montant débité</span><span class="text-white">{{ number_format((float) $order->payment_amount, 2, ',', ' ') }} {{ $order->payment_currency }}</span></div>
                @endif
                @if($order->refunded_at)
                    <div class="{{ $row }}"><span>Remboursée le</span><span class="text-white">{{ $order->refunded_at->format('d/m/Y à H:i') }}</span></div>
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

            <!-- Import -->
            <div class="{{ $card }} text-sm text-gray-300 space-y-2">
                <h2 class="text-lg font-semibold text-white mb-2"><i class="fas fa-ship mr-2 text-primary-400"></i>Import {{ $countryName }} → Douala</h2>
                <div class="{{ $row }}"><span>Mode</span><span class="text-white">{{ $modeLabel }}</span></div>
                <div class="{{ $row }}"><span>Transporteur</span><span class="text-white">{{ $shipping?->carrier ?: '—' }}</span></div>
                <div class="{{ $row }}"><span>Délai annoncé</span><span class="text-white">{{ $shipping?->lead_time_days ? '≈ ' . $shipping->lead_time_days . ' jours' : '—' }}</span></div>
                @if($shipping?->expedition_note)
                    <div class="text-xs text-gray-400">{{ $shipping->expedition_note }}</div>
                @endif
                <div class="{{ $row }}"><span>N° de suivi</span><span class="text-white font-mono">{{ $order->carrier_tracking_number ?? '—' }}</span></div>
                @if($delivery['carrier_tracking_url'])
                    <a href="{{ $delivery['carrier_tracking_url'] }}" target="_blank" rel="noopener" class="inline-block text-primary-400 hover:text-primary-300"><i class="fas fa-external-link-alt mr-1"></i>Suivre chez le transporteur</a>
                @endif
            </div>

            <!-- Livraison locale -->
            <div class="{{ $card }} text-sm text-gray-300 space-y-2">
                <h2 class="text-lg font-semibold text-white mb-2"><i class="fas fa-truck mr-2 text-primary-400"></i>Douala → client</h2>
                @if($hasLocal)
                    <div class="{{ $row }}"><span>Partenaire</span><span class="text-white">{{ $delivery['company_name'] }}</span></div>
                    <div class="{{ $row }}"><span>Option</span><span class="text-white text-right">{{ $delivery['delivery_option_label'] ?? $delivery['service_mode_label'] }}</span></div>
                    @if($delivery['route_label'])
                        <div class="{{ $row }}"><span>Trajet</span><span class="text-white text-right">{{ $delivery['route_label'] }}</span></div>
                    @endif
                    @if($delivery['vehicle_label'])
                        <div class="{{ $row }}"><span>Véhicule</span><span class="text-white">{{ $delivery['vehicle_label'] }}</span></div>
                    @endif
                    @if($delivery['lead_time'])
                        <div class="{{ $row }}"><span>Délai</span><span class="text-white">{{ $delivery['lead_time'] }}</span></div>
                    @endif
                    <div class="{{ $row }}"><span>Enlèvement</span><span class="text-white text-right">{{ $pickup['name'] ?? '—' }}</span></div>
                    @if($order->deliveryPerson)
                        <div class="{{ $row }}"><span>Coursier</span><span class="text-white text-right">{{ $order->deliveryPerson->name }} · {{ $order->deliveryPerson->phone }}</span></div>
                    @endif
                @else
                    <p class="text-gray-500">Commande passée avant la livraison SOLEX : retrait à Douala.</p>
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
