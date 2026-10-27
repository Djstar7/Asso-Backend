<div class="bg-dark-100 rounded-xl border border-dark-200 p-6 mb-6">
    <h2 class="text-lg font-semibold text-white mb-2">Tarifs internationaux du partenaire</h2>
    <p class="text-sm text-gray-400 mb-4">Renseignez une ligne par route, mode et catégorie. Le client paie ASSO à la réservation. L’échéance du tarif fixe le crédit du fret sur le Wallet partenaire : à l’embarquement ou à l’arrivée au QG. Les tarifs sont repris à la création d'un départ, sans modifier les envois déjà réservés.</p>
    @foreach($freightRates->concat([null]) as $rate)
        <details class="mb-4 border border-dark-200 rounded-lg p-3" @if(!$rate) open @endif>
            <summary class="cursor-pointer text-white font-medium">
                @if($rate)
                    {{ $rate->origin_country }} → {{ $rate->destination_country }} · {{ $rate->transport_mode === 'sea' ? 'Maritime' : 'Aérien' }} · {{ $rate->cargo_category === 'sensitive' ? 'Colis sensible' : 'Colis général' }} : {{ number_format($rate->rate_amount, 0, ',', ' ') }} {{ $rate->currency }}/{{ $rate->rate_unit === 'cbm' ? 'CBM' : 'kg' }} · {{ $rate->transit_days_max ? $rate->transit_days_min.'–'.$rate->transit_days_max.' jours' : 'Délai à confirmer' }}
                @else
                    Ajouter un tarif
                @endif
            </summary>
            <form method="POST" action="{{ $rate ? route('admin.maritime.freight-rates.update', [$partner, $rate]) : route('admin.maritime.freight-rates.store', $partner) }}" class="grid grid-cols-1 md:grid-cols-4 gap-3 mt-4"
                  x-data="{ mode: @js($rate?->transport_mode ?? old('transport_mode', 'sea')) }">
                @csrf
                @if($rate) @method('PUT') @endif
                @foreach(['origin_country' => 'Pays de départ', 'destination_country' => "Pays d’arrivée"] as $field => $label)
                    <label class="text-sm text-gray-300">{{ $label }}
                        <select name="{{ $field }}" required class="{{ $input }}" @disabled($rate)>
                            @foreach($countries as $code => $name)<option value="{{ $code }}" @selected(($rate?->$field ?? old($field, $field === 'origin_country' ? 'CN' : 'CM')) === $code)>{{ $name }}</option>@endforeach
                        </select>
                        @error($field)<span class="text-red-400">{{ $message }}</span>@enderror
                    </label>
                @endforeach
                @foreach(['transport_mode' => ['Mode', ['sea' => 'Maritime', 'air' => 'Aérien']], 'cargo_category' => ['Catégorie du colis', ['general' => 'Général', 'sensitive' => 'Sensible']], 'currency' => ['Devise', ['XAF' => 'FCFA (XAF)', 'EUR' => 'EUR', 'USD' => 'USD', 'CNY' => 'CNY', 'CAD' => 'CAD', 'GBP' => 'GBP']], 'freight_payment_timing' => ['Échéance du fret partenaire', ['departure' => 'Au départ', 'arrival' => "À l’arrivée à l’entrepôt"]]] as $field => [$label, $choices])
                    <label class="text-sm text-gray-300">{{ $label }}
                        <select name="{{ $field }}" required class="{{ $input }}"
                            @if($field === 'transport_mode') x-model="mode" @endif
                            @disabled($rate && in_array($field, ['transport_mode', 'cargo_category']))>
                            @foreach($choices as $value => $name)<option value="{{ $value }}" @selected(($rate?->$field ?? old($field, array_key_first($choices))) === $value)>{{ $name }}</option>@endforeach
                        </select>
                        @error($field)<span class="text-red-400">{{ $message }}</span>@enderror
                    </label>
                @endforeach
                <div class="text-sm text-gray-300">Unité de facturation
                    <input type="hidden" name="rate_unit" :value="mode === 'sea' ? 'cbm' : 'kg'">
                    <p class="mt-2" x-text="mode === 'sea' ? 'CBM (m³)' : 'Kilogramme (aérien)'"></p>
                </div>
                @foreach(['rate_amount' => 'Coût partenaire par unité', 'transit_days_min' => 'Délai minimum (jours)', 'transit_days_max' => 'Délai maximum (jours)'] as $field => $label)
                    <label class="text-sm text-gray-300">{{ $label }}
                        <input type="number" name="{{ $field }}" min="{{ $field === 'rate_amount' ? '0.01' : '1' }}" step="{{ $field === 'rate_amount' ? '0.01' : '1' }}" value="{{ $rate?->$field ?? old($field) }}" @required($field === 'rate_amount') class="{{ $input }}">
                        @error($field)<span class="text-red-400">{{ $message }}</span>@enderror
                    </label>
                @endforeach
                <label class="text-sm text-gray-300">Prix client par unité (facultatif)
                    <input type="number" step="0.01" min="0.01" name="public_rate_amount" value="{{ $rate?->public_rate_amount ?? old('public_rate_amount') }}" class="{{ $input }}">
                    <span class="text-xs text-gray-400">Laisser vide pour appliquer la commission habituelle. Sinon, la marge ASSO est la différence entre prix client et coût partenaire.</span>
                    @error('public_rate_amount')<span class="text-red-400">{{ $message }}</span>@enderror
                </label>
                <label class="text-sm text-gray-300">Fréquence (jours, facultative)
                    <input type="number" min="1" max="365" name="frequency_days" value="{{ $rate?->frequency_days ?? old('frequency_days') }}" placeholder="Vide = par occasion" class="{{ $input }}">
                    <span class="text-xs text-gray-400">Paris → Cameroun : 14 jours. Chine → Cameroun : par occasion.</span>
                    @error('frequency_days')<span class="text-red-400">{{ $message }}</span>@enderror
                </label>
                <label class="text-sm text-gray-300 md:col-span-4">Conditions / précisions
                    <textarea name="notes" maxlength="3000" class="{{ $input }}">{{ $rate?->notes ?? old('notes') }}</textarea>
                </label>
                @if($rate)<label class="text-gray-300"><input type="checkbox" name="is_active" value="1" @checked($rate->is_active)> Tarif actif</label>@endif
                <button class="{{ $btn }}" type="submit">Enregistrer le tarif</button>
            </form>
            @if($rate)
                <form method="POST" action="{{ route('admin.maritime.freight-rates.destroy', [$partner, $rate]) }}" class="mt-4"
                      data-confirm="Supprimer le tarif {{ $rate->origin_country }} → {{ $rate->destination_country }} · {{ $rate->transport_mode === 'sea' ? 'Maritime' : 'Aérien' }} · {{ $rate->cargo_category === 'sensitive' ? 'Colis sensible' : 'Colis général' }} ? Les départs et réservations existants seront conservés.">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="px-4 py-2 rounded-lg bg-red-500/10 text-red-400 hover:bg-red-500/20 border border-red-500/30">
                        <i class="fas fa-trash mr-2" aria-hidden="true"></i>Supprimer le tarif
                    </button>
                </form>
            @endif
        </details>
    @endforeach
</div>
