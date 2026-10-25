@extends('admin.layouts.app')

@section('content')
@php
    $in = 'w-full px-3 py-2 bg-dark-50 border border-dark-200 rounded-lg text-white focus:border-primary-500 focus:outline-none';
    $label = 'block text-sm text-gray-400 mb-1';
    $card = 'bg-dark-100 rounded-xl shadow-lg border border-dark-200 p-6 mb-6';
    $isLand = $listing->isLand();
    $exists = $listing->exists;
    $kinds = array_merge(\App\Models\RealEstate\Listing::RENT_KINDS, \App\Models\RealEstate\Listing::LAND_KINDS);
    $kindLabels = ['house' => 'Maison', 'villa' => 'Villa', 'apartment' => 'Appartement', 'studio' => 'Studio', 'room' => 'Chambre', 'duplex' => 'Duplex', 'residence' => 'Résidence / cité',
        'building_land' => 'Terrain constructible', 'farm_land' => 'Terrain agricole', 'commercial_land' => 'Terrain commercial'];
    $units = old('units', $exists ? $listing->units->map(fn ($u) => $u->only(['id', 'label', 'surface_min', 'surface_max', 'net_price', 'old_net_price', 'capacity', 'kitchen', 'furnished', 'serviced', 'units_total', 'units_available']))->values()->all() : []);
    $selectedAmenities = old('amenity_ids', $exists ? $listing->amenities->pluck('id')->all() : []);
    $missing = $missing ?? [];
    $val = fn ($f) => old($f, $listing->$f);
    $checked = fn ($f) => old($f, $listing->$f) ? 'checked' : '';
