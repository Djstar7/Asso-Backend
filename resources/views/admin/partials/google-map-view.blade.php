@if(!empty($latitude) && !empty($longitude))
<div>
    <label class="block text-sm font-medium text-gray-700 mb-2">
        <i class="fas fa-map-marker-alt text-orange-500 mr-1"></i>
        {{ $label ?? 'Localisation' }}
    </label>

    <!-- Address display -->
    @if(!empty($address))
        <div class="mb-3 p-3 bg-orange-50 border-l-4 border-orange-500 rounded">
            <i class="fas fa-map-pin text-orange-600 mr-2"></i>
            <span class="text-gray-700">{{ $address }}</span>
        </div>
    @endif

    <!-- Map container (read-only) with Google Maps Embed -->
    <div class="border-2 border-gray-300 rounded-lg overflow-hidden shadow-md" style="min-height: 320px;">
        <iframe
            width="100%"
            height="320"
            frameborder="0"
            style="border:0"
            referrerpolicy="no-referrer-when-downgrade"
            allowfullscreen
            src="https://maps.google.com/maps?q={{ $latitude }},{{ $longitude }}&z={{ $zoom ?? '15' }}&hl=fr&output=embed">
        </iframe>
    </div>

    <div class="mt-2 flex gap-4 text-xs text-gray-500">
        <span>
            <i class="fas fa-map-marker-alt text-orange-500 mr-1"></i>
            Latitude: <strong>{{ number_format($latitude, 6) }}</strong>
        </span>
        <span>
            <i class="fas fa-map-marker-alt text-orange-500 mr-1"></i>
            Longitude: <strong>{{ number_format($longitude, 6) }}</strong>
        </span>
    </div>
</div>
@endif
