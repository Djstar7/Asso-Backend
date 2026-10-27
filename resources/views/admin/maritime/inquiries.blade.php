@extends('admin.layouts.app')
@section('content')
<div class="p-6 text-gray-300">
    <h1 class="text-2xl text-white font-bold mb-4">Devis maritimes Chine → autres pays</h1>
    @include('admin.maritime._flash')
    <p class="mb-4">Discutez avec le client dans la messagerie, puis choisissez un départ réel du partenaire. Le montant proposé couvre le fret ; l’assurance facultative sera ajoutée au paiement. Un devis accepté est figé.</p>
    @foreach($inquiries as $inquiry)
        <article class="bg-dark-100 border border-dark-200 rounded-xl p-4 mb-4">
            <h2 class="text-white font-bold">#{{ $inquiry->id }} · {{ $inquiry->user->name }} · Chine → {{ $inquiry->destination_country }} · {{ $inquiry->status }}</h2>
            <p>{{ $inquiry->volume_cbm ?? (float) $inquiry->kg / 1000 }} CBM · {{ $inquiry->cargo_category }} · <a class="text-primary-400" href="{{ route('admin.messages.show', $inquiry->conversation_id) }}">Ouvrir la discussion #{{ $inquiry->conversation_id }}</a></p>
            <p class="my-2 whitespace-pre-line">{{ $inquiry->description }}</p>
            @if($inquiry->freight_amount)<p>Devis : {{ number_format($inquiry->freight_amount, 2, ',', ' ') }} {{ $inquiry->currency }} · Coût partenaire : {{ number_format($inquiry->partner_amount, 2, ',', ' ') }} {{ $inquiry->currency }} · Valable jusqu’au {{ $inquiry->expires_at }}</p>@endif
            @if(in_array($inquiry->status, ['pending', 'quoted']))
                <form method="POST" action="{{ route('admin.maritime.inquiries.quote', $inquiry->id) }}" class="flex flex-wrap gap-3 mt-3">
                    @csrf
                    <label>Départ réel
                        <select name="maritime_departure_id" required class="bg-dark-50 border rounded p-2">
                            <option value="">Choisir</option>
                            @foreach($departures->filter(fn($d) => $d->destinationHub->country === $inquiry->destination_country) as $departure)
                                <option value="{{ $departure->id }}">#{{ $departure->id }} · {{ $departure->company->name }} · {{ $departure->departure_date->toDateString() }} · {{ $departure->currency }} · {{ $departure->freight_rate_snapshot['cargo_category'] ?? 'general' }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label>Fret total client (devise du départ)<input class="bg-dark-50 border rounded p-2" type="number" name="freight_amount" min="0.01" step="0.01" required value="{{ $inquiry->freight_amount }}"></label>
                    <label>Expiration<input class="bg-dark-50 border rounded p-2" type="datetime-local" name="expires_at" required></label>
                    <button class="bg-primary-500 text-white rounded px-4 py-2">Proposer le devis</button>
                </form>
            @endif
            @if($inquiry->maritime_booking_id)<a href="{{ route('admin.maritime.bookings.show', $inquiry->maritime_booking_id) }}" class="text-primary-400">Suivre l’envoi #{{ $inquiry->maritime_booking_id }}</a>@endif
        </article>
    @endforeach
    {{ $inquiries->links() }}
</div>
@endsection
