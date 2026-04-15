<div style="background-color: #f8f9fa; padding: 1.5rem; border-radius: 8px; margin-bottom: 1.5rem;">
    <h4 class="text-lg font-semibold text-gray-800 mb-3 flex items-center gap-2">
        <i class="fas fa-map-marker-alt text-orange-500"></i>
        <?php echo e($label ?? 'Localisation GPS (Optionnel)'); ?>

    </h4>
    <p class="text-sm text-gray-600 mb-4">
        <i class="fas fa-info-circle text-blue-500 mr-1"></i>
        Renseignez les coordonnées GPS pour afficher la localisation sur la carte.
    </p>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">
                <i class="fas fa-location-arrow text-orange-500 mr-1"></i>
                Latitude
            </label>
            <input type="number"
                   step="any"
                   name="latitude"
                   id="<?php echo e($id); ?>_latitude"
                   class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orange-500 focus:border-orange-500"
                   value="<?php echo e($latitude ?? ''); ?>"
                   placeholder="Ex: 6.3703"
                   onchange="updateMapPreview_<?php echo e(str_replace('-', '_', $id)); ?>()">
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">
                <i class="fas fa-location-arrow text-orange-500 mr-1"></i>
                Longitude
            </label>
            <input type="number"
                   step="any"
                   name="longitude"
                   id="<?php echo e($id); ?>_longitude"
                   class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orange-500 focus:border-orange-500"
                   value="<?php echo e($longitude ?? ''); ?>"
                   placeholder="Ex: 2.3912"
                   onchange="updateMapPreview_<?php echo e(str_replace('-', '_', $id)); ?>()">
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">
                <i class="fas fa-crosshairs text-orange-500 mr-1"></i>
                Action
            </label>
            <button type="button"
                    onclick="getCurrentLocation_<?php echo e(str_replace('-', '_', $id)); ?>()"
                    class="w-full px-4 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600 transition-all">
                <i class="fas fa-crosshairs mr-2"></i>
                Ma position
            </button>
        </div>
    </div>

    <div>
        <label class="block text-sm font-medium text-gray-700 mb-2">
            <i class="fas fa-map-pin text-orange-500 mr-1"></i>
            Adresse
        </label>
        <input type="text"
               name="address"
               id="<?php echo e($id); ?>_address"
               class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orange-500 focus:border-orange-500"
               value="<?php echo e($address ?? ''); ?>"
               placeholder="123 Rue Principale, Cotonou, Bénin">
        <p class="mt-1 text-xs text-gray-500">
            <i class="fas fa-lightbulb text-yellow-500 mr-1"></i>
            Vous pouvez saisir l'adresse manuellement ou elle sera détectée automatiquement.
        </p>
    </div>

    <!-- Map Preview -->
    <div id="<?php echo e($id); ?>_map_preview" style="display: <?php echo e((!empty($latitude) && !empty($longitude)) ? 'block' : 'none'); ?>; margin-top: 1.5rem;">
        <h5 class="text-md font-semibold text-gray-800 mb-3">
            <i class="fas fa-map text-orange-500 mr-2"></i>
            Aperçu de la localisation
        </h5>
        <div class="border-2 border-gray-300 rounded-lg overflow-hidden shadow-md">
            <iframe
                id="<?php echo e($id); ?>_map_iframe"
                width="100%"
                height="350"
                frameborder="0"
                style="border:0"
                referrerpolicy="no-referrer-when-downgrade"
                allowfullscreen
                <?php if(!empty($latitude) && !empty($longitude)): ?>
                src="https://www.google.com/maps/embed/v1/place?key=AIzaSyAffUHSFli6kMnjkfJOKBGO6AN828ixJPo&q=<?php echo e($latitude); ?>,<?php echo e($longitude); ?>&zoom=<?php echo e($zoom ?? '15'); ?>"
                <?php endif; ?>
            ></iframe>
        </div>
        <p class="mt-2 text-xs text-gray-500">
            <i class="fas fa-map-marker-alt text-orange-500 mr-1"></i>
            <span id="<?php echo e($id); ?>_coordinates_display">
                <?php if(!empty($latitude) && !empty($longitude)): ?>
                    Coordonnées: <?php echo e(number_format($latitude, 6)); ?>, <?php echo e(number_format($longitude, 6)); ?>

                <?php endif; ?>
            </span>
        </p>
    </div>
</div>

<?php $__env->startPush('scripts'); ?>
<script>
function updateMapPreview_<?php echo e(str_replace('-', '_', $id)); ?>() {
    const latitude = document.getElementById('<?php echo e($id); ?>_latitude').value;
    const longitude = document.getElementById('<?php echo e($id); ?>_longitude').value;
    const mapPreview = document.getElementById('<?php echo e($id); ?>_map_preview');
    const mapIframe = document.getElementById('<?php echo e($id); ?>_map_iframe');
    const coordinatesDisplay = document.getElementById('<?php echo e($id); ?>_coordinates_display');

    if (latitude && longitude) {
        const apiKey = 'AIzaSyAffUHSFli6kMnjkfJOKBGO6AN828ixJPo';
        mapIframe.src = `https://www.google.com/maps/embed/v1/place?key=${apiKey}&q=${latitude},${longitude}&zoom=<?php echo e($zoom ?? '15'); ?>`;
        coordinatesDisplay.textContent = `Coordonnées: ${parseFloat(latitude).toFixed(6)}, ${parseFloat(longitude).toFixed(6)}`;
        mapPreview.style.display = 'block';

        // Optional: Reverse geocoding to get address
        fetch(`https://nominatim.openstreetmap.org/reverse?format=json&lat=${latitude}&lon=${longitude}&zoom=18&addressdetails=1`)
            .then(response => response.json())
            .then(data => {
                if (data && data.display_name) {
                    const addressField = document.getElementById('<?php echo e($id); ?>_address');
                    if (!addressField.value) {
                        addressField.value = data.display_name;
                    }
                }
            })
            .catch(error => console.log('Geocoding error:', error));
    } else {
        mapPreview.style.display = 'none';
    }
}

function getCurrentLocation_<?php echo e(str_replace('-', '_', $id)); ?>() {
    if (navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(
            function(position) {
                document.getElementById('<?php echo e($id); ?>_latitude').value = position.coords.latitude.toFixed(6);
                document.getElementById('<?php echo e($id); ?>_longitude').value = position.coords.longitude.toFixed(6);
                updateMapPreview_<?php echo e(str_replace('-', '_', $id)); ?>();

                // Show success message
                alert('✅ Position détectée avec succès!');
            },
            function(error) {
                alert('❌ Impossible de détecter votre position. Veuillez saisir les coordonnées manuellement.');
                console.error('Geolocation error:', error);
            }
        );
    } else {
        alert('❌ La géolocalisation n\'est pas supportée par votre navigateur.');
    }
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    updateMapPreview_<?php echo e(str_replace('-', '_', $id)); ?>();
});
</script>
<?php $__env->stopPush(); ?>
<?php /**PATH /home/djstar-service/Documents/Project/Projet Collab/ASSO/Asso-Backend/resources/views/admin/partials/google-map.blade.php ENDPATH**/ ?>