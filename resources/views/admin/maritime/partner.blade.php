@extends('admin.layouts.app')

@php
    $input = 'w-full px-3 py-2 bg-dark-50 border border-dark-200 rounded-lg text-white focus:border-primary-500 focus:outline-none';
    $small = 'px-2 py-1 bg-dark-50 border border-dark-200 rounded text-white';
    $btn = 'px-4 py-2 bg-gradient-to-r from-primary-500 to-primary-600 text-white rounded-lg hover:shadow-lg';
    $hubs = $partner->maritimeHubs;
    $activeHubs = $hubs->where('is_active', true);
@endphp

@section('content')
<div class="p-6">
    <div class="mb-6 flex items-center gap-3">
        <a href="{{ route('admin.maritime.index') }}" class="text-gray-400 hover:text-primary-500"><i class="fas fa-arrow-left"></i></a>
        <div>
            <h1 class="text-2xl font-bold text-white"><i class="fas fa-anchor mr-2 text-primary-400"></i>{{ $partner->name }}</h1>
            <p class="text-gray-400">Partenaire de transport international : offres aériennes et maritimes, QG, départs et paiements.</p>
        </div>
    </div>

    @include('admin.maritime._flash')

    <!-- Paiements -->
    <div class="bg-dark-100 rounded-xl shadow-lg border border-dark-200 p-6 mb-6 flex flex-wrap items-center justify-between gap-4">
        <div>
            <h2 class="text-lg font-semibold text-white mb-1"><i class="fas fa-wallet mr-2 text-primary-400"></i>Paiements</h2>
            <p class="text-sm text-gray-400">Le fret de chaque envoi remis est crédité sur son Wallet. Solde à verser :
                <span class="text-white font-semibold">{{ number_format($walletBalance, 0, ',', ' ') }} FCFA</span></p>
        </div>
        @if(auth()->user()->isAdmin())
            <a href="{{ route('admin.partner-payouts.show', $partner) }}" class="{{ $btn }}"><i class="fas fa-paper-plane mr-1"></i>Payer (Mobile Money / IBAN)</a>
        @endif
    </div>

    @include('admin.maritime._freight_rates')

    <!-- QG -->
    <div class="bg-dark-100 rounded-xl shadow-lg border border-dark-200 p-6 mb-6">
        <h2 class="text-lg font-semibold text-white mb-1"><i class="fas fa-warehouse mr-2 text-primary-400"></i>QG</h2>
        <p class="text-sm text-gray-400 mb-4">Lieux fixes où l'expéditeur dépose son colis (départ) et où le destinataire le récupère avec son code (arrivée).</p>

        @if($hubs->isNotEmpty())
        <div class="overflow-x-auto mb-4">
            <table class="w-full text-sm">
                <thead class="text-gray-400 text-xs uppercase text-left">
                    <tr><th class="py-1">Nom</th><th>Pays / ville</th><th>Adresse</th><th>Téléphone</th><th>Horaires</th><th>Actif</th><th>Actions</th></tr>
                </thead>
                <tbody class="divide-y divide-dark-200">
                    @foreach($hubs as $hub)
                        <tr>
                            <td class="py-2 pr-2 text-white">{{ $hub->name }}</td>
                            <td class="pr-2 whitespace-nowrap">{{ $countries[$hub->country] ?? $hub->country }} / {{ $hub->city }}</td>
                            <td class="pr-2">{{ $hub->address }}</td>
                            <td class="pr-2 whitespace-nowrap">{{ $hub->phone ?: '—' }}</td>
                            <td class="pr-2 whitespace-nowrap">{{ $hub->opening_hours ?: '—' }}</td>
                            <td class="pr-2">{{ $hub->is_active ? 'Oui' : 'Non' }}</td>
                            <td class="whitespace-nowrap">
                                <button type="button" onclick="document.getElementById('hub-edit-{{ $hub->id }}').showModal()" class="px-3 py-1 bg-indigo-600 text-white rounded hover:bg-indigo-700">Modifier</button>
                                @if(in_array($hub->id, $usedHubIds, true))
                                    <button type="button" disabled title="Ce QG est utilisé par un départ." class="ml-1 px-3 py-1 bg-gray-600 text-gray-300 rounded cursor-not-allowed">Utilisé</button>
                                @else
                                    <form action="{{ route('admin.maritime.hubs.destroy', $hub) }}" method="POST" class="inline"
                                          data-confirm="Supprimer définitivement ce QG ? Cette action est irréversible.">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="ml-1 px-3 py-1 bg-red-700 text-white rounded hover:bg-red-800">Supprimer</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @foreach($hubs as $hub)
            <dialog id="hub-edit-{{ $hub->id }}" class="w-11/12 max-w-2xl rounded-xl border border-dark-200 bg-dark-100 p-0 text-white shadow-2xl backdrop:bg-black/70">
                <form action="{{ route('admin.maritime.hubs.update', $hub) }}" method="POST" class="p-6">
                    @csrf @method('PUT')
                    <div class="mb-5 flex items-start justify-between gap-4">
                        <div>
                            <h3 class="text-xl font-semibold">Modifier le QG — {{ $hub->name }}</h3>
                            <p class="mt-1 text-sm text-gray-400">Mettez à jour les coordonnées et la disponibilité de ce lieu.</p>
                        </div>
                        <button type="button" onclick="this.closest('dialog').close()" class="text-2xl leading-none text-gray-400 hover:text-white" aria-label="Fermer">&times;</button>
                    </div>
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <label class="text-sm text-gray-300 md:col-span-2">Nom
                            <input name="name" value="{{ $hub->name }}" required class="{{ $input }} mt-1">
                        </label>
                        <label class="text-sm text-gray-300">Pays
                            <select name="country" required class="{{ $input }} mt-1">
                                @foreach($countries as $code => $name)<option value="{{ $code }}" @selected($hub->country === $code)>{{ $name }}</option>@endforeach
                            </select>
                        </label>
                        <label class="text-sm text-gray-300">Ville
                            <input name="city" value="{{ $hub->city }}" required class="{{ $input }} mt-1">
                        </label>
                        <label class="text-sm text-gray-300 md:col-span-2">Adresse
                            <input name="address" value="{{ $hub->address }}" required class="{{ $input }} mt-1">
                        </label>
                        <label class="text-sm text-gray-300">Téléphone
                            <input name="phone" value="{{ $hub->phone }}" class="{{ $input }} mt-1">
                        </label>
                        <label class="text-sm text-gray-300">Horaires
                            <input name="opening_hours" value="{{ $hub->opening_hours }}" class="{{ $input }} mt-1">
                        </label>
                        <label class="flex items-center gap-2 text-gray-300 md:col-span-2">
                            <input type="checkbox" name="is_active" value="1" @checked($hub->is_active)> QG actif
                        </label>
                    </div>
                    <div class="mt-6 flex justify-end gap-3">
                        <button type="button" onclick="this.closest('dialog').close()" class="rounded-lg border border-dark-200 px-4 py-2 text-gray-300 hover:bg-dark-50">Annuler</button>
                        <button type="submit" class="{{ $btn }}">Enregistrer les modifications</button>
                    </div>
                </form>
            </dialog>
        @endforeach
        @endif

        <form action="{{ route('admin.maritime.hubs.store', $partner) }}" method="POST" class="grid grid-cols-1 md:grid-cols-6 gap-2 items-end border-t border-dark-200 pt-4">
            @csrf
            <input name="name" placeholder="Nom du QG" required class="{{ $input }}">
            <select name="country" required class="{{ $input }}">
                @foreach($countries as $code => $name)<option value="{{ $code }}">{{ $name }}</option>@endforeach
            </select>
            <input name="city" placeholder="Ville" required class="{{ $input }}">
            <input name="address" placeholder="Adresse" required class="{{ $input }} md:col-span-2">
            <input name="phone" placeholder="Téléphone" class="{{ $input }}">
            <input name="opening_hours" placeholder="Horaires (ex. lun-sam 9h-18h)" class="{{ $input }} md:col-span-5">
            <button type="submit" class="{{ $btn }}"><i class="fas fa-plus mr-1"></i>Ajouter le QG</button>
        </form>
    </div>

    <!-- Nouveau départ -->
    <div class="bg-dark-100 rounded-xl shadow-lg border border-dark-200 p-6 mb-6">
        <h2 class="text-lg font-semibold text-white mb-1"><i class="fas fa-ship mr-2 text-primary-400"></i>Nouveau départ</h2>
        @if($activeHubs->count() < 2)
            <p class="text-sm text-yellow-300">Ajoutez au moins deux QG actifs (départ et arrivée) pour ouvrir un départ.</p>
        @else
            <p class="text-sm text-gray-400 mb-4">L'expéditeur saisit toujours des kilos. Au m³, ses kilos sont convertis en m³ (1 m³ = 1 000 kg) puis multipliés par le tarif, avant que le prix lui soit affiché.</p>
            <form action="{{ route('admin.maritime.departures.store', $partner) }}" method="POST"
                  x-data="{ rateId: @js(old('partner_freight_rate_id', '')), unit: @js(old('pricing_unit', 'cbm')), price: @js(old('price', '')), kg: {{ \App\Models\MaritimeDeparture::KG_PER_CBM }} }"
                  class="grid grid-cols-1 md:grid-cols-4 gap-4 items-end">
                @csrf
                <div>
                    <label class="block text-sm text-gray-300 mb-1">Nombre de départs à créer</label>
                    <input type="number" name="occurrences" min="1" max="26" value="{{ old('occurrences', 1) }}" class="{{ $input }}">
                    <p class="text-xs text-gray-400">Paris → Cameroun : espacés de 14 jours. Chine : un départ daté par occasion.</p>
                    @error('occurrences')<p class="text-red-400">{{ $message }}</p>@enderror
                </div>
                <div class="md:col-span-4">
                    <label class="block text-sm text-gray-300 mb-1">Tarif enregistré (facultatif)</label>
                    <select name="partner_freight_rate_id" x-model="rateId" class="{{ $input }}">
                        <option value="">Saisie manuelle</option>
                        @foreach($freightRates->where('is_active', true) as $rate)
                            <option value="{{ $rate->id }}" @selected(old('partner_freight_rate_id') == $rate->id)>{{ $rate->origin_country }} → {{ $rate->destination_country }} · {{ $rate->transport_mode === 'sea' ? 'Maritime' : 'Aérien' }} · {{ $rate->cargo_category === 'sensitive' ? 'Sensible' : 'Général' }} · {{ number_format($rate->rate_amount, 0, ',', ' ') }} {{ $rate->currency }}/{{ $rate->rate_unit }} · {{ $rate->transit_days_min }}–{{ $rate->transit_days_max }} jours</option>
                        @endforeach
                    </select>
                    <p class="text-xs text-gray-400 mt-1">Le tarif sélectionné remplace le prix, l'unité et la devise saisis ci-dessous. Sans date d'arrivée, le délai maximum du tarif est utilisé.</p>
                    @error('partner_freight_rate_id')<p class="text-red-400">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="block text-sm text-gray-300 mb-1">QG de départ</label>
                    <select name="origin_hub_id" required class="{{ $input }}">
                        @foreach($activeHubs as $hub)<option value="{{ $hub->id }}" @selected(old('origin_hub_id') == $hub->id)>{{ $hub->city }} — {{ $hub->name }}</option>@endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-sm text-gray-300 mb-1">QG d'arrivée</label>
                    <select name="destination_hub_id" required class="{{ $input }}">
                        @foreach($activeHubs as $hub)<option value="{{ $hub->id }}" @selected(old('destination_hub_id', $activeHubs->skip(1)->first()?->id) == $hub->id)>{{ $hub->city }} — {{ $hub->name }}</option>@endforeach
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
                    <label class="block text-sm text-gray-300 mb-1">Tarif du transporteur</label>
                    <div class="flex rounded-lg overflow-hidden border border-dark-200">
                        <label class="flex-1 text-center py-2 cursor-pointer" :class="unit === 'cbm' ? 'bg-primary-600 text-white' : 'bg-dark-50 text-gray-300'">
                            <input type="radio" name="pricing_unit" value="cbm" x-model="unit" class="hidden">Au m³
                        </label>
                        <label class="flex-1 text-center py-2 cursor-pointer" :class="unit === 'kg' ? 'bg-primary-600 text-white' : 'bg-dark-50 text-gray-300'">
                            <input type="radio" name="pricing_unit" value="kg" x-model="unit" class="hidden">Au kg
                        </label>
                    </div>
                </div>
                <div class="flex gap-2">
                    <div class="flex-1">
                        <label class="block text-sm text-gray-300 mb-1" x-text="unit === 'cbm' ? 'Prix pour 1 m³' : 'Prix pour 1 kg'"></label>
                        <input type="number" step="0.01" min="0.01" name="price" x-model="price" :required="!rateId" class="{{ $input }}">
                    </div>
                    <div class="w-24">
                        <label class="block text-sm text-gray-300 mb-1">Devise</label>
                        <input name="currency" maxlength="3" required value="{{ old('currency', 'EUR') }}" class="{{ $input }} uppercase">
                    </div>
                </div>
                <div>
                    <label class="block text-sm text-gray-300 mb-1">Capacité (kg)</label>
                    <input type="number" step="0.5" min="1" name="capacity_kg" required value="{{ old('capacity_kg') }}" class="{{ $input }}">
                </div>

                <p class="md:col-span-4 text-sm text-gray-400" x-show="unit === 'cbm' && price > 0 && kg > 0">
                    Soit <span class="text-white" x-text="(price / kg).toFixed(2)"></span> par kg pour le transporteur, avant la commission ASSO ({{ $settings['commission_rate'] }} %) et l'assurance.
                </p>

                <div class="md:col-span-3">
                    <label class="block text-sm text-gray-300 mb-1">Notes pour l'expéditeur (facultatif)</label>
                    <input name="notes" value="{{ old('notes') }}" placeholder="Ex. colis emballés, étiquette avec le nom du destinataire" class="{{ $input }}">
                </div>
                <button type="submit" class="{{ $btn }}">Ouvrir le départ</button>
            </form>
        @endif
    </div>

    <!-- Départs -->
    <div class="bg-dark-100 rounded-xl shadow-lg border border-dark-200 overflow-hidden mb-6">
        <div class="px-6 py-4 border-b border-dark-200"><h2 class="text-lg font-semibold text-white">Départs</h2></div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-dark-50 text-left text-xs font-semibold text-white uppercase tracking-wider">
                    <tr><th class="px-4 py-3">Départ</th><th class="px-4 py-3">Trajet</th><th class="px-4 py-3">Clôture / dépôt</th><th class="px-4 py-3">Kilos libres</th><th class="px-4 py-3">Tarif</th><th class="px-4 py-3">Envois</th><th class="px-4 py-3">Statut et capacité</th><th class="px-4 py-3">Actions</th></tr>
                </thead>
                <tbody class="divide-y divide-dark-200 text-gray-300">
                    @forelse($departures as $d)
                        <tr>
                            <td class="px-4 py-3 whitespace-nowrap text-white font-semibold">{{ $d->departure_date->format('d/m/Y') }}</td>
                            <td class="px-4 py-3">{{ $d->originHub->city }} → {{ $d->destinationHub->city }}</td>
                            <td class="px-4 py-3 whitespace-nowrap text-xs">jusqu'au {{ $d->bookingClosesOn()->format('d/m') }}<br>dépôt avant le {{ $d->dropoffDeadline()->format('d/m') }}</td>
                            <td class="px-4 py-3 whitespace-nowrap">{{ (float) $d->remaining_kg }} / {{ (float) $d->capacity_kg }} kg</td>
                            <td class="px-4 py-3 whitespace-nowrap">@include('admin.maritime._price', ['d' => $d])</td>
                            <td class="px-4 py-3"><a href="{{ route('admin.maritime.bookings.index', ['departure' => $d->id]) }}" class="text-primary-400 underline">{{ $d->paid_bookings_count }}</a></td>
                            <td class="px-4 py-3">
                                <span>{{ \App\Models\MaritimeDeparture::STATUSES[$d->status] ?? $d->status }}</span>
                                <div class="mt-1 text-xs text-gray-400">
                                    Capacité : {{ (float) $d->capacity_kg }} kg
                                    @if($d->estimated_arrival_date) · arrivée {{ $d->estimated_arrival_date->format('d/m/Y') }}@endif
                                </div>
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                <button type="button" onclick="document.getElementById('departure-edit-{{ $d->id }}').showModal()" class="px-3 py-1 bg-indigo-600 text-white rounded hover:bg-indigo-700">Modifier</button>
                                @if($d->bookings_count > 0)
                                    <button type="button" disabled title="Ce départ possède des envois." class="ml-1 px-3 py-1 bg-gray-600 text-gray-300 rounded cursor-not-allowed">Utilisé</button>
                                @else
                                    <form action="{{ route('admin.maritime.departures.destroy', $d) }}" method="POST" class="inline"
                                          data-confirm="Supprimer définitivement ce départ ? Cette action est irréversible.">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="ml-1 px-3 py-1 bg-red-700 text-white rounded hover:bg-red-800">Supprimer</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="px-4 py-8 text-center text-gray-400">Aucun départ.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @foreach($departures as $d)
            @php($hasBookings = $d->bookings_count > 0)
            <dialog id="departure-edit-{{ $d->id }}" class="w-11/12 max-w-3xl rounded-xl border border-dark-200 bg-dark-100 p-0 text-white shadow-2xl backdrop:bg-black/70">
                <form action="{{ route('admin.maritime.departures.update', $d) }}" method="POST"
                      x-data="{ unit: @js($d->isPricedPerCbm() ? 'cbm' : 'kg') }"
                      class="p-6">
                    @csrf @method('PUT')
                        @if($d->partner_freight_rate_id && !$hasBookings)
                            <input type="hidden" name="partner_freight_rate_id" value="{{ $d->partner_freight_rate_id }}">
                        @endif
                    <div class="mb-5 flex items-start justify-between gap-4">
                        <div>
                            <h3 class="text-xl font-semibold">Modifier le départ du {{ $d->departure_date->format('d/m/Y') }}</h3>
                            <p class="mt-1 text-sm text-gray-400">
                                @if($hasBookings)
                                    Des envois sont déjà liés : le trajet, la date et le tarif sont protégés pour préserver leurs réservations.
                                @else
                                    Modifiez les informations du départ. Les kilos déjà réservés sont conservés.
                                @endif
                            </p>
                        </div>
                        <button type="button" onclick="this.closest('dialog').close()" class="text-2xl leading-none text-gray-400 hover:text-white" aria-label="Fermer">&times;</button>
                    </div>
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <label class="text-sm text-gray-300">QG de départ
                            <select name="origin_hub_id" required @disabled($hasBookings) class="{{ $input }} mt-1">
                                @foreach($hubs as $hub)<option value="{{ $hub->id }}" @selected($d->origin_hub_id === $hub->id)>{{ $hub->city }} — {{ $hub->name }}</option>@endforeach
                            </select>
                        </label>
                        <label class="text-sm text-gray-300">QG d'arrivée
                            <select name="destination_hub_id" required @disabled($hasBookings) class="{{ $input }} mt-1">
                                @foreach($hubs as $hub)<option value="{{ $hub->id }}" @selected($d->destination_hub_id === $hub->id)>{{ $hub->city }} — {{ $hub->name }}</option>@endforeach
                            </select>
                        </label>
                        <label class="text-sm text-gray-300">Date de départ
                            <input type="date" name="departure_date" required value="{{ $d->departure_date->toDateString() }}" @disabled($hasBookings) class="{{ $input }} mt-1">
                        </label>
                        <label class="text-sm text-gray-300">Arrivée estimée
                            <input type="date" name="estimated_arrival_date" value="{{ $d->estimated_arrival_date?->toDateString() }}" class="{{ $input }} mt-1">
                        </label>
                        <fieldset @disabled($hasBookings) class="text-sm text-gray-300">
                            <legend>Tarif du transporteur</legend>
                            <div class="mt-1 flex overflow-hidden rounded-lg border border-dark-200">
                                <label class="flex-1 cursor-pointer py-2 text-center" :class="unit === 'cbm' ? 'bg-primary-600 text-white' : 'bg-dark-50 text-gray-300'">
                                    <input type="radio" name="pricing_unit" value="cbm" x-model="unit" class="hidden">Au m³
                                </label>
                                <label class="flex-1 cursor-pointer py-2 text-center" :class="unit === 'kg' ? 'bg-primary-600 text-white' : 'bg-dark-50 text-gray-300'">
                                    <input type="radio" name="pricing_unit" value="kg" x-model="unit" class="hidden">Au kg
                                </label>
                            </div>
                        </fieldset>
                        <div class="flex items-end gap-2">
                            <label class="flex-1 text-sm text-gray-300"><span x-text="unit === 'cbm' ? 'Prix pour 1 m³' : 'Prix pour 1 kg'"></span>
                                <input type="number" step="0.01" min="0.01" name="price"
                                       value="{{ $d->isPricedPerCbm() ? (float) $d->price_per_cbm : (float) $d->price_per_kg }}"
                                       required @disabled($hasBookings) class="{{ $input }} mt-1">
                            </label>
                            <label class="w-28 text-sm text-gray-300">Devise
                                <input name="currency" maxlength="3" value="{{ $d->currency }}" required @disabled($hasBookings) class="{{ $input }} mt-1 uppercase">
                            </label>
                        </div>
                        <label class="text-sm text-gray-300">Capacité (kg)
                            <input type="number" step="0.5" min="1" name="capacity_kg" value="{{ (float) $d->capacity_kg }}" required class="{{ $input }} mt-1">
                        </label>
                        <label class="text-sm text-gray-300 md:col-span-2">Notes pour l'expéditeur
                            <textarea name="notes" rows="2" class="{{ $input }} mt-1">{{ $d->notes }}</textarea>
                        </label>
                        <label class="text-sm text-gray-300 md:col-span-2">Statut
                            <select name="status" required class="{{ $input }} mt-1">
                                @foreach(\App\Models\MaritimeDeparture::STATUSES as $value => $label)<option value="{{ $value }}" @selected($d->status === $value)>{{ $label }}</option>@endforeach
                            </select>
                        </label>
                    </div>
                    <div class="mt-6 flex justify-end gap-3">
                        <button type="button" onclick="this.closest('dialog').close()" class="rounded-lg border border-dark-200 px-4 py-2 text-gray-300 hover:bg-dark-50">Annuler</button>
                        <button type="submit" class="{{ $btn }}">Enregistrer les modifications</button>
                    </div>
                </form>
            </dialog>
        @endforeach
        @if($departures->hasPages())<div class="px-6 py-4 border-t border-dark-200">{{ $departures->links() }}</div>@endif
    </div>

    <!-- Informations -->
    <div class="bg-dark-100 rounded-xl shadow-lg border border-dark-200 p-6">
        <h2 class="text-lg font-semibold text-white mb-4"><i class="fas fa-building mr-2 text-primary-400"></i>Informations</h2>
        <form action="{{ route('admin.maritime.partners.update', $partner) }}" method="POST" class="grid grid-cols-1 md:grid-cols-3 gap-4">
            @csrf @method('PUT')
            <div>
                <label class="block text-sm text-gray-300 mb-1">Nom *</label>
                <input name="name" required value="{{ old('name', $partner->name) }}" class="{{ $input }}">
            </div>
            <div>
                <label class="block text-sm text-gray-300 mb-1">Téléphone</label>
                <input name="phone" value="{{ old('phone', $partner->phone) }}" class="{{ $input }}">
            </div>
            <div>
                <label class="block text-sm text-gray-300 mb-1">E-mail</label>
                <input name="email" type="email" value="{{ old('email', $partner->email) }}" class="{{ $input }}">
            </div>
            <div class="md:col-span-3">
                <label class="block text-sm text-gray-300 mb-1">Conditions affichées à l'expéditeur</label>
                <textarea name="conditions" rows="3" placeholder="Objets interdits, emballage, délais…" class="{{ $input }}">{{ old('conditions', $partner->getTranslation('conditions', 'fr')) }}</textarea>
                <textarea name="translations[en][conditions]" rows="2" placeholder="English (optional)" class="{{ $input }} mt-2">{{ old('translations.en.conditions', $partner->getTranslation('conditions', 'en')) }}</textarea>
            </div>
            <div class="md:col-span-3">
                <label class="block text-sm text-gray-300 mb-1">Description</label>
                <textarea name="description" rows="2" class="{{ $input }}">{{ old('description', $partner->getTranslation('description', 'fr')) }}</textarea>
            </div>
            <label class="flex items-center gap-2 text-gray-300">
                <input type="checkbox" name="is_active" value="1" @checked($partner->is_active)> Actif (départs proposés dans l'app)
            </label>
            <div class="md:col-span-2 text-right">
                <button type="submit" class="{{ $btn }}">Enregistrer</button>
            </div>
        </form>
    </div>
</div>
@endsection
