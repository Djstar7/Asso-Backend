@extends('admin.layouts.app')

@section('content')
@php
    use App\Models\Dispute;
    $fcfa = fn ($v) => number_format((float) $v, 0, ',', ' ') . ' FCFA';
    $order = $dispute->order;
    $item = $dispute->item;
    $reasons = [
        'different' => 'Produit différent', 'damaged' => 'Endommagé', 'defective' => 'Défectueux',
        'incomplete' => 'Incomplet', 'wrong_variant' => 'Mauvaise variante', 'other' => 'Autre',
    ];
    $undecided = $dispute->decision === null && $dispute->isOpen();
    $founded = $dispute->decision === Dispute::DECISION_FOUNDED && $dispute->status === Dispute::STATUS_VENDOR_CONTACTED;
    $replacementDelivered = $replacement && $replacement->status === 'delivered';
    $clientFiles = $dispute->attachments->where('author_type', 'client');
    $vendorFiles = $dispute->attachments->where('author_type', 'vendor');
    $adminFiles = $dispute->attachments->where('author_type', 'admin');
    $vendorEvents = $dispute->events->where('type', 'evidence')->where('actor_type', 'vendor');
    $actors = ['client' => 'Client', 'vendor' => 'Vendeur', 'admin' => 'ASSO', 'system' => 'Système', 'partner' => 'Partenaire'];
