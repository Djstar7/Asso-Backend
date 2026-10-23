@php
    $shipmentInput = 'w-full px-3 py-2 bg-dark-50 border border-dark-200 rounded-lg text-white text-sm';
    $nextShipmentSteps = \App\Services\AssoShipmentService::nextSteps($order);
    $shipmentDefaults = collect($nextShipmentSteps)->mapWithKeys(fn ($step) => [$step => \App\Services\AssoShipmentService::formDefaults($order, $step)])->all();
    $initialShipmentStep = in_array(old('step'), $nextShipmentSteps, true) ? old('step') : ($nextShipmentSteps[0] ?? '');
    $initialShipmentDefaults = $shipmentDefaults[$initialShipmentStep] ?? [];
    $displayPartnerDetails = \App\Services\AssoShipmentService::formDefaults($order, $initialShipmentStep ?: $order->tracking_status);

@endphp
<div class="bg-dark-100 rounded-xl border border-dark-200 p-6" id="shipment-tracking">
    <h2 class="text-lg font-semibold text-white mb-3">Acheminement du colis · suivi ASSO</h2>
    <p class="text-sm text-gray-300 mb-3">ASSO suit le colis jusqu'à sa remise au client. Après réception à Douala, la demande d’enlèvement est proposée aux livreurs synchronisés du partenaire. Le premier qui accepte est affecté ; le suivi reste accessible à ASSO.</p>
    @if($order->deliveryCompany)
        <div class="text-sm text-gray-300 mb-3 space-y-1">
            <p>Partenaire : {{ $order->deliveryCompany->name }}</p>
            @if($displayPartnerDetails['agency_name'])
                <p>Agence : {{ $displayPartnerDetails['agency_name'] }}</p>
            @endif
            <p>Téléphone : {{ $displayPartnerDetails['phone'] ?: 'À confirmer' }} · E-mail : {{ $displayPartnerDetails['email'] ?: 'À confirmer' }}</p>
        </div>
    @endif
    @if($order->tracking_status)
        <p class="text-sm text-white mb-3">Dernière étape : {{ \App\Services\OrderTrackingService::stepLabel($order->tracking_status) }}</p>
    @endif
    @if($order->carrier_courier_requested_at)
        <p class="text-sm text-green-300 mb-3">Mission ouverte aux livreurs synchronisés du partenaire.
            @if($order->delivery_person_id)
                Livreur affecté : {{ $order->deliveryPerson?->name ?? '#'.$order->delivery_person_id }}.
            @else
                En attente d’acceptation par un livreur.
            @endif
        </p>
        @if($order->isAgencyPickup() && $order->carrier_handover_code)
            <p class="text-sm text-gray-300 mb-3">Code de remise à l’agence : <strong>{{ $order->carrier_handover_code }}</strong>. À communiquer au réceptionnaire de l’agence ; le livreur le saisit à la remise du colis.</p>
        @endif
    @endif
    @if($order->isAgencyPickup())
        @if($agency = $order->pickup_agency_snapshot)
            <p class="text-sm text-gray-300 mb-3"><strong>{{ $agency['name'] }} · {{ $agency['code'] }}</strong><br>{{ $agency['address'] }}, {{ $agency['city'] }} · {{ $agency['phone'] }}</p>
        @endif
        @if(in_array($order->status, ['confirmed', 'preparing', 'shipped'], true) && (!$order->delivery_agency_id || !in_array($order->tracking_status, ['arrived', 'ready_for_pickup'], true)))
            @adminCan('admin.shipments.agency')
                <form method="POST" action="{{ route('admin.shipments.agency', $order) }}" class="mb-4 flex flex-wrap items-end gap-3">
                    @csrf
                    <label class="text-sm text-gray-300">Agence de retrait
                        <select name="delivery_agency_id" class="{{ $shipmentInput }}" required>
                            <option value="">Choisir une agence</option>
                            @foreach(\App\Models\DeliveryAgency::where('deliverer_company_id', $order->delivery_company_id)->where('is_active', true)->orderBy('city')->get() as $choice)
                                <option value="{{ $choice->id }}" @selected($order->delivery_agency_id === $choice->id)>{{ $choice->city }} · {{ $choice->name }}</option>
                            @endforeach
                        </select>
                    </label>
                    <button class="px-4 py-2 bg-primary-500 text-white rounded-lg text-sm">Affecter l'agence</button>
                </form>
                <p class="text-xs text-gray-400 mb-3">Créez les agences dans Partenaires logistiques.</p>
            @endadminCan
        @endif
    @endif
    @if($order->status === 'delivered')
        <p class="text-sm text-green-300">Remise enregistrée le {{ $order->delivered_at?->displayTz()->format('d/m/Y H:i') }}. {{ $order->confirmed_by_client_at ? 'Le client a confirmé sa réception.' : 'Confirmation personnelle du client attendue.' }}</p>
    @elseif($nextShipmentSteps)
        @adminCan('admin.shipments.step')
            <form method="POST" action="{{ route('admin.shipments.step', $order) }}" class="grid grid-cols-1 md:grid-cols-2 gap-3"
                  x-data="{
                    defaults: @js($shipmentDefaults), step: @js($initialShipmentStep),
                    location: @js(old('location', $initialShipmentDefaults['location'] ?? '')),
                    contact: @js(old('confirmation_contact', $initialShipmentDefaults['confirmation_contact'] ?? '')),
                    reference: @js(old('confirmation_reference', $initialShipmentDefaults['confirmation_reference'] ?? '')),
                    channel: @js(old('confirmation_channel', 'platform')),
                    applyStep() {
                        const values = this.defaults[this.step] || {};
                        this.location = values.location || '';
                        this.contact = values.confirmation_contact || '';
                        this.reference = values.confirmation_reference || '';
                        this.channel = values.confirmation_channel || 'platform';
                    }
                  }">
                @csrf
                <label class="text-sm text-gray-300">Étape
                    <select name="step" x-model="step" @change="applyStep()" class="{{ $shipmentInput }}" required>
                        @foreach($nextShipmentSteps as $step)
                            <option value="{{ $step }}" @selected($initialShipmentStep === $step)>{{ \App\Services\OrderTrackingService::stepLabel($step) }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="text-sm text-gray-300">N° de suivi transporteur
                    <input name="carrier_tracking_number" maxlength="100" value="{{ old('carrier_tracking_number', $order->carrier_tracking_number) }}" class="{{ $shipmentInput }}">
                </label>
                <label class="text-sm text-gray-300">Lieu
                    <input name="location" x-model="location" maxlength="150" value="{{ old('location', $initialShipmentDefaults['location'] ?? '') }}" class="{{ $shipmentInput }}">
                </label>
                <label class="text-sm text-gray-300">Note visible par le client
                    <input name="note" maxlength="500" value="{{ old('note') }}" class="{{ $shipmentInput }}">
                </label>
                <p class="md:col-span-2 text-xs text-gray-400">Les coordonnées et la référence du dossier sont chargées automatiquement depuis ASSO. Le canal « Données ASSO » désigne ce préremplissage et ne constitue pas une réponse externe du transporteur. Pour tracer un échange réel, choisissez son canal et indiquez sa référence. Les opérations physiques restent enregistrées par votre équipe.</p>
                <label class="text-sm text-gray-300">Contact du partenaire
                    <input name="confirmation_contact" x-model="contact" maxlength="150" value="{{ old('confirmation_contact', $initialShipmentDefaults['confirmation_contact'] ?? '') }}" class="{{ $shipmentInput }}">
                </label>
                <label class="text-sm text-gray-300">Référence du dossier / de l’échange
                    <input name="confirmation_reference" x-model="reference" maxlength="150" value="{{ old('confirmation_reference', $initialShipmentDefaults['confirmation_reference'] ?? '') }}" class="{{ $shipmentInput }}">
                </label>
                <label class="text-sm text-gray-300">Source des informations / canal de confirmation
                    <select name="confirmation_channel" x-model="channel" class="{{ $shipmentInput }}">
                        <option value="">Choisir</option>
                        @foreach(['platform' => 'Données ASSO (automatique)', 'phone' => 'Téléphone', 'email' => 'E-mail', 'whatsapp' => 'WhatsApp', 'document' => 'Bordereau / document', 'in_person' => 'Sur place'] as $value => $label)
                            <option value="{{ $value }}" @selected(old('confirmation_channel', 'platform') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <div class="md:col-span-2 text-right"><button class="px-4 py-2 bg-primary-500 text-white rounded-lg text-sm">Enregistrer et synchroniser la livraison</button></div>
            </form>
        @endadminCan
    @elseif($order->status === 'pending')
        <p class="text-sm text-yellow-300">Confirmez d'abord la commande payée.</p>
    @endif
    @php($confirmations = $order->trackingEvents->filter(fn ($event) => filled($event->confirmation_reference)))
    @if($confirmations->isNotEmpty())
        <details class="mt-4 text-sm text-gray-300"><summary>Traçabilité du partenaire · usage interne ASSO</summary>
            @foreach($confirmations as $confirmation)
                <p class="mt-2">{{ $confirmation->occurred_at->displayTz()->format('d/m/Y H:i') }} · {{ $confirmation->label }} · {{ $confirmation->confirmation_contact }} · {{ $confirmation->confirmation_channel === 'platform' ? 'Données ASSO (automatique)' : $confirmation->confirmation_channel }} · {{ $confirmation->confirmation_reference }} · employé ASSO #{{ $confirmation->actor_id }}</p>
            @endforeach
        </details>
    @endif
</div>
