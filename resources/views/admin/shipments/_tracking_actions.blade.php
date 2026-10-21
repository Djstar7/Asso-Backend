@php
    $shipmentInput = 'w-full px-3 py-2 bg-dark-50 border border-dark-200 rounded-lg text-white text-sm';
    $nextShipmentSteps = \App\Services\AssoShipmentService::nextSteps($order);
@endphp
<div class="bg-dark-100 rounded-xl border border-dark-200 p-6" id="shipment-tracking">
    <h2 class="text-lg font-semibold text-white mb-3">Acheminement du colis · suivi ASSO</h2>
    <p class="text-sm text-gray-300 mb-3">ASSO suit le colis jusqu'à sa remise au client. Après réception à Douala, contactez le partenaire puis enregistrez sa réponse et la récupération physique du colis.</p>
    @if($order->deliveryCompany)
        <p class="text-sm text-gray-300 mb-3">Partenaire : {{ $order->deliveryCompany->name }} · {{ $order->deliveryCompany->phone }} · {{ $order->deliveryCompany->email }}</p>
    @endif
    @if($order->tracking_status)
        <p class="text-sm text-white mb-3">Dernière étape : {{ \App\Services\OrderTrackingService::stepLabel($order->tracking_status) }}</p>
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
            <form method="POST" action="{{ route('admin.shipments.step', $order) }}" class="grid grid-cols-1 md:grid-cols-2 gap-3">
                @csrf
                <label class="text-sm text-gray-300">Étape
                    <select name="step" class="{{ $shipmentInput }}" required>
                        @foreach($nextShipmentSteps as $step)
                            <option value="{{ $step }}" @selected(old('step') === $step)>{{ \App\Services\OrderTrackingService::stepLabel($step) }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="text-sm text-gray-300">N° de suivi transporteur
                    <input name="carrier_tracking_number" maxlength="100" value="{{ old('carrier_tracking_number', $order->carrier_tracking_number) }}" class="{{ $shipmentInput }}">
                </label>
                <label class="text-sm text-gray-300">Lieu
                    <input name="location" maxlength="150" value="{{ old('location') }}" class="{{ $shipmentInput }}">
                </label>
                <label class="text-sm text-gray-300">Note visible par le client
                    <input name="note" maxlength="500" value="{{ old('note') }}" class="{{ $shipmentInput }}">
                </label>
                <p class="md:col-span-2 text-xs text-gray-400">Pour les étapes du partenaire : renseignez ci-dessous le contact et la référence de votre échange ou du bordereau. Ces informations restent internes. « Demande transmise » enregistre un contact déjà effectué par votre équipe.</p>
                <label class="text-sm text-gray-300">Contact du partenaire
                    <input name="confirmation_contact" maxlength="150" value="{{ old('confirmation_contact') }}" class="{{ $shipmentInput }}">
                </label>
                <label class="text-sm text-gray-300">Référence de l'échange / preuve de remise
                    <input name="confirmation_reference" maxlength="150" value="{{ old('confirmation_reference') }}" class="{{ $shipmentInput }}">
                </label>
                <label class="text-sm text-gray-300">Canal de confirmation
                    <select name="confirmation_channel" class="{{ $shipmentInput }}">
                        <option value="">Choisir</option>
                        @foreach(['phone' => 'Téléphone', 'email' => 'E-mail', 'whatsapp' => 'WhatsApp', 'document' => 'Bordereau / document', 'in_person' => 'Sur place'] as $value => $label)
                            <option value="{{ $value }}" @selected(old('confirmation_channel') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <div class="md:col-span-2 text-right"><button class="px-4 py-2 bg-primary-500 text-white rounded-lg text-sm">Enregistrer et prévenir le client</button></div>
            </form>
        @endadminCan
    @elseif($order->status === 'pending')
        <p class="text-sm text-yellow-300">Confirmez d'abord la commande payée.</p>
    @endif
    @php($confirmations = $order->trackingEvents->filter(fn ($event) => filled($event->confirmation_reference)))
    @if($confirmations->isNotEmpty())
        <details class="mt-4 text-sm text-gray-300"><summary>Confirmations du partenaire · usage interne ASSO</summary>
            @foreach($confirmations as $confirmation)
                <p class="mt-2">{{ $confirmation->occurred_at->displayTz()->format('d/m/Y H:i') }} · {{ $confirmation->label }} · {{ $confirmation->confirmation_contact }} · {{ $confirmation->confirmation_channel }} · {{ $confirmation->confirmation_reference }} · employé ASSO #{{ $confirmation->actor_id }}</p>
            @endforeach
        </details>
    @endif
</div>
