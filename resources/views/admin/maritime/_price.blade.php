{{-- Tarif du partenaire et prix client, selon le mode de transport. --}}
@if(($d->freight_rate_snapshot['transport_mode'] ?? 'sea') === 'sea')
    {{ number_format($d->partnerFreightFor(1000), 2, ',', ' ') }} {{ $d->currency }}/m³
    <span class="block text-xs text-gray-500">client {{ $d->publicFreightFor(1000) }} {{ $d->currency }}/CBM</span>
@else
    {{ number_format((float) $d->price_per_kg, 2, ',', ' ') }} {{ $d->currency }}/kg
    <span class="block text-xs text-gray-500">client {{ $d->publicPricePerKg() }} {{ $d->currency }}/kg</span>
@endif
