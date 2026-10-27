@extends('admin.layouts.app')

@section('content')
<div class="p-6">
    <div class="mb-6">
        <a href="{{ route('admin.maritime.index') }}" class="text-sm text-gray-400 hover:text-white"><i class="fas fa-arrow-left mr-1"></i>Départs</a>
        <h1 class="text-2xl font-bold text-white mt-2">Envois maritimes</h1>
        <p class="text-gray-400">Envois payés. Contactez l'expéditeur après le paiement, puis enregistrez chaque étape : il est prévenu à chaque fois.</p>
    </div>

    @include('admin.maritime._flash')

    <form method="GET" class="mb-4 flex flex-wrap gap-2">
        @if(request('departure'))<input type="hidden" name="departure" value="{{ request('departure') }}">@endif
        <a href="{{ route('admin.maritime.bookings.index', array_filter(['departure' => request('departure')])) }}"
           class="px-3 py-1.5 rounded-lg text-sm {{ request('status') ? 'bg-dark-200 text-gray-300' : 'bg-primary-600 text-white' }}">Tous</a>
        @foreach($statuses as $value => $label)
            @continue($value === 'pending')
            <button name="status" value="{{ $value }}" class="px-3 py-1.5 rounded-lg text-sm {{ request('status') === $value ? 'bg-primary-600 text-white' : 'bg-dark-200 text-gray-300' }}">{{ $label }}</button>
        @endforeach
        <select name="claim_status" class="px-3 py-1.5 rounded-lg bg-dark-200 text-gray-300" onchange="this.form.submit()">
            <option value="">Toutes les demandes d’indemnisation</option>
            @foreach($claimStatuses as $value => $label)
                <option value="{{ $value }}" @selected(request('claim_status') === $value)>{{ $label }}</option>
            @endforeach
        </select>
    </form>

    <div class="bg-dark-100 rounded-xl shadow-lg border border-dark-200 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-dark-50">
                    <tr class="text-left text-xs font-semibold text-white uppercase tracking-wider">
                        <th class="px-4 py-3">#</th><th class="px-4 py-3">Expéditeur</th><th class="px-4 py-3">Trajet</th><th class="px-4 py-3">Volume / poids</th>
                        <th class="px-4 py-3">Total</th><th class="px-4 py-3">Dépôt avant</th><th class="px-4 py-3">Statut</th><th class="px-4 py-3">Indemnisation</th><th></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-dark-200 text-gray-300">
                    @forelse($bookings as $b)
                        <tr>
                            <td class="px-4 py-3">{{ $b->id }}</td>
                            <td class="px-4 py-3"><span class="text-white">{{ $b->senderName() }}</span><br><span class="text-xs">{{ $b->sender_phone }}</span></td>
                            <td class="px-4 py-3">{{ $b->departure->originHub->city }} → {{ $b->departure->destinationHub->city }}<br><span class="text-xs">départ {{ $b->departure->departure_date->format('d/m/Y') }}</span></td>
                            <td class="px-4 py-3 whitespace-nowrap">{{ ($b->departure->freight_rate_snapshot['transport_mode'] ?? 'sea') === 'sea' ? ((float) $b->volume_cbm ?: (float) $b->kg_booked / 1000) : (float) $b->kg_booked }} {{ ($b->departure->freight_rate_snapshot['transport_mode'] ?? 'sea') === 'sea' ? 'CBM' : 'kg' }} · {{ $b->parcels_count }} colis</td>
                            <td class="px-4 py-3 whitespace-nowrap">{{ number_format((float) $b->total_price, 2, ',', ' ') }} {{ $b->currency }}</td>
                            <td class="px-4 py-3 whitespace-nowrap {{ $b->status === 'paid' && $b->dropoff_deadline->isPast() ? 'text-red-300' : '' }}">{{ $b->dropoff_deadline->format('d/m/Y') }}</td>
                            <td class="px-4 py-3">{{ $statuses[$b->status] ?? $b->status }}</td>
                            <td class="px-4 py-3">{{ $claimStatuses[$b->indemnityClaim?->status] ?? '—' }}</td>
                            <td class="px-4 py-3 text-right"><a href="{{ route('admin.maritime.bookings.show', $b) }}" class="px-3 py-1.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700">Ouvrir</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="px-4 py-8 text-center text-gray-400">Aucun envoi.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($bookings->hasPages())<div class="px-6 py-4 border-t border-dark-200">{{ $bookings->links() }}</div>@endif
    </div>
</div>
@endsection
