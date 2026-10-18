{{-- Tarif d'un départ : au m³ (converti depuis les kilos) ou au kg. --}}
@if($d->isPricedPerCbm())
    {{ number_format((float) $d->price_per_cbm, 2, ',', ' ') }} {{ $d->currency }}/m³
    <span class="block text-xs text-gray-500">1 m³ = {{ (float) $d->kg_per_cbm }} kg · client {{ $d->publicPricePerKg() }} {{ $d->currency }}/kg</span>
@else
    {{ number_format((float) $d->price_per_kg, 2, ',', ' ') }} {{ $d->currency }}/kg
    <span class="block text-xs text-gray-500">client {{ $d->publicPricePerKg() }} {{ $d->currency }}/kg</span>
@endif