@endphp
<div class="p-6">
    <div class="mb-6 flex items-start justify-between flex-wrap gap-3">
        <div>
            <a href="{{ route('admin.real-estate.listings.index') }}" class="text-sm text-gray-400 hover:text-white"><i class="fas fa-arrow-left mr-1"></i> Annonces</a>
            <h1 class="text-2xl font-bold text-white mt-1">
                {{ $exists ? $listing->title : ($isLand ? 'Nouveau terrain' : 'Nouvelle maison') }}
            </h1>
            @if($exists)
                <p class="text-gray-400 flex items-center gap-2 mt-1">
                    {{ $listing->reference }} · {{ $isLand ? 'Terrain à vendre' : ($listing->isSale() ? 'Maison à vendre' : 'Maison à louer') }} · @include('admin.real_estate._status', ['status' => $listing->status])
                    · {{ $listing->views_count }} vues
                </p>
            @endif
        </div>
        @if($exists)
            <div class="flex flex-wrap gap-2" x-data="{ reject: false }">
                @if($listing->status !== 'published')
                    <form method="POST" action="{{ route('admin.real-estate.listings.approve', $listing) }}">@csrf
                        <button class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700"><i class="fas fa-check mr-2"></i>Publier</button>
                    </form>
                @endif
                @if(in_array($listing->status, ['pending_review', 'published'], true))
                    <button type="button" @click="reject = !reject" class="px-4 py-2 bg-red-600/80 text-white rounded-lg hover:bg-red-700"><i class="fas fa-times mr-2"></i>Refuser</button>
                @endif
                <form method="POST" action="{{ route('admin.real-estate.listings.featured', $listing) }}">@csrf
                    <button class="px-4 py-2 bg-dark-200 text-white rounded-lg hover:bg-dark-50"><i class="fas fa-star mr-2 {{ $listing->is_featured ? 'text-primary-400' : '' }}"></i>{{ $listing->is_featured ? 'Retirer de la une' : 'Mettre à la une' }}</button>
                </form>
                <form method="POST" action="{{ route('admin.real-estate.listings.archive', $listing) }}">@csrf
                    <button class="px-4 py-2 bg-dark-200 text-white rounded-lg hover:bg-dark-50"><i class="fas fa-archive mr-2"></i>{{ $listing->status === 'archived' ? 'Désarchiver' : 'Archiver' }}</button>
                </form>
                @include('admin.real_estate.listings._delete')
                <form x-show="reject" x-cloak method="POST" action="{{ route('admin.real-estate.listings.reject', $listing) }}" class="w-full flex gap-2 mt-2">@csrf
                    <input name="reason" required placeholder="Motif communiqué au bailleur (photos floues, prix incohérent…)" class="{{ $in }}">
                    <button class="px-4 py-2 bg-red-600 text-white rounded-lg">Envoyer</button>
                </form>
            </div>
        @endif
    </div>

    @include('admin.real_estate._alerts')

    @if($exists)
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
            @if(! $isLand)
                <div class="bg-dark-100 rounded-xl border border-dark-200 p-5">
                    <h2 class="text-white font-semibold mb-3"><i class="fas fa-user mr-2 text-primary-400"></i>Bailleur</h2>
                    @if($owner = $listing->owner)
                        <p class="text-white">{{ $owner->name }}</p>
                        <p class="text-sm text-gray-400">{{ $owner->phone }} · {{ $owner->email }}</p>
                        <div class="mt-2">@include('admin.real_estate._status', ['status' => $owner->landlord_verification_status ?: 'none'])</div>
                        @if($v = \App\Models\RealEstate\LandlordVerification::where('user_id', $owner->id)->first())
                            <a href="{{ route('admin.real-estate.verifications.show', $v) }}" class="inline-block mt-3 text-sm text-primary-400 hover:text-primary-300"><i class="fas fa-id-card mr-1"></i>Voir ses pièces</a>
                        @endif
                    @endif
                </div>
            @endif
            <div class="bg-dark-100 rounded-xl border border-dark-200 p-5">
                <h2 class="text-white font-semibold mb-3"><i class="fas fa-clipboard-check mr-2 text-primary-400"></i>Contrôle avant publication</h2>
                @if($missing)
                    <ul class="text-sm text-yellow-300 space-y-1">
                        @foreach($missing as $m)<li><i class="fas fa-exclamation-triangle mr-1"></i>{{ __("real_estate.missing.$m") }}</li>@endforeach
                    </ul>
                @else
                    <p class="text-sm text-green-300"><i class="fas fa-check-circle mr-1"></i>Photos, visite et prix complets.</p>
                @endif
                <p class="text-xs text-gray-500 mt-3">Vérifiez aussi la qualité des images, la cohérence du prix et la description du quartier.</p>
                @if($listing->rejection_reason)
                    <p class="text-sm text-red-300 mt-3">Dernier refus : {{ $listing->rejection_reason }}</p>
                @endif
            </div>
            @isset($quote)
                <div class="bg-dark-100 rounded-xl border border-dark-200 p-5">
                    <h2 class="text-white font-semibold mb-3"><i class="fas fa-calculator mr-2 text-primary-400"></i>{{ $isLand ? 'Acompte (lot le moins cher)' : 'Paiement d\'une réservation' }}</h2>
                    <dl class="text-sm space-y-1">
                        <div class="flex justify-between"><dt class="text-gray-400">Prix affiché</dt><dd class="text-white">{{ number_format($quote['unit_price'], 0, ',', ' ') }} FCFA</dd></div>
                        <div class="flex justify-between"><dt class="text-gray-400">{{ $isLand ? 'Acompte' : 'Avance ('.$quote['advance_months'].' mois)' }}</dt><dd class="text-white">{{ number_format($quote['base_amount'], 0, ',', ' ') }}</dd></div>
                        <div class="flex justify-between"><dt class="text-gray-400">Commission cabinet (en supplément)</dt><dd class="text-white">{{ number_format($quote['commission'], 0, ',', ' ') }}</dd></div>
                        <div class="flex justify-between border-t border-dark-200 pt-1"><dt class="text-gray-300">Total payé</dt><dd class="text-white font-semibold">{{ number_format($quote['total'], 0, ',', ' ') }}</dd></div>
                        <div class="flex justify-between"><dt class="text-gray-400">{{ $listing->isManagedByCabinet() ? 'Cabinet (avance intégrale bloquée)' : 'Bailleur (avance intégrale bloquée)' }}</dt><dd class="text-white">{{ number_format($quote['owner_amount'], 0, ',', ' ') }}</dd></div>
                        <div class="flex justify-between"><dt class="text-gray-400">Part ASSO (en supplément)</dt><dd class="text-primary-300 font-semibold">{{ number_format($quote['platform_amount'], 0, ',', ' ') }}</dd></div>
                    </dl>
                </div>
            @endisset
        </div>
    @endif

    <form method="POST" enctype="multipart/form-data" action="{{ $exists ? route('admin.real-estate.listings.update', $listing) : route('admin.real-estate.listings.store') }}">
        @csrf
        @if($exists) @method('PUT') @endif
        <div class="{{ $card }}" x-data="{ category: @js(old('type', $listing->type)) }">
            <label class="{{ $label }}" for="listing-category">Rubrique de l’annonce</label>
            <select id="listing-category" name="type" x-model="category" class="{{ $in }}" x-init="$nextTick(() => $el.dispatchEvent(new Event('change')))"
                @change="const kind = $el.form.elements.property_kind; const land = category === 'land'; for (const option of kind.options) { option.hidden = option.dataset.land !== (land ? '1' : '0'); } if (kind.selectedOptions[0]?.hidden) kind.value = land ? 'building_land' : 'house';">
                <option value="rent">Maison à louer</option>
                <option value="sale">Maison à vendre</option>
                <option value="land">Terrain à vendre</option>
            </select>
            <p class="text-sm text-gray-400 mt-2">Choisissez la rubrique puis enregistrez pour reclasser cette offre. Ses photos et sa référence sont conservées.</p>
        </div>

        <div class="{{ $card }}">
            <h2 class="text-lg font-semibold text-white mb-4"><i class="fas fa-info-circle mr-2 text-primary-400"></i>Annonce</h2>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label class="{{ $label }}">Cabinet gestionnaire / partenaire</label>
                    <select name="cabinet_id" required class="{{ $in }}">
                        @foreach($cabinets as $cab)
                            <option value="{{ $cab->id }}" @selected($val('cabinet_id') == $cab->id)>{{ $cab->name }}{{ $cab->is_active ? '' : ' (inactif)' }}</option>
                        @endforeach
                    </select>
                    <p class="text-xs text-gray-500 mt-1">Le cabinet reçoit la commission. Le net revient au bailleur s’il a proposé la maison, sinon au cabinet.</p>
                    @if($cabinets->isEmpty())<p class="text-xs text-yellow-300 mt-1">Créez d'abord un cabinet partenaire actif.</p>@endif
                </div>
                <div>
                    <label class="{{ $label }}">Type de bien</label>
                    <select name="property_kind" required class="{{ $in }}">
                        @foreach($kinds as $k)<option value="{{ $k }}" data-land="{{ in_array($k, \App\Models\RealEstate\Listing::LAND_KINDS) ? '1' : '0' }}" @selected($val('property_kind') === $k)>{{ $kindLabels[$k] }}</option>@endforeach
                    </select>
                </div>
                <div class="{{ $isLand ? '' : 'md:col-span-2' }}">
                    <label class="{{ $label }}">Titre</label>
                    <input name="title" required value="{{ $val('title') }}" class="{{ $in }}">
                </div>
                <div class="md:col-span-3">
                    <label class="{{ $label }}">Titre (EN)</label>
                    <input name="translations[en][title]" value="{{ old('translations.en.title', $translations['en']['title'] ?? '') }}" class="{{ $in }}">
                </div>
                <div class="md:col-span-3 grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="{{ $label }}">Description libre (ajoutée à la description générée)</label>
                        <textarea name="owner_description" rows="4" class="{{ $in }}">{{ $val('owner_description') }}</textarea>
                    </div>
                    <div>
                        <label class="{{ $label }}">Description (EN)</label>
                        <textarea name="translations[en][owner_description]" rows="4" class="{{ $in }}">{{ old('translations.en.owner_description', $translations['en']['owner_description'] ?? '') }}</textarea>
                    </div>
                </div>
                <div><label class="{{ $label }}">Ville</label><input name="city" required value="{{ $val('city') }}" class="{{ $in }}"></div>
                <div><label class="{{ $label }}">Quartier / localité</label><input name="district" value="{{ $val('district') }}" class="{{ $in }}"></div>
                <div><label class="{{ $label }}">Adresse exacte (communiquée après paiement)</label><input name="address" value="{{ $val('address') }}" class="{{ $in }}"></div>
                <div><label class="{{ $label }}">Latitude</label><input name="latitude" type="number" step="0.0000001" value="{{ $val('latitude') }}" class="{{ $in }}"></div>
                <div><label class="{{ $label }}">Longitude</label><input name="longitude" type="number" step="0.0000001" value="{{ $val('longitude') }}" class="{{ $in }}"></div>
            </div>
        </div>

        <div class="{{ $card }}">
            <h2 class="text-lg font-semibold text-white mb-4"><i class="fas fa-road mr-2 text-primary-400"></i>{{ $isLand ? 'Terrain et environnement' : 'Caractéristiques et quartier' }}</h2>
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                @if($isLand)
                    <label class="flex items-center gap-2 text-white"><input type="checkbox" name="land_titled" value="1" {{ $checked('land_titled') }} class="rounded"> Terrain titré</label>
                    <label class="flex items-center gap-2 text-white"><input type="checkbox" name="serviced" value="1" {{ $checked('serviced') }} class="rounded"> Viabilisé</label>
                    <div><label class="{{ $label }}">Situation des papiers</label><input name="land_title_status" value="{{ $val('land_title_status') }}" placeholder="Titre foncier n°…, morcellement en cours…" class="{{ $in }}"></div>
                    <div><label class="{{ $label }}">Acompte (%)</label><input name="deposit_percent" type="number" min="1" max="100" step="0.5" value="{{ $val('deposit_percent') }}" placeholder="Défaut" class="{{ $in }}"></div>
                @else
                    <div><label class="{{ $label }}">Pièces</label><input name="rooms" type="number" min="0" value="{{ $val('rooms') }}" class="{{ $in }}"></div>
                    <div><label class="{{ $label }}">Chambres</label><input name="bedrooms" type="number" min="0" value="{{ $val('bedrooms') }}" class="{{ $in }}"></div>
                    <div><label class="{{ $label }}">Douches</label><input name="bathrooms" type="number" min="0" value="{{ $val('bathrooms') }}" class="{{ $in }}"></div>
                    <div><label class="{{ $label }}">Surface (m²)</label><input name="surface_m2" type="number" min="1" value="{{ $val('surface_m2') }}" class="{{ $in }}"></div>
                    <label class="flex items-center gap-2 text-white"><input type="checkbox" name="furnished" value="1" {{ $checked('furnished') }} class="rounded"> Meublé</label>
                @endif
                <div>
                    <label class="{{ $label }}">Accès à la route</label>
                    <select name="road_access" class="{{ $in }}">
                        <option value="">—</option>
                        @foreach(['roadside' => 'En bordure de route', 'near_road' => 'Proche de la route', 'inside' => 'À l\'intérieur du quartier'] as $k => $l)<option value="{{ $k }}" @selected($val('road_access') === $k)>{{ $l }}</option>@endforeach
                    </select>
                </div>
                <div><label class="{{ $label }}">Distance de la route (m)</label><input name="distance_to_road_m" type="number" min="0" value="{{ $val('distance_to_road_m') }}" class="{{ $in }}"></div>
                <div>
                    <label class="{{ $label }}">État de la route</label>
                    <select name="road_condition" class="{{ $in }}">
                        <option value="">—</option>
                        @foreach(['paved' => 'Goudronnée', 'dirt' => 'En terre, praticable', 'poor' => 'Difficile en saison des pluies'] as $k => $l)<option value="{{ $k }}" @selected($val('road_condition') === $k)>{{ $l }}</option>@endforeach
                    </select>
                </div>
                <div>
                    <label class="{{ $label }}">Eau</label>
                    <select name="water_source" class="{{ $in }}">
                        <option value="">—</option>
                        @foreach(['network' => 'Réseau', 'borehole' => 'Forage', 'well' => 'Puits', 'none' => 'Aucune'] as $k => $l)<option value="{{ $k }}" @selected($val('water_source') === $k)>{{ $l }}</option>@endforeach
                    </select>
                </div>
                <div>
                    <label class="{{ $label }}">Électricité</label>
                    <select name="electricity" class="{{ $in }}">
                        <option value="">—</option>
                        @foreach(['network' => 'Réseau, compteur classique', 'prepaid' => 'Réseau, prépayé', 'solar' => 'Solaire', 'none' => 'Aucune'] as $k => $l)<option value="{{ $k }}" @selected($val('electricity') === $k)>{{ $l }}</option>@endforeach
                    </select>
                </div>
                <div>
                    <label class="{{ $label }}">Sécurité du quartier</label>
                    <select name="security_level" class="{{ $in }}">
                        <option value="">—</option>
                        @foreach(['high' => 'Très sûr', 'medium' => 'Calme', 'low' => 'Animé'] as $k => $l)<option value="{{ $k }}" @selected($val('security_level') === $k)>{{ $l }}</option>@endforeach
                    </select>
                </div>
                <label class="flex items-center gap-2 text-white"><input type="checkbox" name="is_landlocked" value="1" {{ $checked('is_landlocked') }} class="rounded"> Zone enclavée</label>
                <label class="flex items-center gap-2 text-white"><input type="checkbox" name="has_parking" value="1" {{ $checked('has_parking') }} class="rounded"> Parking</label>
                <label class="flex items-center gap-2 text-white"><input type="checkbox" name="has_fence" value="1" {{ $checked('has_fence') }} class="rounded"> Barrière / clôture</label>
                <label class="flex items-center gap-2 text-white"><input type="checkbox" name="has_guard" value="1" {{ $checked('has_guard') }} class="rounded"> Gardien</label>
            </div>
        </div>

        @unless($isLand)
            <div class="{{ $card }}">
                <h2 class="text-lg font-semibold text-white mb-4"><i class="fas fa-money-bill mr-2 text-primary-400"></i>Conditions et prix</h2>
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                    <div><label class="{{ $label }}">{{ $listing->isSale() ? 'Prix de vente (FCFA)' : 'Loyer mensuel de référence (FCFA)' }}</label><input name="net_price" type="number" min="0" value="{{ $val('net_price') }}" class="{{ $in }}"></div>
                    <div><label class="{{ $label }}">Ancien prix (barré)</label><input name="old_net_price" type="number" min="0" value="{{ $val('old_net_price') }}" class="{{ $in }}"></div>
                    <div><label class="{{ $label }}">Durée minimale (mois)</label><input name="min_lease_months" type="number" min="1" value="{{ $val('min_lease_months') }}" class="{{ $in }}"></div>
                    <div><label class="{{ $label }}">Mois d'avance</label><input name="advance_months" type="number" min="1" value="{{ $val('advance_months') }}" class="{{ $in }}"></div>
                    <div><label class="{{ $label }}">Disponible à partir du</label><input name="available_from" type="date" value="{{ optional($listing->available_from)->toDateString() }}" class="{{ $in }}"></div>
                    <div class="md:col-span-3"><label class="{{ $label }}">Offre spéciale</label><input name="promo_text" value="{{ $val('promo_text') }}" class="{{ $in }}"></div>
                </div>
            </div>
        @endunless

        <!-- Logements ou lots -->
        <div class="{{ $card }}" x-data="{ units: @js(array_values($units)) }">
            <div class="flex items-center justify-between mb-2">
                <h2 class="text-lg font-semibold text-white"><i class="fas fa-th-large mr-2 text-primary-400"></i>{{ $isLand ? 'Lots de surface' : 'Types de logement' }}</h2>
                <button type="button" @click="units.push({units_total: 1})" class="px-3 py-1.5 text-sm bg-dark-200 text-white rounded-lg"><i class="fas fa-plus mr-1"></i>Ajouter</button>
            </div>
            <p class="text-sm text-gray-400 mb-4">{{ $isLand ? 'Une ligne par surface exacte proposée (300 m², 500 m²…), avec le prix total du lot. Le client voit le prix au m², calculé automatiquement.' : 'Pour une cité ou une résidence : une ligne par type (chambre, studio…). Une maison simple peut rester sans ligne.' }}</p>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead><tr class="text-gray-400 text-left">
                        <th class="py-2 pr-2">Libellé</th><th class="py-2 pr-2">Surface min</th><th class="py-2 pr-2">Surface max</th><th class="py-2 pr-2">Prix de référence</th>
                        @unless($isLand)<th class="py-2 pr-2">Pers.</th><th class="py-2 pr-2">Cuisine</th><th class="py-2 pr-2">Meublé</th>@else<th class="py-2 pr-2">Viabilisé</th>@endunless
                        <th class="py-2 pr-2">Total</th><th class="py-2 pr-2">Dispo.</th><th></th>
                    </tr></thead>
                    <tbody>
                    <template x-for="(u, i) in units" :key="i">
                        <tr>
                            <td class="py-1 pr-2"><input type="hidden" :name="`units[${i}][id]`" :value="u.id"><input :name="`units[${i}][label]`" x-model="u.label" class="{{ $in }}"></td>
                            <td class="py-1 pr-2"><input type="number" min="1" :name="`units[${i}][surface_min]`" x-model="u.surface_min" class="{{ $in }}"></td>
                            <td class="py-1 pr-2"><input type="number" min="1" :name="`units[${i}][surface_max]`" x-model="u.surface_max" class="{{ $in }}"></td>
                            <td class="py-1 pr-2"><input type="number" min="0" :name="`units[${i}][net_price]`" x-model="u.net_price" class="{{ $in }}"></td>
                            @unless($isLand)
                                <td class="py-1 pr-2"><input type="number" min="1" :name="`units[${i}][capacity]`" x-model="u.capacity" class="{{ $in }}"></td>
                                <td class="py-1 pr-2"><select :name="`units[${i}][kitchen]`" x-model="u.kitchen" class="{{ $in }}"><option value="">—</option><option value="private">Privative</option><option value="shared">Partagée</option><option value="none">Aucune</option></select></td>
                                <td class="py-1 pr-2 text-center"><input type="checkbox" value="1" :name="`units[${i}][furnished]`" :checked="u.furnished" class="rounded"></td>
                            @else
                                <td class="py-1 pr-2 text-center"><input type="checkbox" value="1" :name="`units[${i}][serviced]`" :checked="u.serviced" class="rounded"></td>
                            @endunless
                            <td class="py-1 pr-2"><input type="number" min="1" :name="`units[${i}][units_total]`" x-model="u.units_total" class="{{ $in }}"></td>
                            <td class="py-1 pr-2"><input type="number" min="0" :name="`units[${i}][units_available]`" x-model="u.units_available" class="{{ $in }}"></td>
                            <td class="py-1"><button type="button" @click="units.splice(i, 1)" class="text-red-400 hover:text-red-300"><i class="fas fa-trash"></i></button></td>
                        </tr>
                    </template>
                    </tbody>
                </table>
            </div>
        </div>

        @unless($isLand)
            <div class="{{ $card }}">
                <h2 class="text-lg font-semibold text-white mb-4"><i class="fas fa-check-square mr-2 text-primary-400"></i>Atouts, équipements et services</h2>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    @foreach($amenities->groupBy('kind') as $kind => $group)
                        <div>
                            <p class="text-sm text-gray-400 mb-2">{{ ['highlight' => 'Atouts', 'equipment' => 'Équipements', 'service' => 'Services'][$kind] ?? $kind }}</p>
                            @foreach($group as $a)
                                <label class="flex items-center gap-2 text-white text-sm mb-1"><input type="checkbox" name="amenity_ids[]" value="{{ $a->id }}" @checked(in_array($a->id, $selectedAmenities)) class="rounded"> {{ $a->name }}</label>
                            @endforeach
                        </div>
                    @endforeach
                </div>
            </div>
        @endunless

        <div class="{{ $card }}">
            <h2 class="text-lg font-semibold text-white mb-4"><i class="fas fa-images mr-2 text-primary-400"></i>Photos et vidéo</h2>
            @if($exists)
                @php($byRoom = $listing->media->groupBy(fn ($m) => $m->room_id ?: 0))
                @foreach([0 => null] + $listing->tourRooms->keyBy('id')->all() as $roomId => $room)
                    @if(($byRoom[$roomId] ?? collect())->isNotEmpty())
                        <p class="text-sm text-gray-300 mb-2 mt-4">{{ $room ? ($room->label ?: __('real_estate.rooms.'.$room->kind)) : 'Photos générales' }}</p>
                        <div class="grid grid-cols-2 md:grid-cols-6 gap-3">
                            @foreach($byRoom[$roomId] as $m)
                                <div class="relative group rounded-lg overflow-hidden border {{ $m->is_cover ? 'border-primary-500' : 'border-dark-200' }}">
                                    @if($m->type === 'video')
                                        <video src="{{ media_url($m->path) }}" class="w-full h-28 object-cover" controls></video>
                                    @else
                                        <a href="{{ media_url($m->path) }}" target="_blank"><img src="{{ media_url($m->path) }}" class="w-full h-28 object-cover" alt=""></a>
                                    @endif
                                    <div class="absolute top-1 right-1 flex gap-1">
                                        @if($m->type === 'image' && ! $m->is_cover)
                                            <button form="cover-{{ $m->id }}" class="px-2 py-1 text-xs bg-black/70 text-white rounded" title="Photo principale"><i class="fas fa-star"></i></button>
                                        @endif
                                        <button form="del-{{ $m->id }}" class="px-2 py-1 text-xs bg-red-600/80 text-white rounded" title="Supprimer"><i class="fas fa-trash"></i></button>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                @endforeach
            @endif
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
                @unless($isLand)
                    <div>
                        <label class="{{ $label }}">Pièce des nouvelles photos</label>
                        <select name="photo_room_kind" class="{{ $in }}">
                            <option value="">Photos générales</option>
                            @foreach(\App\Models\RealEstate\Room::KINDS as $roomKind)
                                <option value="{{ $roomKind }}">{{ __('real_estate.rooms.'.$roomKind) }}</option>
                            @endforeach
                        </select>
                        <p class="text-xs text-gray-500 mt-1">Ajoutez les photos d’entrée, puis celles des pièces, et une vidéo avant de publier.</p>
                    </div>
                @endunless
                <div><label class="{{ $label }}">Ajouter des photos</label><input type="file" name="photos[]" multiple accept="image/*" class="{{ $in }}"></div>
                @include('admin.real_estate.listings._video_upload')
            </div>
        </div>

        <div class="flex justify-end">
            <button type="submit" class="px-6 py-3 bg-gradient-to-r from-primary-500 to-primary-600 text-white rounded-lg hover:shadow-lg"><i class="fas fa-save mr-2"></i>Enregistrer</button>
        </div>
    </form>

    @if($exists)
        @foreach($listing->media as $m)
            <form id="del-{{ $m->id }}" method="POST" action="{{ route('admin.real-estate.listings.media.destroy', [$listing, $m]) }}" data-confirm="Supprimer ce média ?">@csrf @method('DELETE')</form>
            <form id="cover-{{ $m->id }}" method="POST" action="{{ route('admin.real-estate.listings.media.cover', [$listing, $m]) }}">@csrf</form>
        @endforeach
    @endif
</div>
@endsection
