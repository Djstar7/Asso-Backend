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
                    <dt class="text-gray-500">Partenaire</dt><dd class="text-white">{{ $d->company->name }}</dd>
                    <dt class="text-gray-500">QG de départ</dt><dd class="text-white">{{ $d->originHub->name }}, {{ $d->originHub->address }}, {{ $d->originHub->city }}</dd>
                    <dt class="text-gray-500">QG d'arrivée</dt><dd class="text-white">{{ $d->destinationHub->name }}, {{ $d->destinationHub->address }}, {{ $d->destinationHub->city }}</dd>
                    <dt class="text-gray-500">Départ</dt><dd class="text-white">{{ $d->departure_date->format('d/m/Y') }}</dd>
                    <dt class="text-gray-500">Dépôt avant le</dt><dd class="text-white">{{ $booking->dropoff_deadline->format('d/m/Y') }}</dd>
                    <dt class="text-gray-500">Poids / colis</dt><dd class="text-white">{{ (float) $booking->kg_booked }} kg · {{ $booking->parcels_count }} colis</dd>
                    <dt class="text-gray-500">Nature</dt><dd class="text-white">{{ $booking->parcelNature?->name ?? '—' }}</dd>
                    <dt class="text-gray-500">Valeur déclarée</dt><dd class="text-white">{{ $money($booking->declared_value) }}</dd>
                </dl>
            </div>

            <div class="bg-dark-100 rounded-xl border border-dark-200 p-6">
                <h2 class="text-lg font-semibold text-white mb-4">Montants</h2>
                <dl class="grid grid-cols-2 gap-x-6 gap-y-2 text-sm">
                    <dt class="text-gray-500">Fret partenaire</dt><dd class="text-white">{{ $money($booking->freight_subtotal) }}</dd>
                    <dt class="text-gray-500">Commission ASSO</dt><dd class="text-white">{{ $money($booking->commission_amount) }}</dd>
                    <dt class="text-gray-500">Assurance</dt><dd class="text-white">{{ $money($booking->insurance_amount) }}</dd>
                    <dt class="text-gray-500">Total payé</dt><dd class="text-white font-semibold">{{ $money($booking->total_price) }} ({{ $booking->payment_method }})</dd>
                    @if($booking->refunded_at)
                        <dt class="text-gray-500">Remboursé</dt><dd class="text-yellow-300">{{ number_format((float) $booking->refunded_amount, 0, ',', ' ') }} FCFA le {{ $booking->refunded_at->format('d/m/Y') }}</dd>
                    @endif
                </dl>
            </div>
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
                        <p class="text-xs mt-2 text-gray-500">Le fret s'ajoutera à ce qu'ASSO doit à {{ $d->company->name }}.</p>
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
