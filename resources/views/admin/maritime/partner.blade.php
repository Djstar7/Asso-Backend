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
            <p class="text-gray-400">Partenaire maritime : ses QG, ses départs et ses paiements.</p>
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

    <!-- QG -->
    <div class="bg-dark-100 rounded-xl shadow-lg border border-dark-200 p-6 mb-6">
        <h2 class="text-lg font-semibold text-white mb-1"><i class="fas fa-warehouse mr-2 text-primary-400"></i>QG</h2>
        <p class="text-sm text-gray-400 mb-4">Lieux fixes où l'expéditeur dépose son colis (départ) et où le destinataire le récupère avec son code (arrivée).</p>

        @if($hubs->isNotEmpty())
        <div class="overflow-x-auto mb-4">
            <table class="w-full text-sm">
                <thead class="text-gray-400 text-xs uppercase text-left">
                    <tr><th class="py-1">Nom</th><th>Pays / ville</th><th>Adresse</th><th>Téléphone</th><th>Horaires</th><th>Actif</th><th></th></tr>
                </thead>
                <tbody class="divide-y divide-dark-200">
                    @foreach($hubs as $hub)
                        <tr>
                            <form id="hub-{{ $hub->id }}" action="{{ route('admin.maritime.hubs.update', $hub) }}" method="POST">@csrf @method('PUT')</form>
                            <td class="py-1 pr-2"><input form="hub-{{ $hub->id }}" name="name" value="{{ $hub->name }}" required class="w-full {{ $small }}"></td>
                            <td class="pr-2 whitespace-nowrap">
                                <select form="hub-{{ $hub->id }}" name="country" class="{{ $small }}">
                                    @foreach($countries as $code => $name)<option value="{{ $code }}" @selected($hub->country === $code)>{{ $name }}</option>@endforeach
                                </select>
                                <input form="hub-{{ $hub->id }}" name="city" value="{{ $hub->city }}" required class="w-28 {{ $small }}">
                            </td>
                            <td class="pr-2"><input form="hub-{{ $hub->id }}" name="address" value="{{ $hub->address }}" required class="w-full {{ $small }}"></td>
                            <td class="pr-2"><input form="hub-{{ $hub->id }}" name="phone" value="{{ $hub->phone }}" class="w-32 {{ $small }}"></td>
                            <td class="pr-2"><input form="hub-{{ $hub->id }}" name="opening_hours" value="{{ $hub->opening_hours }}" class="w-36 {{ $small }}"></td>
                            <td class="pr-2"><input form="hub-{{ $hub->id }}" type="checkbox" name="is_active" value="1" @checked($hub->is_active)></td>
                            <td><button form="hub-{{ $hub->id }}" type="submit" class="px-3 py-1 bg-blue-600 text-white rounded hover:bg-blue-700">Enregistrer</button></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
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
            <p class="text-sm text-gray-400 mb-4">L'expéditeur saisit toujours des kilos. Au m³, ses kilos sont convertis en m³ (kilos pour 1 m³) puis multipliés par le tarif, avant que le prix lui soit affiché.</p>
            <form action="{{ route('admin.maritime.departures.store', $partner) }}" method="POST"
                  x-data="{ unit: @js(old('pricing_unit', 'cbm')), price: @js(old('price', '')), kg: @js(old('kg_per_cbm', $settings['kg_per_cbm'])) }"
                  class="grid grid-cols-1 md:grid-cols-4 gap-4 items-end">
                @csrf
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
                        <input type="number" step="0.01" min="0.01" name="price" x-model="price" required class="{{ $input }}">
                    </div>
                    <div class="w-24">
                        <label class="block text-sm text-gray-300 mb-1">Devise</label>
                        <input name="currency" maxlength="3" required value="{{ old('currency', 'EUR') }}" class="{{ $input }} uppercase">
                    </div>
                </div>
                <div x-show="unit === 'cbm'">
                    <label class="block text-sm text-gray-300 mb-1">Kilos pour 1 m³</label>
                    <input type="number" step="1" min="1" max="5000" name="kg_per_cbm" x-model="kg" class="{{ $input }}">
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
                    <tr><th class="px-4 py-3">Départ</th><th class="px-4 py-3">Trajet</th><th class="px-4 py-3">Clôture / dépôt</th><th class="px-4 py-3">Kilos libres</th><th class="px-4 py-3">Tarif</th><th class="px-4 py-3">Envois</th><th class="px-4 py-3">Statut et capacité</th></tr>
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
                                <form action="{{ route('admin.maritime.departures.update', $d) }}" method="POST" class="flex flex-wrap gap-2 items-center">
                                    @csrf @method('PUT')
                                    <select name="status" class="{{ $small }}">
                                        @foreach(\App\Models\MaritimeDeparture::STATUSES as $value => $label)<option value="{{ $value }}" @selected($d->status === $value)>{{ $label }}</option>@endforeach
                                    </select>
                                    <input type="number" step="0.5" min="0" name="capacity_kg" value="{{ (float) $d->capacity_kg }}" class="w-24 {{ $small }}" title="Capacité (kg)">
                                    <input type="date" name="estimated_arrival_date" value="{{ $d->estimated_arrival_date?->toDateString() }}" class="{{ $small }}" title="Arrivée estimée">
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
