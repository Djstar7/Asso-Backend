<input type="hidden" name="reference_fields" value="1">
<label class="text-sm text-gray-300">Pays
    <select name="country" class="{{ $input }}"><option value="">Non renseigné</option>
        @foreach(\App\Support\CountryCode::NAMES as $code => $label)
            <option value="{{ $code }}" @selected($reference?->country === $code)>{{ $label }}</option>
        @endforeach
    </select>
</label>
@foreach(['latitude' => 'Latitude', 'longitude' => 'Longitude', 'source_url' => 'Lien de la source'] as $field => $label)
    <label class="text-sm text-gray-300">{{ $label }}<input name="{{ $field }}" value="{{ $reference?->$field }}" class="{{ $input }}"></label>
@endforeach
<label class="text-sm text-gray-300"><input type="checkbox" name="is_international_reference" value="1" @checked($reference?->is_international_reference)> Repère international (la couverture reste celle du pays)</label>
<label class="text-sm text-gray-300"><input type="checkbox" name="is_provisional" value="1" @checked($reference?->is_provisional)> Repère provisoire, agence à confirmer</label>

<label class="text-sm text-gray-300">Précision des coordonnées
    <select name="coordinate_precision" class="{{ $input }}">
        <option value="">Non renseignée</option>
        @foreach(['precise' => 'Position précise confirmée', 'city_center' => 'Centre-ville provisoire', 'street' => 'Rue (position approximative)', 'published' => 'Coordonnées publiées par le transporteur'] as $precision => $label)
            <option value="{{ $precision }}" @selected($reference?->coordinate_precision === $precision)>{{ $label }}</option>
        @endforeach
    </select>
</label>
