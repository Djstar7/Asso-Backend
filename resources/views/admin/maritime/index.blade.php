@extends('admin.layouts.app')

@php
    $input = 'w-full px-3 py-2 bg-dark-50 border border-dark-200 rounded-lg text-white focus:border-primary-500 focus:outline-none';
    $btn = 'px-4 py-2 bg-gradient-to-r from-primary-500 to-primary-600 text-white rounded-lg hover:shadow-lg';
@endphp

@section('content')
<div class="p-6">
    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-white">Transport international</h1>
            <p class="text-gray-400">Offres de fret aérien et maritime des partenaires, entre leurs QG. Les coûts du partenaire, les prix client et les délais sont configurables par route.</p>
        </div>
        <a href="{{ route('admin.maritime.bookings.index') }}" class="{{ $btn }}">
            <i class="fas fa-boxes mr-2"></i>Envois
            @if($pendingDropoffs > 0)<span class="ml-2 px-2 py-0.5 text-xs bg-white/20 rounded-full">{{ $pendingDropoffs }} colis attendus</span>@endif
        </a>
    </div>

    @include('admin.maritime._flash')

    <a class="inline-block text-primary-400 mb-4" href="{{ route('admin.maritime.inquiries.index') }}">Demandes de devis Chine → autres pays</a>
    <!-- Comment ça marche -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
        @foreach([
            ['fa-anchor', '1. Partenaire', 'Créez le partenaire de transport ci-dessous, puis ajoutez ses tarifs aériens et/ou maritimes.'],
            ['fa-warehouse', '2. QG', 'Ajoutez ses QG de dépôt et de retrait (ex. Paris, Douala).'],
            ['fa-ship', '3. Départs', 'Ouvrez ses départs : date, capacité et tarif en CBM pour le maritime, en kg pour l’aérien.'],
            ['fa-wallet', '4. Paiement', 'Le fret est crédité au Wallet selon l’échéance du tarif (embarquement ou arrivée). Les envois historiques restent réglés à la remise.'],
        ] as [$icon, $title, $text])
            <div class="bg-dark-100 rounded-xl border border-dark-200 p-4">
                <p class="text-white font-semibold"><i class="fas {{ $icon }} mr-2 text-primary-400"></i>{{ $title }}</p>
                <p class="text-sm text-gray-400 mt-1">{{ $text }}</p>
            </div>
        @endforeach
    </div>

    <!-- Partenaires de transport international -->
    <div class="bg-dark-100 rounded-xl shadow-lg border border-dark-200 p-6 mb-6">
        <h2 class="text-lg font-semibold text-white mb-4"><i class="fas fa-anchor mr-2 text-primary-400"></i>Partenaires de transport international</h2>

        <div class="overflow-x-auto mb-6">
            <table class="w-full text-sm">
                <thead class="text-gray-400 text-xs uppercase text-left">
                    <tr><th class="py-2">Partenaire</th><th>Contact</th><th class="text-center">QG</th><th class="text-center">Départs à venir</th><th class="text-right">À verser</th><th>Statut</th><th></th></tr>
                </thead>
                <tbody class="divide-y divide-dark-200 text-gray-300">
                    @forelse($partners as $partner)
                        @php $toPay = $partner->account?->kpayAvailableFor('XAF') ?? 0; @endphp
                        <tr>
                            <td class="py-3 text-white font-medium">{{ $partner->name }}</td>
                            <td>{{ $partner->phone }}<br><span class="text-xs text-gray-500">{{ $partner->email }}</span></td>
                            <td class="text-center">{{ $partner->maritime_hubs_count }}</td>
                            <td class="text-center">{{ $partner->upcoming_departures_count }}</td>
                            <td class="text-right whitespace-nowrap {{ $toPay > 0 ? 'text-yellow-300' : 'text-gray-500' }}">{{ number_format($toPay, 0, ',', ' ') }} FCFA</td>
                            <td>
                                <span class="px-2 py-1 rounded text-xs {{ $partner->is_active ? 'bg-green-900/30 text-green-400' : 'bg-gray-800 text-gray-400' }}">{{ $partner->is_active ? 'Actif' : 'Inactif' }}</span>
                            </td>
                            <td class="text-right"><a href="{{ route('admin.maritime.partners.show', $partner) }}" class="text-primary-400 hover:text-primary-300">Ouvrir <i class="fas fa-chevron-right ml-1"></i></a></td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="py-6 text-center text-gray-500">Aucun partenaire de transport international pour l'instant.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <form action="{{ route('admin.maritime.partners.store') }}" method="POST" class="grid grid-cols-1 md:grid-cols-4 gap-3 items-end border-t border-dark-200 pt-4">
            @csrf
            <div>
                <label class="block text-sm text-gray-300 mb-1">Nom du transporteur *</label>
                <input name="name" required value="{{ old('name') }}" class="{{ $input }}">
            </div>
            <div>
                <label class="block text-sm text-gray-300 mb-1">Téléphone</label>
                <input name="phone" value="{{ old('phone') }}" class="{{ $input }}">
            </div>
            <div>
                <label class="block text-sm text-gray-300 mb-1">E-mail</label>
                <input name="email" type="email" value="{{ old('email') }}" class="{{ $input }}">
            </div>
            <button type="submit" class="{{ $btn }}"><i class="fas fa-plus mr-1"></i>Ajouter le partenaire</button>
        </form>
    </div>

    <!-- Départs à venir (tous partenaires) -->
    <div class="bg-dark-100 rounded-xl shadow-lg border border-dark-200 overflow-hidden mb-6">
        <div class="px-6 py-4 border-b border-dark-200">
            <h2 class="text-lg font-semibold text-white"><i class="fas fa-ship mr-2 text-primary-400"></i>Départs à venir</h2>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-dark-50 text-left text-xs font-semibold text-white uppercase tracking-wider">
                    <tr><th class="px-4 py-3">Départ</th><th class="px-4 py-3">Trajet</th><th class="px-4 py-3">Réservations / dépôt</th><th class="px-4 py-3">Kilos libres</th><th class="px-4 py-3">Tarif</th><th class="px-4 py-3">Envois</th><th class="px-4 py-3">Statut</th></tr>
                </thead>
                <tbody class="divide-y divide-dark-200 text-gray-300">
                    @forelse($departures as $d)
                        <tr>
                            <td class="px-4 py-3 whitespace-nowrap"><span class="text-white font-semibold">{{ $d->departure_date->format('d/m/Y') }}</span><br>
                                <a href="{{ route('admin.maritime.partners.show', $d->company) }}" class="text-xs text-primary-400">{{ $d->company->name }}</a></td>
                            <td class="px-4 py-3">{{ $d->originHub->city }} → {{ $d->destinationHub->city }}</td>
                            <td class="px-4 py-3 whitespace-nowrap text-xs">jusqu'au {{ $d->bookingClosesOn()->format('d/m') }}<br>dépôt avant le {{ $d->dropoffDeadline()->format('d/m') }}</td>
                            <td class="px-4 py-3 whitespace-nowrap">{{ ($d->freight_rate_snapshot['transport_mode'] ?? 'sea') === 'sea' ? (float) $d->remaining_kg / 1000 : (float) $d->remaining_kg }} / {{ ($d->freight_rate_snapshot['transport_mode'] ?? 'sea') === 'sea' ? (float) $d->capacity_kg / 1000 : (float) $d->capacity_kg }} {{ ($d->freight_rate_snapshot['transport_mode'] ?? 'sea') === 'sea' ? 'CBM' : 'kg' }}</td>
                            <td class="px-4 py-3 whitespace-nowrap">@include('admin.maritime._price', ['d' => $d])</td>
                            <td class="px-4 py-3"><a href="{{ route('admin.maritime.bookings.index', ['departure' => $d->id]) }}" class="text-primary-400 underline">{{ $d->paid_bookings_count }}</a></td>
                            <td class="px-4 py-3">{{ \App\Models\MaritimeDeparture::STATUSES[$d->status] ?? $d->status }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-4 py-8 text-center text-gray-400">Aucun départ à venir. Ouvrez-en depuis la fiche d'un partenaire.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <!-- Réglages -->
    <div class="bg-dark-100 rounded-xl shadow-lg border border-dark-200 p-6 mb-6">
        <h2 class="text-lg font-semibold text-white mb-1"><i class="fas fa-sliders-h mr-2 text-primary-400"></i>Réglages</h2>
        <p class="text-sm text-gray-400 mb-4">Passé la clôture, l'app affiche « Trajet impossible à faire, veuillez consulter les prochaines dates pour cette destination. » Un colis non déposé avant la date limite est remboursé au Wallet, hors commission ASSO.</p>
        <form action="{{ route('admin.maritime.settings') }}" method="POST" class="grid grid-cols-1 md:grid-cols-4 gap-4 items-end">
            @csrf
            <div>
                <label class="block text-sm text-gray-300 mb-1">Commission ASSO (%)</label>
                <input type="number" step="0.01" min="0" max="100" name="commission_rate" value="{{ old('commission_rate', $settings['commission_rate']) }}" class="{{ $input }}">
            </div>
            <div>
                <label class="block text-sm text-gray-300 mb-1">Clôture (jours avant départ)</label>
                <input type="number" min="0" max="60" name="booking_cutoff_days" value="{{ old('booking_cutoff_days', $settings['booking_cutoff_days']) }}" class="{{ $input }}">
            </div>
            <div>
                <label class="block text-sm text-gray-300 mb-1">Dépôt (jours avant départ)</label>
                <input type="number" min="0" max="60" name="dropoff_days_before" value="{{ old('dropoff_days_before', $settings['dropoff_days_before']) }}" class="{{ $input }}">
            </div>
            <button type="submit" class="{{ $btn }}">Enregistrer</button>
        </form>
    </div>

    <div class="bg-dark-100 rounded-xl shadow-lg border border-dark-200 p-6">
        <h2 class="text-lg font-semibold text-white mb-1">Plafond global d’indemnisation</h2>
        <p class="text-sm text-gray-400 mb-4">Plafond unique appliqué au montant calculé par le serveur ; obligatoire avant tout versement.</p>
        <form action="{{ route('admin.maritime.indemnity-cap') }}" method="POST" class="grid grid-cols-1 md:grid-cols-3 gap-4 items-end">
            @csrf
            <div>
                <label class="block text-sm text-gray-300 mb-1">Plafond global (XAF)</label>
                <input type="number" step="1" min="1" max="1000000000000" required name="indemnity_cap_xaf" value="{{ old('indemnity_cap_xaf', $indemnityCapXaf) }}" class="{{ $input }}">
            </div>
            <div class="text-sm text-gray-400">Valeur actuelle : {{ $indemnityCapXaf ? number_format((float) $indemnityCapXaf, 0, ',', ' ') . ' XAF' : 'Non configurée' }}</div>
            <button type="submit" class="{{ $btn }}">Enregistrer le plafond</button>
        </form>
    </div>
</div>
@endsection
