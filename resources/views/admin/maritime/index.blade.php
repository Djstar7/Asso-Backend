@extends('admin.layouts.app')

@php
    $input = 'w-full px-3 py-2 bg-dark-50 border border-dark-200 rounded-lg text-white focus:border-primary-500 focus:outline-none';
    $btn = 'px-4 py-2 bg-gradient-to-r from-primary-500 to-primary-600 text-white rounded-lg hover:shadow-lg';
    $statusColors = ['open' => 'green', 'closed' => 'yellow', 'departed' => 'blue', 'arrived' => 'purple', 'cancelled' => 'gray'];
@endphp

@section('content')
<div class="p-6">
    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-white">Diaspo Maritime</h1>
            <p class="text-gray-400">Départs par bateau des partenaires maritimes, entre leurs QG. L'expéditeur réserve des kilos, paie, puis dépose son colis au QG de départ avant la date limite.</p>
        </div>
        <a href="{{ route('admin.maritime.bookings.index') }}" class="{{ $btn }}">
            <i class="fas fa-boxes mr-2"></i>Envois
            @if($pendingDropoffs > 0)<span class="ml-2 px-2 py-0.5 text-xs bg-white/20 rounded-full">{{ $pendingDropoffs }} colis attendus</span>@endif
        </a>
    </div>

    @include('admin.maritime._flash')

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
                <label class="block text-sm text-gray-300 mb-1">Clôture des réservations (jours avant départ)</label>
                <input type="number" min="0" max="60" name="booking_cutoff_days" value="{{ old('booking_cutoff_days', $settings['booking_cutoff_days']) }}" class="{{ $input }}">
            </div>
            <div>
                <label class="block text-sm text-gray-300 mb-1">Dépôt du colis (jours avant départ)</label>
                <input type="number" min="0" max="60" name="dropoff_days_before" value="{{ old('dropoff_days_before', $settings['dropoff_days_before']) }}" class="{{ $input }}">
            </div>
            <button type="submit" class="{{ $btn }}">Enregistrer</button>
        </form>
    </div>

    <!-- Partenaires et QG -->
    <div class="bg-dark-100 rounded-xl shadow-lg border border-dark-200 p-6 mb-6">
        <h2 class="text-lg font-semibold text-white mb-1"><i class="fas fa-anchor mr-2 text-primary-400"></i>Partenaires maritimes et QG</h2>
        <p class="text-sm text-gray-400 mb-4">
            Créez le partenaire dans <a href="{{ route('admin.delivery-partners.index') }}" class="text-primary-400 underline">Partenaires logistiques</a> avec la catégorie « Fret maritime (Diaspo) ».
            Son fret est versé sur son Wallet ASSO : liez son compte avec un code de synchronisation (Livreurs) avant d'ouvrir un départ.
        </p>

        @forelse($partners as $partner)
            <div class="border border-dark-200 rounded-lg p-4 mb-4">
                <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
                    <p class="text-white font-semibold">{{ $partner->name }}
                        @unless($partner->is_active)<span class="ml-2 text-xs text-yellow-400">(inactif)</span>@endunless
                    </p>
                    @if($partner->user)
                        <span class="text-sm text-green-300"><i class="fas fa-wallet mr-1"></i>Wallet : {{ $partner->user->name }} ({{ $partner->user->email ?? $partner->user->phone }})</span>
                    @else
                        <a href="{{ route('admin.deliverers.show', $partner) }}" class="text-sm text-red-300 underline"><i class="fas fa-exclamation-triangle mr-1"></i>Aucun compte lié : générer un code de synchronisation</a>
                    @endif
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm mb-3">
                        <thead class="text-gray-400 text-xs uppercase">
                            <tr><th class="text-left py-1">QG</th><th class="text-left">Pays / ville</th><th class="text-left">Adresse</th><th class="text-left">Téléphone</th><th class="text-left">Horaires</th><th class="text-left">Actif</th><th></th></tr>
                        </thead>
                        <tbody class="divide-y divide-dark-200">
                            @foreach($partner->maritimeHubs as $hub)
                                <tr>
                                    <form id="hub-{{ $hub->id }}" action="{{ route('admin.maritime.hubs.update', $hub) }}" method="POST">@csrf @method('PUT')</form>
                                    <td class="py-1 pr-2"><input form="hub-{{ $hub->id }}" name="name" value="{{ $hub->name }}" required class="w-full px-2 py-1 bg-dark-50 border border-dark-200 rounded text-white"></td>
                                    <td class="pr-2 whitespace-nowrap">
                                        <select form="hub-{{ $hub->id }}" name="country" class="px-2 py-1 bg-dark-50 border border-dark-200 rounded text-white">
                                            @foreach($countries as $code => $name)<option value="{{ $code }}" @selected($hub->country === $code)>{{ $name }}</option>@endforeach
                                        </select>
                                        <input form="hub-{{ $hub->id }}" name="city" value="{{ $hub->city }}" required class="w-28 px-2 py-1 bg-dark-50 border border-dark-200 rounded text-white">
                                    </td>
                                    <td class="pr-2"><input form="hub-{{ $hub->id }}" name="address" value="{{ $hub->address }}" required class="w-full px-2 py-1 bg-dark-50 border border-dark-200 rounded text-white"></td>
                                    <td class="pr-2"><input form="hub-{{ $hub->id }}" name="phone" value="{{ $hub->phone }}" class="w-32 px-2 py-1 bg-dark-50 border border-dark-200 rounded text-white"></td>
                                    <td class="pr-2"><input form="hub-{{ $hub->id }}" name="opening_hours" value="{{ $hub->opening_hours }}" class="w-36 px-2 py-1 bg-dark-50 border border-dark-200 rounded text-white"></td>
                                    <td class="pr-2"><input form="hub-{{ $hub->id }}" type="checkbox" name="is_active" value="1" @checked($hub->is_active)></td>
                                    <td><button form="hub-{{ $hub->id }}" type="submit" class="px-3 py-1 bg-blue-600 text-white rounded hover:bg-blue-700">Enregistrer</button></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <form action="{{ route('admin.maritime.hubs.store', $partner) }}" method="POST" class="grid grid-cols-1 md:grid-cols-7 gap-2 items-end">
                    @csrf
                    <input name="name" placeholder="Nom du QG" required class="{{ $input }}">
                    <select name="country" required class="{{ $input }}">
                        @foreach($countries as $code => $name)<option value="{{ $code }}">{{ $name }}</option>@endforeach
                    </select>
                    <input name="city" placeholder="Ville" required class="{{ $input }}">
                    <input name="address" placeholder="Adresse" required class="{{ $input }} md:col-span-2">
                    <input name="phone" placeholder="Téléphone" class="{{ $input }}">
                    <button type="submit" class="{{ $btn }}">Ajouter le QG</button>
                    <input name="opening_hours" placeholder="Horaires (ex. lun-sam 9h-18h)" class="{{ $input }} md:col-span-3">
                </form>
            </div>
        @empty
            <p class="text-gray-400">Aucun partenaire maritime pour l'instant.</p>
        @endforelse
    </div>

    <!-- Nouveau départ -->
    @if($partners->isNotEmpty())
    <div class="bg-dark-100 rounded-xl shadow-lg border border-dark-200 p-6 mb-6">
        <h2 class="text-lg font-semibold text-white mb-4"><i class="fas fa-plus-circle mr-2 text-primary-400"></i>Nouveau départ</h2>
        <form action="{{ route('admin.maritime.departures.store') }}" method="POST" class="grid grid-cols-1 md:grid-cols-4 gap-4 items-end">
            @csrf
            <div>
                <label class="block text-sm text-gray-300 mb-1">Partenaire</label>
                <select name="deliverer_company_id" required class="{{ $input }}">
                    @foreach($partners as $partner)<option value="{{ $partner->id }}" @selected(old('deliverer_company_id') == $partner->id)>{{ $partner->name }}</option>@endforeach
                </select>
            </div>
            <div>
                <label class="block text-sm text-gray-300 mb-1">QG de départ</label>
                <select name="origin_hub_id" required class="{{ $input }}">
                    @foreach($partners as $partner)
                        <optgroup label="{{ $partner->name }}">
                            @foreach($partner->maritimeHubs->where('is_active', true) as $hub)<option value="{{ $hub->id }}" @selected(old('origin_hub_id') == $hub->id)>{{ $hub->city }} — {{ $hub->name }}</option>@endforeach
                        </optgroup>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-sm text-gray-300 mb-1">QG d'arrivée</label>
                <select name="destination_hub_id" required class="{{ $input }}">
                    @foreach($partners as $partner)
                        <optgroup label="{{ $partner->name }}">
                            @foreach($partner->maritimeHubs->where('is_active', true) as $hub)<option value="{{ $hub->id }}" @selected(old('destination_hub_id') == $hub->id)>{{ $hub->city }} — {{ $hub->name }}</option>@endforeach
                        </optgroup>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-sm text-gray-300 mb-1">Date de départ</label>
                <input type="date" name="departure_date" required value="{{ old('departure_date') }}" class="{{ $input }}">
            </div>
            <div>
                <label class="block text-sm text-gray-300 mb-1">Arrivée estimée</label>
                <input type="date" name="estimated_arrival_date" value="{{ old('estimated_arrival_date') }}" class="{{ $input }}">
            </div>
            <div>
                <label class="block text-sm text-gray-300 mb-1">Capacité (kg)</label>
                <input type="number" step="0.5" min="1" name="capacity_kg" required value="{{ old('capacity_kg') }}" class="{{ $input }}">
            </div>
            <div class="flex gap-2">
                <div class="flex-1">
                    <label class="block text-sm text-gray-300 mb-1">Prix partenaire / kg</label>
                    <input type="number" step="0.01" min="0.01" name="price_per_kg" required value="{{ old('price_per_kg') }}" class="{{ $input }}">
                </div>
                <div class="w-24">
                    <label class="block text-sm text-gray-300 mb-1">Devise</label>
                    <input name="currency" maxlength="3" required value="{{ old('currency', 'EUR') }}" class="{{ $input }} uppercase">
                </div>
            </div>
            <button type="submit" class="{{ $btn }}">Créer le départ</button>
            <div class="md:col-span-4">
                <label class="block text-sm text-gray-300 mb-1">Notes pour l'expéditeur (facultatif)</label>
                <input name="notes" value="{{ old('notes') }}" placeholder="Ex. colis emballés, étiquette avec le nom du destinataire" class="{{ $input }}">
            </div>
        </form>
        <p class="text-xs text-gray-500 mt-3">Le client paie le prix partenaire majoré de la commission ASSO ({{ $settings['commission_rate'] }} %), plus l'assurance selon la nature et la valeur déclarée.</p>
    </div>
    @endif

    <!-- Départs -->
    <div class="bg-dark-100 rounded-xl shadow-lg border border-dark-200 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-dark-50">
                    <tr class="text-left text-xs font-semibold text-white uppercase tracking-wider">
                        <th class="px-4 py-3">Départ</th><th class="px-4 py-3">Trajet</th><th class="px-4 py-3">Clôture / dépôt</th>
                        <th class="px-4 py-3">Kilos</th><th class="px-4 py-3">Prix / kg</th><th class="px-4 py-3">Envois</th><th class="px-4 py-3">Statut et capacité</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-dark-200 text-gray-300">
                    @forelse($departures as $d)
                        <tr>
                            <td class="px-4 py-3 whitespace-nowrap"><span class="text-white font-semibold">{{ $d->departure_date->format('d/m/Y') }}</span><br><span class="text-xs">{{ $d->company->name }}</span></td>
                            <td class="px-4 py-3">{{ $d->originHub->city }} ({{ $d->originHub->country }}) → {{ $d->destinationHub->city }} ({{ $d->destinationHub->country }})</td>
                            <td class="px-4 py-3 whitespace-nowrap text-xs">Réservations jusqu'au {{ $d->bookingClosesOn()->format('d/m') }}<br>Dépôt avant le {{ $d->dropoffDeadline()->format('d/m') }}</td>
                            <td class="px-4 py-3 whitespace-nowrap">{{ (float) $d->remaining_kg }} / {{ (float) $d->capacity_kg }} kg</td>
                            <td class="px-4 py-3 whitespace-nowrap">{{ (float) $d->price_per_kg }} {{ $d->currency }}<br><span class="text-xs text-gray-500">client : {{ $d->publicPricePerKg() }}</span></td>
                            <td class="px-4 py-3"><a href="{{ route('admin.maritime.bookings.index', ['departure' => $d->id]) }}" class="text-primary-400 underline">{{ $d->paid_bookings_count }}</a></td>
                            <td class="px-4 py-3">
                                <form action="{{ route('admin.maritime.departures.update', $d) }}" method="POST" class="flex flex-wrap gap-2 items-center">
                                    @csrf @method('PUT')
                                    <select name="status" class="px-2 py-1 bg-dark-50 border border-dark-200 rounded text-{{ $statusColors[$d->status] ?? 'gray' }}-300">
                                        @foreach(\App\Models\MaritimeDeparture::STATUSES as $value => $label)<option value="{{ $value }}" @selected($d->status === $value)>{{ $label }}</option>@endforeach
                                    </select>
                                    <input type="number" step="0.5" min="0" name="capacity_kg" value="{{ (float) $d->capacity_kg }}" class="w-24 px-2 py-1 bg-dark-50 border border-dark-200 rounded text-white" title="Capacité (kg)">
                                    <input type="date" name="estimated_arrival_date" value="{{ $d->estimated_arrival_date?->toDateString() }}" class="px-2 py-1 bg-dark-50 border border-dark-200 rounded text-white" title="Arrivée estimée">
                                    <button type="submit" class="px-3 py-1 bg-blue-600 text-white rounded hover:bg-blue-700">OK</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-4 py-8 text-center text-gray-400">Aucun départ.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($departures->hasPages())<div class="px-6 py-4 border-t border-dark-200">{{ $departures->links() }}</div>@endif
    </div>
</div>
@endsection
