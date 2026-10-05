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
            <p class="text-gray-400 text-sm">Ouvert le {{ $dispute->created_at->format('d/m/Y à H:i') }} · Commande #{{ $order?->order_number }}</p>
        </div>
        <span class="px-3 py-1.5 rounded-full text-sm border border-primary-500/50 text-primary-300 bg-primary-500/10">{{ Dispute::STATUSES[$dispute->status] ?? $dispute->status }}</span>
    </div>

    @include('admin.wholesale_orders._flash')

    <div class="grid lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2 space-y-6">
            <!-- Financier -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                @foreach([
                    ['Prix payé (article)', $item?->total_price, 'fa-tag'],
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
                        @if($order?->is_wholesale)<p class="text-primary-400 text-xs">Import en gros : ASSO est le revendeur et gère le dossier.</p>@endif
                    </div>
                    <div>
                        <p class="text-gray-500 text-xs uppercase">Paiement</p>
                        <p class="text-gray-300">{{ $order?->payment_method }} · {{ $fcfa($order?->total) }}</p>
                    </div>
                    <div>
                        <p class="text-gray-500 text-xs uppercase">Livraison initiale</p>
                        <p class="text-gray-300">{{ $order?->delivery_breakdown['company_name'] ?? $order?->deliveryCompany?->name ?? '—' }}</p>
                        <p class="text-gray-400 text-xs">Livrée le {{ $order?->delivered_at?->format('d/m/Y à H:i') ?? '—' }}</p>
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
                @forelse($vendorEvents as $event)
                    <p class="text-sm text-gray-200 bg-dark-200 rounded-lg p-3 mb-2">{{ $event->note ?: '(pièces jointes)' }}
                        <span class="block text-xs text-gray-500 mt-1">{{ $event->occurred_at?->format('d/m/Y H:i') }}</span></p>
                @empty
                    <p class="text-sm text-gray-500">Aucune réponse du vendeur pour l'instant.</p>
                @endforelse
                @if($vendorFiles->isNotEmpty() || $adminFiles->isNotEmpty())
                    <div class="flex flex-wrap gap-2 mt-3">
                        @foreach($vendorFiles->concat($adminFiles) as $file)
                            <a href="{{ $file->url() }}" target="_blank" title="{{ $actors[$file->author_type] ?? '' }}"><img src="{{ $file->url() }}" class="w-24 h-24 rounded-lg object-cover border border-dark-200" alt=""></a>
                        @endforeach
                    </div>
                @endif
            </div>

            <!-- Logistique -->
            @foreach(array_filter(['Remplacement (Cas A)' => $replacement, 'Retour (Cas B)' => $return]) as $title => $shipment)
                <div class="bg-dark-100 rounded-xl border border-blue-500/30 p-6 text-sm">
                    <h2 class="text-lg font-semibold text-white mb-2"><i class="fas fa-shipping-fast text-blue-400 mr-2"></i>{{ $title }}</h2>
                    <div class="grid md:grid-cols-2 gap-2 text-gray-300 mb-3">
                        <p>Partenaire : <span class="text-white">{{ $shipment->company?->name ?? 'à choisir' }}</span></p>
                        <p>Prix : <span class="text-white">{{ (float) $shipment->price > 0 ? $fcfa($shipment->price) : '—' }}</span> · payé par {{ $shipment->payer === 'asso' ? 'ASSO' : 'le vendeur' }}</p>
                        <p>Paiement : <span class="text-white">{{ ['pending' => 'En attente', 'paid' => 'Payé', 'failed' => 'Échoué'][$shipment->payment_status] ?? $shipment->payment_status }}</span>@if($shipment->payment_mode) ({{ $shipment->payment_mode }})@endif</p>
                        <p>N° de suivi : <span class="text-white">{{ $shipment->carrier_tracking_number ?? '—' }}</span></p>
                    </div>
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
                    @if($shipment->nextSteps())
                        <form method="POST" action="{{ route('admin.disputes.shipments.step', $shipment) }}" enctype="multipart/form-data" class="grid md:grid-cols-2 gap-2 mt-3">
                            @csrf
                            <select name="step" class="px-3 py-2 bg-dark-200 border border-dark-300 rounded-lg text-white">
                                @foreach($shipment->nextSteps() as $step)
                                    <option value="{{ $step }}">{{ $shipment->steps()[$step] }}</option>
                                @endforeach
                            </select>
                            <input type="text" name="carrier_tracking_number" placeholder="N° de suivi (facultatif)" class="px-3 py-2 bg-dark-200 border border-dark-300 rounded-lg text-white">
                            <input type="text" name="note" placeholder="Note (facultatif)" class="px-3 py-2 bg-dark-200 border border-dark-300 rounded-lg text-white">
                            <input type="file" name="proof" accept="image/*" class="text-gray-400 text-xs">
                            <button class="md:col-span-2 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg"
                                    onclick="return this.form.step.value !== 'confirmed' || confirm('Confirmer le retour ? Le client sera remboursé sur son Wallet ASSO.')">
                                <i class="fas fa-check mr-1"></i> Enregistrer l'étape
                            </button>
                            @if($shipment->type === 'return')
                                <p class="md:col-span-2 text-xs text-gray-500">« Retour confirmé » rembourse le client, même sans confirmation du vendeur.</p>
                            @endif
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
                            <span class="text-gray-500 w-32 flex-shrink-0">{{ $event->occurred_at?->format('d/m/Y H:i') }}</span>
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
                    <p class="text-gray-400 text-xs">Par {{ $dispute->decider?->name ?? '—' }} le {{ $dispute->decided_at?->format('d/m/Y à H:i') }}</p>
                    @if($dispute->decision_note)<p class="text-gray-300 bg-dark-200 rounded p-2">{{ $dispute->decision_note }}</p>@endif
                @endif

                @if($dispute->status === Dispute::STATUS_NEW)
                    <form method="POST" action="{{ route('admin.disputes.review', $dispute) }}">
                        @csrf
                        <button class="w-full px-4 py-2 bg-dark-200 border border-amber-500/50 text-amber-200 rounded-lg"><i class="fas fa-search mr-1"></i> Prendre en analyse</button>
                    </form>
                @endif
                @if($undecided && !$order?->is_wholesale)
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
                                    onclick="return confirm('Réclamation non fondée : les fonds du vendeur seront débloqués et le litige clôturé. Continuer ?')">NON FONDÉE</button>
                        </div>
                        <p class="text-xs text-gray-500">Non fondée : fonds vendeur débloqués, litige clôturé. Fondée : remplacement (une fois) ou retour.</p>
                    </form>
                @endif

                @if($founded)
                    <div class="pt-3 border-t border-dark-200 space-y-2">
                        <p class="text-gray-300">{{ $order?->is_wholesale ? 'ASSO, revendeur, choisit la suite :' : 'Le vendeur choisit dans son espace. ASSO peut imposer le retour :' }}</p>
                        @if($order?->is_wholesale && $dispute->replacement_count < Dispute::MAX_REPLACEMENTS)
                            <form method="POST" action="{{ route('admin.disputes.replace', $dispute) }}">
                                @csrf
                                <button class="w-full px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg"><i class="fas fa-exchange-alt mr-1"></i> Remplacer le produit</button>
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
                    <p class="text-xs text-gray-400 pt-3 border-t border-dark-200">Remplacement livré : contrôle client jusqu'au {{ $dispute->auto_validate_at?->format('d/m/Y à H:i') }}. Une nouvelle non-conformité impose le retour.</p>
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
                    <p class="text-gray-400 text-xs">{{ $pending->payer === 'asso' ? 'Import en gros : ASSO prend la course à sa charge.' : 'Le vendeur a reçu une notification lui demandant de choisir un partenaire et de payer (Wallet, Mobile Money, carte). Vous pouvez choisir le partenaire à sa place.' }}</p>
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
                    @if($pending->payer === 'asso' && $pending->deliverer_company_id)
                        <form method="POST" action="{{ route('admin.disputes.shipments.pay-by-asso', $pending) }}">
                            @csrf
                            <button class="w-full px-4 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg">Prise en charge par ASSO ({{ $fcfa($pending->price) }})</button>
                        </form>
                    @endif
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
