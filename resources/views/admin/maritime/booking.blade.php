@extends('admin.layouts.app')

@php
    use App\Models\MaritimeBooking;
    $d = $booking->departure;
    $money = fn ($v) => number_format((float) $v, 2, ',', ' ') . ' ' . $booking->currency;
    $next = MaritimeBooking::NEXT_STEP[$booking->status] ?? null;
@endphp

@section('content')
<div class="p-6">
    <div class="mb-6">
        <a href="{{ route('admin.maritime.bookings.index') }}" class="text-sm text-gray-400 hover:text-white"><i class="fas fa-arrow-left mr-1"></i>Envois</a>
        <h1 class="text-2xl font-bold text-white mt-2">Envoi maritime #{{ $booking->id }}</h1>
        <p class="text-gray-400">{{ MaritimeBooking::STATUSES[$booking->status] ?? $booking->status }}</p>
    </div>

    @include('admin.maritime._flash')

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2 space-y-6">
            <div class="bg-dark-100 rounded-xl border border-dark-200 p-6">
                <h2 class="text-lg font-semibold text-white mb-4">Contacts</h2>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                    <div>
                        <p class="text-xs uppercase text-gray-500">Expéditeur</p>
                        <p class="text-white">{{ $booking->senderName() }}</p>
                        <p class="text-gray-300"><i class="fas fa-phone mr-1"></i>{{ $booking->sender_phone }}</p>
                        <p class="text-gray-300"><i class="fas fa-exclamation-circle mr-1"></i>Urgence : {{ $booking->emergency_phone }}</p>
                        <p class="text-gray-500 text-xs mt-1">Compte : {{ $booking->sender?->name }} {{ $booking->sender?->email }}</p>
                    </div>
                    <div>
                        <p class="text-xs uppercase text-gray-500">Destinataire</p>
                        <p class="text-white">{{ $booking->recipientName() }}</p>
                        <p class="text-gray-300"><i class="fas fa-phone mr-1"></i>{{ $booking->recipient_phone }}</p>
                        <p class="text-gray-300">{{ $countries[$booking->recipient_country] ?? $booking->recipient_country }}</p>
                    </div>
                </div>
            </div>

            <div class="bg-dark-100 rounded-xl border border-dark-200 p-6">
                <h2 class="text-lg font-semibold text-white mb-4">Colis et trajet</h2>
                <dl class="grid grid-cols-2 gap-x-6 gap-y-2 text-sm">
                    <dt class="text-gray-500">Partenaire</dt><dd class="text-white"><a href="{{ route('admin.maritime.partners.show', $d->company) }}" class="underline">{{ $d->company->name }}</a></dd>
                    <dt class="text-gray-500">QG de départ</dt><dd class="text-white">{{ $d->originHub->name }}, {{ $d->originHub->address }}, {{ $d->originHub->city }}</dd>
                    <dt class="text-gray-500">QG d'arrivée</dt><dd class="text-white">{{ $d->destinationHub->name }}, {{ $d->destinationHub->address }}, {{ $d->destinationHub->city }}</dd>
                    <dt class="text-gray-500">Départ</dt><dd class="text-white">{{ $d->departure_date->format('d/m/Y') }}</dd>
                    <dt class="text-gray-500">Dépôt avant le</dt><dd class="text-white">{{ $booking->dropoff_deadline->format('d/m/Y') }}</dd>
                    <dt class="text-gray-500">Poids / colis</dt><dd class="text-white">{{ (float) $booking->kg_booked }} kg{{ $booking->volume_cbm !== null ? ' (' . rtrim(rtrim(number_format((float) $booking->volume_cbm, 4, ',', ' '), '0'), ',') . ' m³)' : '' }} · {{ $booking->parcels_count }} colis</dd>
                    <dt class="text-gray-500">Nature</dt><dd class="text-white">{{ $booking->parcelNature?->name ?? '—' }}</dd>
                    <dt class="text-gray-500">Valeur déclarée</dt><dd class="text-white">{{ $money($booking->declared_value) }}</dd>
                    <dt class="text-gray-500">Assurance facultative</dt><dd class="text-white">{{ $booking->insurance_opt_in ? 'Souscrite' : 'Refusée' }} · {{ $booking->insurance_status }}</dd>
                    <dt class="text-gray-500">Taux enregistré</dt><dd class="text-white">{{ number_format((float) $booking->insurance_rate_applied, 2, ',', ' ') }} %</dd>
                    <dt class="text-gray-500">Devis indicatif</dt><dd class="text-white">{{ $money($booking->insurance_quote_amount) }}</dd>
                </dl>
            </div>

            <div class="bg-dark-100 rounded-xl border border-dark-200 p-6">
                <h2 class="text-lg font-semibold text-white mb-4">Montants</h2>
                <dl class="grid grid-cols-2 gap-x-6 gap-y-2 text-sm">
                    <dt class="text-gray-500">Fret partenaire</dt><dd class="text-white">{{ $money($booking->freight_subtotal) }}</dd>
                    <dt class="text-gray-500">Assurance effectivement payée</dt><dd class="text-white">{{ $money($booking->insurance_amount) }}</dd>
                    <dt class="text-gray-500">Commission ASSO</dt><dd class="text-white">{{ $money($booking->commission_amount) }}</dd>
                    <dt class="text-gray-500">Total payé</dt>
                        <dd class="text-white font-semibold">
                            {{ $money($booking->total_price) }}
                            ({{ $booking->payment_method }})
                        </dd>
                    @if($booking->refunded_at)
                        <dt class="text-gray-500">Remboursé</dt><dd class="text-yellow-300">{{ number_format((float) $booking->refunded_amount, 0, ',', ' ') }} FCFA le {{ $booking->refunded_at->format('d/m/Y') }}</dd>
                    @endif
                </dl>
            </div>

            @if($booking->indemnityClaim)
                @php $claim = $booking->indemnityClaim; @endphp
                <div class="bg-dark-100 rounded-xl border border-dark-200 p-6">
                    <h2 class="text-lg font-semibold text-white mb-3">Demande d’indemnisation #{{ $claim->id }} · {{ $claim->status }}</h2>
                    <p class="text-sm text-gray-300 whitespace-pre-line"><strong>Déclaration client :</strong> {{ $claim->customer_reason }}</p>
                    @if($claim->loss_confirmation_reason)<p class="text-sm text-gray-300 mt-2"><strong>Confirmation de perte :</strong> {{ $claim->loss_confirmation_reason }}</p>@endif
                    @if($claim->decision_reason)<p class="text-sm text-gray-300 mt-2"><strong>Décision :</strong> {{ $claim->decision_reason }}</p>@endif
                    @if($claim->paid_at)<p class="text-sm text-green-300 mt-2">Versé : {{ number_format((float) $claim->payout_amount_xaf, 0, ',', ' ') }} XAF le {{ $claim->paid_at->format('d/m/Y H:i') }}</p>@endif

                    @if($claim->status === 'reported')
                        <form action="{{ route('admin.maritime.claims.confirm-loss', $claim) }}" method="POST" class="mt-4 space-y-2">
                            @csrf
                            <textarea name="reason" required minlength="5" maxlength="5000" placeholder="Motif / constat de confirmation de perte" class="w-full px-3 py-2 bg-dark-50 border border-dark-200 rounded-lg text-white"></textarea>
                            <button class="px-4 py-2 bg-yellow-600 text-white rounded-lg">Confirmer la perte</button>
                        </form>
                    @elseif($claim->status === 'loss_confirmed')
                        @foreach(['approve' => 'Approuver', 'refuse' => 'Refuser'] as $decision => $label)
                            <form action="{{ route('admin.maritime.claims.review', $claim) }}" method="POST" class="mt-3 space-y-2">
                                @csrf
                                <input type="hidden" name="decision" value="{{ $decision }}">
                                <textarea name="reason" required minlength="5" maxlength="5000" placeholder="Motif de la décision" class="w-full px-3 py-2 bg-dark-50 border border-dark-200 rounded-lg text-white"></textarea>
                                <button class="px-4 py-2 {{ $decision === 'approve' ? 'bg-green-600' : 'bg-red-600' }} text-white rounded-lg">{{ $label }}</button>
                            </form>
                        @endforeach
                    @elseif($claim->status === 'approved')
                        @if($indemnityCapXaf)
                            <p class="text-sm text-gray-400 mt-3">Plafond global actuel : {{ number_format((float) $indemnityCapXaf, 0, ',', ' ') }} XAF. Le montant sera calculé par le serveur à partir de la valeur déclarée convertie, plafonné à ce montant.</p>
                            <button type="button"
                                    class="mt-3 px-4 py-2 bg-green-600 text-white rounded-lg"
                                    data-payout-proposal-url="{{ route('admin.maritime.claims.payout-proposal', $claim) }}"
                                    data-payout-form-url="{{ route('admin.maritime.claims.pay', $claim) }}"
                                    onclick="showMaritimePayoutConfirmation(this)">
                                Prévisualiser et confirmer le versement
                            </button>
                            <dialog id="maritime-payout-confirmation" class="w-full max-w-lg rounded-xl bg-dark-100 p-0 text-white shadow-2xl backdrop:bg-black/70">
                                <form id="maritime-payout-form" method="POST" class="space-y-4 p-6">
                                    @csrf
                                    <h3 class="text-xl font-semibold">Confirmer l’indemnisation</h3>
                                    <p class="text-sm text-gray-300">Vérifiez les informations et indiquez le motif avant de confirmer.</p>
                                    <dl class="grid grid-cols-2 gap-2 text-sm">
                                        <dt class="text-gray-400">Client</dt><dd id="payout-client"></dd>
                                        <dt class="text-gray-400">Référence</dt><dd id="payout-booking-reference"></dd>
                                        <dt class="text-gray-400">Valeur déclarée</dt><dd id="payout-declared-value"></dd>
                                        <dt class="text-gray-400">Montant proposé par le serveur</dt><dd id="payout-amount" class="font-semibold text-green-300"></dd>
                                    </dl>
                                    <input type="hidden" name="confirmed_proposal_xaf" id="payout-confirmed-amount">
                                    <label class="block text-sm text-gray-300">
                                        Motif du versement
                                        <textarea name="reason" required minlength="5" maxlength="5000" class="mt-1 w-full rounded-lg border border-dark-200 bg-dark-50 px-3 py-2 text-white"></textarea>
                                    </label>
                                    <div class="flex justify-end gap-2">
                                        <button type="button" onclick="document.getElementById('maritime-payout-confirmation').close()" class="rounded-lg bg-dark-200 px-4 py-2">Annuler</button>
                                        <button id="payout-confirm-submit" type="submit" class="rounded-lg bg-green-600 px-4 py-2">Confirmer le versement</button>
                                    </div>
                                </form>
                            </dialog>
                            <script>
                                async function showMaritimePayoutConfirmation(button) {
                                    button.disabled = true;
                                    try {
                                        const response = await fetch(button.dataset.payoutProposalUrl, {
                                            headers: { 'Accept': 'application/json' },
                                            credentials: 'same-origin',
                                        });
                                        const payload = await response.json();
                                        if (!response.ok || !payload.success) {
                                            throw new Error(payload.message || 'Impossible de calculer la proposition.');
                                        }
                                        const proposal = payload.data.proposal;
                                        const money = value => new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 2 }).format(value);
                                        document.getElementById('payout-client').textContent = proposal.client || '—';
                                        document.getElementById('payout-booking-reference').textContent = proposal.booking_reference;
                                        document.getElementById('payout-declared-value').textContent =
                                            `${money(proposal.declared_value)} ${proposal.declared_value_currency} (${money(proposal.declared_value_xaf)} XAF)`;
                                        document.getElementById('payout-amount').textContent = `${money(proposal.amount_xaf)} XAF`;
                                        document.getElementById('payout-confirmed-amount').value = proposal.amount_xaf;
                                        document.getElementById('maritime-payout-form').action = button.dataset.payoutFormUrl;
                                        document.getElementById('maritime-payout-confirmation').showModal();
                                    } catch (error) {
                                        window.alert(error.message);
                                    } finally {
                                        button.disabled = false;
                                    }
                                }
                            </script>
                        @else
                            <p class="text-sm text-yellow-300 mt-3">Configurez le plafond global dans les réglages maritimes avant tout versement.</p>
                        @endif
                    @endif

                    <h3 class="text-sm font-semibold text-white mt-5 mb-2">Historique administratif et financier</h3>
                    <ol class="space-y-2 text-sm">
                        @foreach($claim->histories as $history)
                            <li class="border-l-2 border-primary-500 pl-3 text-gray-300">
                                <strong>{{ $history->action }}</strong> : {{ $history->from_status ?? '—' }} → {{ $history->to_status }}
                                · {{ $history->actor?->name ?? 'Client / système' }} · {{ $history->created_at->format('d/m/Y H:i') }}
                                @if($history->reason)<br>{{ $history->reason }}@endif
                                @if($history->amount_xaf !== null)<br>{{ number_format((float) $history->amount_xaf, 0, ',', ' ') }} XAF · {{ $history->operation_reference }}@endif
                            </li>
                        @endforeach
                    </ol>
                </div>
            @endif

            @if($booking->insuranceHistory->isNotEmpty())
                <div class="bg-dark-100 rounded-xl border border-dark-200 p-6">
                    <h2 class="text-lg font-semibold text-white mb-3">Historique assurance</h2>
                    <ul class="space-y-2 text-sm text-gray-300">
                        @foreach($booking->insuranceHistory as $history)
                            <li>{{ $history->created_at->format('d/m/Y H:i') }} · {{ $history->event }} · {{ $history->status }}@if($history->reason) · {{ $history->reason }}@endif</li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>

        <div class="space-y-6">
            <div class="bg-dark-100 rounded-xl border border-dark-200 p-6">
                <h2 class="text-lg font-semibold text-white mb-4">Suivi</h2>
                <ul class="text-sm space-y-2 mb-4">
                    <li class="text-gray-300">Payé : {{ $booking->paid_at?->format('d/m/Y H:i') ?? '—' }}</li>
                    <li class="text-gray-300">Déposé au QG : {{ $booking->dropped_off_at?->format('d/m/Y H:i') ?? '—' }}</li>
                    <li class="text-gray-300">Embarqué : {{ $booking->shipped_at?->format('d/m/Y H:i') ?? '—' }}</li>
                    <li class="text-gray-300">Arrivé : {{ $booking->arrived_at?->format('d/m/Y H:i') ?? '—' }}</li>
                    <li class="text-gray-300">Remis : {{ $booking->completed_at?->format('d/m/Y H:i') ?? '—' }}</li>
                    @if($booking->cancelled_at)<li class="text-red-300">Annulé : {{ $booking->cancelled_at->format('d/m/Y H:i') }} ({{ $booking->cancel_reason }})</li>@endif
                </ul>

                @if($next)
                    <form action="{{ route('admin.maritime.bookings.advance', $booking) }}" method="POST">
                        @csrf
                        <button type="submit" class="w-full px-4 py-2 bg-gradient-to-r from-primary-500 to-primary-600 text-white rounded-lg hover:shadow-lg">
                            Marquer : {{ MaritimeBooking::STATUSES[$next] }}
                        </button>
                    </form>
                @endif

                @if($booking->status === MaritimeBooking::STATUS_ARRIVED)
                    <form action="{{ route('admin.maritime.bookings.complete', $booking) }}" method="POST" class="mt-4">
                        @csrf
                        <label class="block text-sm text-gray-300 mb-1">Code remis par le destinataire</label>
                        <div class="flex gap-2">
                            <input name="confirmation_code" maxlength="6" required class="flex-1 px-3 py-2 bg-dark-50 border border-dark-200 rounded-lg text-white tracking-widest">
                            <button type="submit" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700">Remettre</button>
                        </div>
                        <p class="text-xs mt-2 text-gray-500">Le fret sera crédité sur le Wallet de {{ $d->company->name }}.</p>
                    </form>
                @endif
            </div>

            <div class="bg-dark-100 rounded-xl border border-dark-200 p-6">
                <h2 class="text-lg font-semibold text-white mb-2">Notes internes</h2>
                <form action="{{ route('admin.maritime.bookings.notes', $booking) }}" method="POST">
                    @csrf
                    <textarea name="admin_notes" rows="4" class="w-full px-3 py-2 bg-dark-50 border border-dark-200 rounded-lg text-white">{{ $booking->admin_notes }}</textarea>
                    <button type="submit" class="mt-2 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">Enregistrer</button>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