@endphp
<div class="p-6 space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <a href="{{ route('admin.disputes.index') }}" class="text-sm text-gray-400 hover:text-white"><i class="fas fa-arrow-left mr-1"></i> Réclamations / Litiges</a>
            <h1 class="text-2xl font-bold text-white mt-1">Litige {{ $dispute->number }}</h1>
            <p class="text-gray-400 text-sm">Ouvert le {{ $dispute->created_at->displayTz()->format('d/m/Y à H:i') }} · Commande #{{ $order?->order_number }}</p>
        </div>
        <span class="px-3 py-1.5 rounded-full text-sm border border-primary-500/50 text-primary-300 bg-primary-500/10">{{ Dispute::STATUSES[$dispute->status] ?? $dispute->status }}</span>
    </div>

    @include('admin.wholesale_orders._flash')
    @include('admin.disputes._resolution')

    <div class="grid lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2 space-y-6">
            @if($dispute->refunded_at && !$dispute->closed_at)
                <p class="bg-green-900/20 p-4 text-green-300 rounded-lg">Client remboursé — retour de la marchandise en cours.</p>
            @endif
            <!-- Financier -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                @foreach([
                    ['Prix payé (quantité contestée)', $dispute->disputedTotal(), 'fa-tag'],
                    ['Part vendeur bloquée', $dispute->held_amount, 'fa-lock'],
                    ['Livraison initiale', $order?->deliveryPriceShown(), 'fa-truck'],
                    ['Remboursement', $dispute->refund_amount, 'fa-undo'],
                ] as [$label, $value, $icon])
                    <div class="bg-dark-100 rounded-xl border border-dark-200 p-4">
                        <p class="text-xs uppercase tracking-wider text-gray-500"><i class="fas {{ $icon }} mr-1"></i>{{ $label }}</p>
                        <p class="text-lg font-bold text-white mt-1">{{ $value !== null ? $fcfa($value) : '—' }}</p>
                    </div>
                @endforeach
            </div>

            <!-- Identification -->
            <div class="bg-dark-100 rounded-xl border border-dark-200 p-6">
                <h2 class="text-lg font-semibold text-white mb-4">Identification</h2>
                <div class="flex items-center gap-4 mb-4">
                    @if($item?->product?->primaryImage)
                        <img src="{{ media_url($item->product->primaryImage->image_path) }}" class="w-16 h-16 rounded-lg object-cover" alt="">
                    @endif
                    <div>
                        <p class="text-white">{{ $item?->product?->name ?? 'Produit supprimé' }}</p>
                        <p class="text-xs text-gray-500">{{ $item?->quantity }} × {{ $fcfa($item?->unit_price) }}
                            @if($item?->variant_attributes) · {{ collect($item->variant_attributes)->map(fn ($v, $k) => "$k : $v")->implode(', ') }}@endif
                        </p>
                    </div>
                </div>
                <div class="grid md:grid-cols-2 gap-4 text-sm">
                    <div>
                        <p class="text-gray-500 text-xs uppercase">Client</p>
                        <p class="text-white">{{ $dispute->client?->name ?? '—' }}</p>
                        <p class="text-gray-400">{{ $order?->customer_phone ?: $dispute->client?->phone }}</p>
                        <p class="text-gray-400 text-xs">{{ $order?->delivery_address }}</p>
                    </div>
                    <div>
                        <p class="text-gray-500 text-xs uppercase">Vendeur</p>
                        <p class="text-white">{{ $dispute->seller?->name ?? '—' }}</p>
                        <p class="text-gray-400">{{ $dispute->seller?->phone }}</p>
                        @if($dispute->isAssoManaged())<p class="text-primary-400 text-xs">Boutique ASSO : réponse et traitement depuis ce tableau de bord.</p>@endif
                    </div>
                    <div>
                        <p class="text-gray-500 text-xs uppercase">Paiement</p>
                        <p class="text-gray-300">{{ $order?->payment_method }} · {{ $fcfa($order?->total) }}</p>
                    </div>
                    <div>
                        <p class="text-gray-500 text-xs uppercase">Livraison initiale</p>
                        <p class="text-gray-300">{{ $order?->delivery_breakdown['company_name'] ?? $order?->deliveryCompany?->name ?? '—' }}</p>
                        <p class="text-gray-400 text-xs">Livrée le {{ $order?->delivered_at?->displayTz()->format('d/m/Y à H:i') ?? '—' }}</p>
                    </div>
                </div>
            </div>

            <!-- Preuves client -->
            <div class="bg-dark-100 rounded-xl border border-dark-200 p-6">
                <h2 class="text-lg font-semibold text-white mb-2">Preuves du client</h2>
                <p class="text-sm text-gray-400 mb-2">Motif : <span class="text-white">{{ $reasons[$dispute->reason] ?? $dispute->reason }}</span></p>
                <p class="text-sm text-gray-200 whitespace-pre-line bg-dark-200 rounded-lg p-3">{{ $dispute->description }}</p>
                @if($clientFiles->isNotEmpty())
                    <div class="flex flex-wrap gap-2 mt-3">
                        @foreach($clientFiles as $file)
                            <a href="{{ $file->url() }}" target="_blank"><img src="{{ $file->url() }}" class="w-24 h-24 rounded-lg object-cover border border-dark-200" alt=""></a>
                        @endforeach
                    </div>
                @endif
            </div>

            <!-- Réponse vendeur -->
            <div class="bg-dark-100 rounded-xl border border-dark-200 p-6">
                <h2 class="text-lg font-semibold text-white mb-2">Réponse du vendeur</h2>
                @if($dispute->vendor_response_due_at)
                    <p class="text-sm text-gray-400 mb-3">Réponse attendue avant le {{ $dispute->vendor_response_due_at->displayTz()->format('d/m/Y H:i') }}@if($dispute->vendor_responded_at) · réponse reçue @endif</p>
                @endif
                @if($dispute->isAssoManaged() && $dispute->isOpen())
                    <form method="POST" action="{{ route('admin.disputes.evidence', $dispute) }}" enctype="multipart/form-data" class="space-y-2 mb-4">
                        @csrf
                        <textarea name="note" rows="3" required placeholder="Réponse et justification de la boutique ASSO" class="w-full px-3 py-2 bg-dark-200 rounded-lg text-white"></textarea>
                        <input type="file" name="files[]" accept="image/*" multiple class="text-gray-400 text-xs">
                        <button class="px-4 py-2 bg-blue-600 text-white rounded-lg">Répondre à la réclamation</button>
                    </form>
                @endif
                @forelse($vendorEvents as $event)
                    <p class="text-sm text-gray-200 bg-dark-200 rounded-lg p-3 mb-2">{{ $event->note ?: '(pièces jointes)' }}
                        <span class="block text-xs text-gray-500 mt-1">{{ $event->occurred_at?->displayTz()->format('d/m/Y H:i') }}</span></p>
                    <div class="flex flex-wrap gap-2 mb-3">
                        @foreach($dispute->attachments->where('dispute_event_id', $event->id) as $file)
                            <a href="{{ $file->url() }}" target="_blank"><img src="{{ $file->url() }}" class="w-24 h-24 rounded-lg object-cover border border-dark-200" alt="Preuve jointe à la réponse"></a>
                        @endforeach
                    </div>
                @empty
                    <p class="text-sm text-gray-500">Aucune réponse du vendeur pour l'instant.</p>
                @endforelse

            </div>

            <!-- Logistique -->
            @foreach(array_filter(['Collecte pour échange' => $collection, 'Remplacement (Cas A)' => $replacement, 'Retour définitif (Cas B)' => $return]) as $title => $shipment)
                <div class="bg-dark-100 rounded-xl border border-blue-500/30 p-6 text-sm">
                    <h2 class="text-lg font-semibold text-white mb-2"><i class="fas fa-shipping-fast text-blue-400 mr-2"></i>{{ $title }}</h2>
                    <div class="grid md:grid-cols-2 gap-2 text-gray-300 mb-3">
                        <p>Partenaire : <span class="text-white">{{ $shipment->company?->name ?? 'à choisir' }}</span></p>
                        <p>Prix : <span class="text-white">{{ (float) $shipment->price > 0 ? $fcfa($shipment->price) : '—' }}</span> · payé par {{ $shipment->payer === 'asso' ? 'ASSO' : 'le vendeur' }}</p>
                        <p>Paiement : <span class="text-white">{{ ['pending' => 'En attente', 'paid' => 'Payé', 'failed' => 'Échoué'][$shipment->payment_status] ?? $shipment->payment_status }}</span>@if($shipment->payment_mode) ({{ $shipment->payment_mode }})@endif</p>
                        <p>N° de suivi : <span class="text-white">{{ $shipment->carrier_tracking_number ?? '—' }}</span></p>
                        <p>Livreur : <span class="text-white">{{ $shipment->deliveryPerson?->name ?? 'pas encore accepté' }}</span>@if($shipment->deliveryPerson?->phone) · {{ $shipment->deliveryPerson->phone }}@endif</p>
                        @if(!$shipment->isInternational())<p>Remise contre le code du <span class="text-white">{{ $shipment->codeHolder() === 'client' ? 'client' : 'vendeur' }}</span></p>@endif
                    </div>
                    @if($shipment->isInternational())
                        <p class="text-blue-200 mb-3">{{ $shipment->type === 'replacement' ? 'Vendeur → Solex → transporteur → client' : 'Client → transporteur → Solex → vendeur' }}. Le vendeur confirme personnellement la réception du retour. DHL/FedEx assure les étapes internationales.</p>
                        @foreach($shipment->quote['breakdown']['legs'] ?? [] as $leg)
                            <p class="text-gray-300">{{ $leg['label'] }} : {{ $fcfa($leg['price']) }} {{ ($shipment->quote['breakdown']['prices_exclude_vat'] ?? false) ? 'HT' : '' }}</p>
                        @endforeach
                        @if($shipment->type === 'replacement' && $shipment->isOpenForCourier())
                            <p class="text-gray-300">Code de dépôt Solex : <strong>{{ $shipment->confirmation_code }}</strong>. À remettre au collecteur uniquement à l’arrivée en agence.</p>
                        @endif
                    @endif
                    @if($shipment->payer === 'asso' && $shipment->codeHolder() === 'vendor' && $shipment->isOpenForCourier())
                        <p class="text-white mb-3">Code de réception boutique : <strong>{{ $shipment->confirmation_code }}</strong> — à communiquer au livreur à la remise du colis.</p>
                    @endif
                    @if($shipment->funding_error)
                        <p class="text-amber-300 mb-3">Financement en attente : {{ $shipment->funding_error }}</p>
                    @endif
                    <ol class="flex flex-wrap gap-2 mb-3">
                        @php $reached = true; @endphp
                        @foreach($shipment->steps() as $key => $label)
                            <li class="px-2 py-1 rounded text-xs border {{ $reached ? 'border-green-500/50 text-green-300' : 'border-dark-200 text-gray-500' }}">{{ $label }}</li>
                            @php if ($key === $shipment->status) { $reached = false; } @endphp
                        @endforeach
                    </ol>
                    @if($shipment->proof_path)
                        <a href="{{ media_url($shipment->proof_path) }}" target="_blank" class="text-primary-400 text-xs"><i class="fas fa-file-image mr-1"></i>Preuve de livraison</a>
                    @endif
                    @if($shipment->manualSteps())
                        <form method="POST" action="{{ route('admin.disputes.shipments.step', $shipment) }}" enctype="multipart/form-data" class="grid md:grid-cols-2 gap-2 mt-3">
                            @csrf
                            <select name="step" class="px-3 py-2 bg-dark-200 border border-dark-300 rounded-lg text-white">
                                @foreach($shipment->manualSteps() as $step)
                                    <option value="{{ $step }}">{{ $shipment->steps()[$step] }}</option>
                                @endforeach
                            </select>
                            @if((!$shipment->isInternational() || $shipment->type !== \App\Models\DisputeShipment::TYPE_REPLACEMENT) && in_array($shipment->codeStep(), $shipment->manualSteps(), true))
                            <p class="md:col-span-2 text-white font-semibold">{{ $shipment->codeHolder() === 'vendor' ? 'Réception en boutique — confirmer la remise du colis' : 'Remise du remplacement au client' }}</p>
                            <input type="text" name="confirmation_code" inputmode="numeric" maxlength="6" pattern="[0-9]{6}" required
                                   placeholder="Code du {{ $shipment->codeHolder() === 'client' ? 'client' : 'vendeur' }} (étape « {{ $shipment->steps()[$shipment->codeStep()] }} »)"
                                   class="px-3 py-2 bg-dark-200 border border-dark-300 rounded-lg text-white">
                            @endif
                            <input type="text" name="carrier_tracking_number" placeholder="N° de suivi (facultatif)" class="px-3 py-2 bg-dark-200 border border-dark-300 rounded-lg text-white">
                            <input type="text" name="note" placeholder="Note (facultatif)" class="px-3 py-2 bg-dark-200 border border-dark-300 rounded-lg text-white">
                            <input type="file" name="proof" accept="image/*" class="text-gray-400 text-xs">
                            <button class="md:col-span-2 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg"
                                    >
                                <i class="fas fa-check mr-1"></i> Enregistrer l'étape
                            </button>
                            @if(!$shipment->isInternational())<p class="md:col-span-2 text-xs text-gray-500">
                                Le livreur valide normalement la remise dans son application avec le code du {{ $shipment->codeHolder() === 'client' ? 'client' : 'vendeur' }}.
                                Saisissez-le ici seulement si le livreur ne peut pas le faire.
                                @if($shipment->type === 'return') La remise clôture le retour et déclenche le remboursement si celui-ci n’a pas déjà été effectué.@else @if($shipment->type === 'collection') La réception permet de préparer le remplacement, sans remboursement.@endif @endif
                            </p>@else<p class="md:col-span-2 text-xs text-gray-500">Enregistrez les confirmations du transporteur. La réception finale du retour doit être confirmée par le vendeur dans son espace.</p>@endif
                        </form>
                    @endif
                </div>
            @endforeach

            <!-- Historique -->
            <div class="bg-dark-100 rounded-xl border border-dark-200 p-6">
                <h2 class="text-lg font-semibold text-white mb-4">Historique</h2>
                <ol class="space-y-3">
                    @foreach($dispute->events as $event)
                        <li class="flex gap-3 text-sm {{ $event->internal ? 'bg-amber-500/5 rounded p-2' : '' }}">
                            <span class="text-gray-500 w-32 flex-shrink-0">{{ $event->occurred_at?->displayTz()->format('d/m/Y H:i') }}</span>
                            <span class="text-gray-200">
                                @if($event->internal)<span class="text-amber-400 text-xs mr-1">[interne]</span>@endif
                                {{ $event->label }}
                                <span class="text-xs text-gray-500">— {{ $actors[$event->actor_type] ?? $event->actor_type }}@if($event->actor) ({{ $event->actor->name }})@endif</span>
                                @if($event->note)<span class="block text-xs text-gray-400">{{ $event->note }}</span>@endif
                            </span>
                        </li>
                    @endforeach
                </ol>
            </div>
        </div>

        <div class="space-y-6">
            <!-- Décision ASSO -->
            <div class="bg-dark-100 rounded-xl border border-amber-500/40 p-6 text-sm space-y-3">
                <h2 class="text-lg font-semibold text-white"><i class="fas fa-gavel text-amber-400 mr-2"></i>Décision ASSO</h2>
                @if($dispute->decision)
                    <p class="text-gray-300">Décision : <span class="text-white">{{ $dispute->decision === 'founded' ? 'Fondée' : 'Non fondée' }}</span></p>
                    <p class="text-gray-400 text-xs">Par {{ $dispute->decider?->name ?? '—' }} le {{ $dispute->decided_at?->displayTz()->format('d/m/Y à H:i') }}</p>
                    @if($dispute->decision_note)<p class="text-gray-300 bg-dark-200 rounded p-2">{{ $dispute->decision_note }}</p>@endif
                @endif

                @if($dispute->status === Dispute::STATUS_NEW)
                    <form method="POST" action="{{ route('admin.disputes.review', $dispute) }}">
                        @csrf
                        <button class="w-full px-4 py-2 bg-dark-200 border border-amber-500/50 text-amber-200 rounded-lg"><i class="fas fa-search mr-1"></i> Prendre en analyse</button>
                    </form>
                @endif
                @if($undecided && !$dispute->isAssoManaged())
                    <form method="POST" action="{{ route('admin.disputes.contact-vendor', $dispute) }}" class="space-y-2">
                        @csrf
                        <textarea name="note" rows="2" placeholder="Ce qui est demandé au vendeur (facultatif)" class="w-full px-3 py-2 bg-dark-200 border border-dark-300 rounded-lg text-white text-sm"></textarea>
                        <button class="w-full px-4 py-2 bg-dark-200 border border-amber-500/50 text-amber-200 rounded-lg"><i class="fas fa-store mr-1"></i> Contacter le vendeur</button>
                    </form>
                @endif
                @if($undecided)
                    <form method="POST" action="{{ route('admin.disputes.decide', $dispute) }}" class="space-y-2">
                        @csrf
                        <textarea name="note" rows="3" required placeholder="Motif de la décision" class="w-full px-3 py-2 bg-dark-200 border border-dark-300 rounded-lg text-white text-sm">{{ old('note') }}</textarea>
                        <div class="grid grid-cols-2 gap-2">
                            <button name="decision" value="founded" class="px-4 py-2 bg-green-600 hover:bg-green-700 text-white font-semibold rounded-lg">FONDÉE</button>
                            <button name="decision" value="unfounded" class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white font-semibold rounded-lg"
                                    data-confirm="Réclamation non fondée : les fonds du vendeur seront débloqués et le litige clôturé. Continuer ?">NON FONDÉE</button>
                        </div>
                        <p class="text-xs text-gray-500">Non fondée : fonds vendeur débloqués, litige clôturé. Fondée : remplacement (une fois) ou retour.</p>
                    </form>
                @endif

                @if($replacement?->status === 'awaiting_preparation')
                    <p class="text-gray-300">Produit récupéré : contrôlez et préparez le remplacement.</p>
                    <form method="POST" action="{{ route('admin.disputes.replace', $dispute) }}">
                        @csrf
                        <button class="w-full px-4 py-2 bg-blue-600 text-white rounded-lg">Remplacement prêt — financer et livrer</button>
                    </form>
                    <form method="POST" action="{{ route('admin.disputes.return', $dispute) }}">
                        @csrf
                        <button class="w-full px-4 py-2 bg-dark-200 text-red-300 rounded-lg">Remplacement indisponible — rembourser</button>
                    </form>
                @endif
                @if($founded)
                    <div class="pt-3 border-t border-dark-200 space-y-2">
                        <p class="text-gray-300">{{ $dispute->isAssoManaged() ? 'ASSO, revendeur, choisit la suite :' : 'Le vendeur peut choisir dans son espace. ASSO peut également lancer le remplacement ou le retour ; les frais restent à la charge du vendeur.' }}</p>
                        @if($dispute->replacement_count < Dispute::MAX_REPLACEMENTS)
                            <form method="POST" action="{{ route('admin.disputes.replace', $dispute) }}">
                                @csrf
                                <button class="w-full px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg"><i class="fas fa-exchange-alt mr-1"></i> Remplacer le produit (Cas A)</button>
                            </form>
                        @endif
                        <form method="POST" action="{{ route('admin.disputes.return', $dispute) }}" class="space-y-2">
                            @csrf
                            <textarea name="note" rows="2" placeholder="Note (facultatif)" class="w-full px-3 py-2 bg-dark-200 border border-dark-300 rounded-lg text-white text-sm"></textarea>
                            <button class="w-full px-4 py-2 bg-dark-200 border border-red-500/50 text-red-300 rounded-lg"><i class="fas fa-undo mr-1"></i> Retour et remboursement (Cas B)</button>
                        </form>
                    </div>
                @endif
                @if($dispute->status === Dispute::STATUS_REPLACEMENT && $replacementDelivered)
                    <p class="text-xs text-gray-400 pt-3 border-t border-dark-200">Remplacement livré : contrôle client jusqu'au {{ $dispute->auto_validate_at?->displayTz()->format('d/m/Y à H:i') }}. Une nouvelle non-conformité impose le retour.</p>
                    <form method="POST" action="{{ route('admin.disputes.return', $dispute) }}">
                        @csrf
                        <button class="w-full px-4 py-2 bg-dark-200 border border-red-500/50 text-red-300 rounded-lg"><i class="fas fa-undo mr-1"></i> Remplacement non conforme → Cas B</button>
                    </form>
                @endif
            </div>

            <!-- Course en attente : choix du partenaire -->
            @if($pending)
                <div class="bg-dark-100 rounded-xl border border-blue-500/30 p-6 text-sm space-y-3">
                    <h2 class="text-lg font-semibold text-white">Course à payer</h2>
                    <p class="text-gray-400 text-xs">{{ $pending->payer === 'asso' ? 'Boutique ASSO : financement automatique ; une action manuelle permet de reprendre un échec.' : 'Le vendeur a reçu une notification lui demandant de choisir un partenaire et de payer (Wallet, Mobile Money, carte). Vous pouvez choisir le partenaire à sa place.' }}</p>
                    @if(!empty($quotes['partners']))
                        <form method="POST" action="{{ route('admin.disputes.shipments.partner', $pending) }}" class="space-y-2">
                            @csrf
                            <select name="partner" class="w-full px-3 py-2 bg-dark-200 border border-dark-300 rounded-lg text-white">
                                @foreach($quotes['partners'] as $partner)
                                    <option value="{{ json_encode(array_intersect_key($partner, array_flip(['company_id', 'zone_id', 'route_id', 'grid_id', 'vehicle']))) }}"
                                        @selected($pending->deliverer_company_id === $partner['company_id'])>
                                        {{ $partner['company_name'] }} — {{ $partner['route_label'] ?? '' }} — {{ $partner['formatted_delivery_price'] }}
                                    </option>
                                @endforeach
                            </select>
                            <button class="w-full px-4 py-2 bg-dark-200 border border-blue-500/50 text-blue-200 rounded-lg">Enregistrer le partenaire</button>
                        </form>
                    @else
                        <p class="text-red-300 text-xs">{{ $quotes['message'] ?? 'Aucun partenaire disponible pour cette adresse.' }}</p>
                    @endif
                    @if($pending->payer === 'asso' && $pending->deliverer_company_id && $pending->status !== 'awaiting_preparation')
                        <form method="POST" action="{{ route('admin.disputes.shipments.pay-by-asso', $pending) }}">
                            @csrf
                            <button class="w-full px-4 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg">Débiter ASSO et lancer la course ({{ $fcfa($pending->carrier_amount) }})</button>
                        </form>
                    @endif
                </div>
            @endif

            <!-- Virement IBAN par ASSO (commande payée par carte) -->
            @if(!empty($bankTransfers))
                <div class="bg-dark-100 rounded-xl border border-emerald-500/30 p-6 text-sm space-y-3">
                    <h2 class="text-lg font-semibold text-white"><i class="fas fa-university text-emerald-400 mr-2"></i>Virement bancaire (Stripe Connect)</h2>
                    <p class="text-gray-400 text-xs">Commande payée par carte : ASSO vire le montant depuis le Wallet du bénéficiaire vers son IBAN validé.</p>
                    @foreach($bankTransfers as $beneficiary => $transfer)
                        @php($who = $transfer['user'])
                        <div class="border-t border-dark-200 pt-3 space-y-1">
                            <p class="text-white">{{ $beneficiary === 'client' ? 'Remboursement client' : 'Part du vendeur' }} — {{ $fcfa($transfer['amount']) }}</p>
                            <p class="text-gray-400 text-xs">{{ $who->name }}@if($who->stripe_external_last4) · IBAN •••• {{ $who->stripe_external_last4 }}@endif</p>
                            @if($transfer['withdrawal'])
                                <p class="text-emerald-300 text-xs">Virement {{ $transfer['withdrawal']->transaction_reference }} — {{ number_format((float) $transfer['withdrawal']->payout_amount, 2, ',', ' ') }} {{ $transfer['withdrawal']->payout_currency }} ({{ $transfer['withdrawal']->status }})</p>
                            @elseif(!$transfer['ready'])
                                <p class="text-amber-300 text-xs">IBAN non synchronisé ou pas encore validé : le {{ $beneficiary === 'client' ? 'client' : 'vendeur' }} doit l'ajouter depuis son Wallet, puis il faut le valider dans Comptes de virement.</p>
                            @else
                                <form method="POST" action="{{ route('admin.disputes.bank-transfer', $dispute) }}"
                                      data-confirm="Virer {{ $fcfa($transfer['amount']) }} sur l'IBAN de {{ $who->name }} ?">
                                    @csrf
                                    <input type="hidden" name="beneficiary" value="{{ $beneficiary }}">
                                    <button class="w-full px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg"><i class="fas fa-paper-plane mr-1"></i> Virer sur l'IBAN</button>
                                </form>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif

            <!-- Pièces & notes internes -->
            <div class="bg-dark-100 rounded-xl border border-dark-200 p-6 text-sm space-y-3">
                <h2 class="text-lg font-semibold text-white">Notes internes</h2>
                <form method="POST" action="{{ route('admin.disputes.note', $dispute) }}" class="space-y-2">
                    @csrf
                    <textarea name="note" rows="2" required placeholder="Visible uniquement par l'équipe ASSO" class="w-full px-3 py-2 bg-dark-200 border border-dark-300 rounded-lg text-white text-sm"></textarea>
                    <button class="w-full px-4 py-2 bg-dark-200 border border-dark-300 text-gray-200 rounded-lg"><i class="fas fa-sticky-note mr-1"></i> Ajouter la note</button>
                </form>
                @if($dispute->isOpen())
                    <form method="POST" action="{{ route('admin.disputes.evidence', $dispute) }}" enctype="multipart/form-data" class="space-y-2 pt-3 border-t border-dark-200">
                        @csrf
                        <input type="file" name="files[]" accept="image/*" multiple class="text-gray-400 text-xs">
                        <input type="text" name="note" placeholder="Description de la pièce" class="w-full px-3 py-2 bg-dark-200 border border-dark-300 rounded-lg text-white text-sm">
                        <button class="w-full px-4 py-2 bg-dark-200 border border-dark-300 text-gray-200 rounded-lg"><i class="fas fa-paperclip mr-1"></i> Ajouter une pièce au dossier</button>
                    </form>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection
