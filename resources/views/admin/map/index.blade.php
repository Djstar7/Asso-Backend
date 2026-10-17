@extends('admin.layouts.app')

@push('styles')
<!-- Charger Leaflet CSS -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
      integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY="
      crossorigin=""/>
@endpush

@section('content')
<div class="p-6 space-y-6">
    <!-- En-tête -->
    <div class="flex justify-between items-center">
        <div>
            <h1 class="text-3xl font-bold text-gray-100">Carte des Utilisateurs</h1>
            <p class="text-gray-400 mt-1">Visualisation géographique de tous les utilisateurs</p>
        </div>
    </div>

    <!-- Statistiques rapides -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
        <div class="bg-gradient-to-br from-blue-600 to-blue-700 rounded-lg p-6 text-white">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-blue-100 text-sm font-medium">Total Utilisateurs</p>
                    <p class="text-3xl font-bold mt-2">{{ number_format($stats['total']) }}</p>
                </div>
                <div class="bg-blue-500 bg-opacity-30 p-3 rounded-lg">
                    <i class="fas fa-users text-2xl"></i>
                </div>
            </div>
        </div>

        <div class="bg-gradient-to-br from-red-600 to-red-700 rounded-lg p-6 text-white">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-red-100 text-sm font-medium">Vendeurs</p>
                    <p class="text-3xl font-bold mt-2">{{ number_format($stats['vendeur']) }}</p>
                </div>
                <div class="bg-red-500 bg-opacity-30 p-3 rounded-lg">
                    <i class="fas fa-store text-2xl"></i>
                </div>
            </div>
        </div>

        <div class="bg-gradient-to-br from-purple-600 to-purple-700 rounded-lg p-6 text-white">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-purple-100 text-sm font-medium">Acheteurs</p>
                    <p class="text-3xl font-bold mt-2">{{ number_format($stats['acheteur']) }}</p>
                </div>
                <div class="bg-purple-500 bg-opacity-30 p-3 rounded-lg">
                    <i class="fas fa-shopping-cart text-2xl"></i>
                </div>
            </div>
        </div>

        <div class="bg-gradient-to-br from-green-600 to-green-700 rounded-lg p-6 text-white">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-green-100 text-sm font-medium">Livreurs</p>
                    <p class="text-3xl font-bold mt-2">{{ number_format($stats['livreur']) }}</p>
                </div>
                <div class="bg-green-500 bg-opacity-30 p-3 rounded-lg">
                    <i class="fas fa-truck text-2xl"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Filtres et contrôles -->
    <div class="bg-dark-100 border border-dark-200 rounded-lg p-4">
        <form id="mapFiltersForm" class="flex flex-wrap gap-4 items-end">
            <div class="flex-1 min-w-[200px]">
                <label for="search" class="block text-sm font-medium text-gray-300 mb-2">Rechercher</label>
                <input type="text" id="search" name="search"
                    placeholder="Nom, email, téléphone..."
                    class="w-full px-4 py-2 bg-dark-50 border border-dark-200 rounded-lg text-gray-100 focus:ring-2 focus:ring-blue-500">
            </div>
            <div class="w-48">
                <label for="role" class="block text-sm font-medium text-gray-300 mb-2">Rôle</label>
                <select id="role" name="role"
                    class="w-full px-4 py-2 bg-dark-50 border border-dark-200 rounded-lg text-gray-100 focus:ring-2 focus:ring-blue-500">
                    <option value="">Tous</option>
                    <option value="vendeur">Vendeurs</option>
                    <option value="acheteur">Acheteurs</option>
                    <option value="livreur">Livreurs</option>
                </select>
            </div>
            <button type="submit" class="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition-colors">
                <i class="fas fa-filter mr-2"></i>
                Filtrer
            </button>
            <button type="button" onclick="resetFilters()" class="px-6 py-2 bg-dark-50 border border-dark-200 hover:bg-dark-100 text-gray-100 rounded-lg transition-colors">
                Réinitialiser
            </button>
        </form>

        <!-- Légende -->
        <div class="mt-4 pt-4 border-t border-dark-200">
            <h4 class="text-sm font-medium text-gray-300 mb-2">Légende des marqueurs :</h4>
            <div class="flex flex-wrap gap-4">
                <div class="flex items-center gap-2">
                    <div class="w-6 h-6 rounded-full bg-red-500 border-2 border-white shadow-md"></div>
                    <span class="text-gray-300 text-sm">Vendeurs</span>
                </div>
                <div class="flex items-center gap-2">
                    <div class="w-6 h-6 rounded-full bg-purple-500 border-2 border-white shadow-md"></div>
                    <span class="text-gray-300 text-sm">Acheteurs</span>
                </div>
                <div class="flex items-center gap-2">
                    <div class="w-6 h-6 rounded-full bg-green-500 border-2 border-white shadow-md"></div>
                    <span class="text-gray-300 text-sm">Livreurs</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Carte Google Maps -->
    <div class="bg-dark-100 border border-dark-200 rounded-lg overflow-hidden">
        <div id="map" style="height: 700px; width: 100%;"></div>
    </div>

    <!-- Liste des utilisateurs affichés -->
    <div class="bg-dark-100 border border-dark-200 rounded-lg p-6">
        <h3 class="text-xl font-semibold text-gray-100 mb-4">
            Utilisateurs sur la carte (<span id="userCount">{{ $users->count() }}</span>)
        </h3>
        <div id="usersList" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            @foreach($users as $user)
                <div class="bg-dark-50 border border-dark-200 rounded-lg p-4 hover:bg-dark-100 transition-colors cursor-pointer"
                    onclick="focusOnUser({{ $user->id }}, {{ $user->latitude }}, {{ $user->longitude }})">
                    <div class="flex items-center gap-3">
                        @if($user->avatar)
                            <img src="{{ media_url($user->avatar) }}" alt="{{ $user->name }}"
                                class="w-12 h-12 rounded-full object-cover">
                        @else
                            <div class="w-12 h-12 rounded-full flex items-center justify-center
                                {{ $user->role === 'vendeur' ? 'bg-red-600' : ($user->role === 'livreur' ? 'bg-green-600' : 'bg-purple-600') }}">
                                <span class="text-white font-bold">{{ substr($user->name, 0, 1) }}</span>
                            </div>
                        @endif
                        <div class="flex-1">
                            <h4 class="text-gray-100 font-medium">{{ $user->name }}</h4>
                            <p class="text-gray-400 text-sm">{{ $user->email }}</p>
                            <span class="inline-block mt-1 px-2 py-1 rounded text-xs font-medium
                                {{ $user->role === 'vendeur' ? 'bg-red-600 text-white' : ($user->role === 'livreur' ? 'bg-green-600 text-white' : 'bg-purple-600 text-white') }}">
                                {{ ucfirst($user->role) }}
                            </span>
                        </div>
                        <i class="fas fa-location-arrow text-blue-400"></i>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</div>

@push('scripts')
<!-- Charger Leaflet JS -->
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
        integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo="
        crossorigin=""></script>

<script>
let map;
let markers = [];
let usersData = @json($usersData);

// Fonction d'initialisation de la carte
function initializeMap() {
    const centerLat = {{ $centerLat }};
    const centerLng = {{ $centerLng }};

    console.log('🗺️ Initialisation de la carte...');
    console.log('📍 Centre:', centerLat, centerLng);
    console.log('👥 Nombre d\'utilisateurs:', usersData.length);

    // Vérifier que Leaflet est chargé
    if (typeof L === 'undefined') {
        console.error('❌ Leaflet n\'est pas chargé !');
        return;
    }

    console.log('✅ Leaflet chargé');

    // Créer la carte avec Leaflet
    try {
        map = L.map('map').setView([centerLat, centerLng], 7);
        console.log('✅ Carte créée');
    } catch (error) {
        console.error('❌ Erreur lors de la création de la carte:', error);
        return;
    }

    // Ajouter les tuiles OpenStreetMap par défaut
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
        maxZoom: 19
    }).addTo(map);

    console.log('✅ Tuiles ajoutées');

    // Ajouter tous les marqueurs
    console.log('🎯 Ajout des marqueurs...');
    displayMarkers(usersData);
}

// Attendre que tout soit chargé
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeMap);
} else {
    // DOM déjà chargé
    setTimeout(initializeMap, 100);
}

// Afficher les marqueurs
function displayMarkers(users) {
    console.log('displayMarkers appelé avec', users.length, 'utilisateurs');

    // Supprimer les anciens marqueurs
    markers.forEach(marker => map.removeLayer(marker));
    markers = [];

    if (users.length === 0) {
        console.log('Aucun utilisateur à afficher');
        return;
    }

    const bounds = L.latLngBounds();

    // Ajouter les nouveaux marqueurs
    users.forEach((user, index) => {
        console.log('Ajout marqueur pour:', user.name, 'à', user.latitude, user.longitude);

        const markerColor = getMarkerColor(user.role);

        // Créer une icône personnalisée
        const customIcon = L.divIcon({
            className: 'custom-marker',
            html: `<div style="
                width: 30px;
                height: 30px;
                background-color: ${markerColor};
                border: 3px solid white;
                border-radius: 50%;
                box-shadow: 0 2px 8px rgba(0,0,0,0.3);
                display: flex;
                align-items: center;
                justify-content: center;
                font-weight: bold;
                color: white;
                font-size: 12px;
            ">${user.name.charAt(0)}</div>`,
            iconSize: [30, 30],
            iconAnchor: [15, 15],
            popupAnchor: [0, -15]
        });

        const marker = L.marker([user.latitude, user.longitude], { icon: customIcon })
            .addTo(map);

        const popupContent = `
            <div style="padding: 10px; min-width: 250px; background: #1f2937; color: white; border-radius: 8px;">
                <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 10px;">
                    ${user.avatar
                        ? `<img src="${user.avatar}" alt="${user.name}" style="width: 50px; height: 50px; border-radius: 50%; object-fit: cover;">`
                        : `<div style="width: 50px; height: 50px; border-radius: 50%; background: ${markerColor}; display: flex; align-items: center; justify-content: center; color: white; font-weight: bold; font-size: 20px;">${user.name.charAt(0)}</div>`
                    }
                    <div>
                        <h3 style="margin: 0; font-size: 16px; font-weight: bold; color: white;">${user.name}</h3>
                        <span style="display: inline-block; margin-top: 4px; padding: 2px 8px; background: ${markerColor}; color: white; border-radius: 4px; font-size: 11px; font-weight: 600;">${user.role.toUpperCase()}</span>
                    </div>
                </div>
                <div style="border-top: 1px solid #374151; padding-top: 10px; color: #d1d5db;">
                    <p style="margin: 5px 0; font-size: 13px;"><i class="fas fa-envelope" style="width: 16px; color: #60a5fa;"></i> ${user.email}</p>
                    ${user.phone ? `<p style="margin: 5px 0; font-size: 13px;"><i class="fas fa-phone" style="width: 16px; color: #60a5fa;"></i> ${user.phone}</p>` : ''}
                    ${user.address ? `<p style="margin: 5px 0; font-size: 13px;"><i class="fas fa-map-marker-alt" style="width: 16px; color: #60a5fa;"></i> ${user.address}</p>` : ''}
                </div>
                <div style="margin-top: 10px;">
                    <a href="/admin/users/${user.id}" style="display: inline-block; padding: 8px 16px; background: #3b82f6; color: white; text-decoration: none; border-radius: 6px; font-size: 13px; font-weight: 500;">
                        <i class="fas fa-eye"></i> Voir le profil
                    </a>
                </div>
            </div>
        `;

        marker.bindPopup(popupContent, {
            maxWidth: 300,
            className: 'custom-popup'
        });

        markers.push(marker);
        bounds.extend([user.latitude, user.longitude]);
    });

    console.log('Total marqueurs ajoutés:', markers.length);

    // Ajuster la vue pour inclure tous les marqueurs
    if (markers.length > 0) {
        console.log('Ajustement des bounds de la carte...');
        map.fitBounds(bounds, { padding: [50, 50] });
        console.log('✅ Carte ajustée');
    } else {
        console.log('❌ Aucun marqueur à afficher');
    }
}

// Obtenir la couleur du marqueur selon le rôle
function getMarkerColor(role) {
    switch(role) {
        case 'vendeur': return '#dc2626'; // Rouge
        case 'acheteur': return '#9333ea'; // Violet
        case 'livreur': return '#16a34a'; // Vert
        default: return '#3b82f6'; // Bleu
    }
}

// Centrer sur un utilisateur
function focusOnUser(userId, lat, lng) {
    map.setView([lat, lng], 16);

    // Trouver et ouvrir le popup du marqueur correspondant
    const markerIndex = usersData.findIndex(u => u.id === userId);
    if (markerIndex !== -1 && markers[markerIndex]) {
        markers[markerIndex].openPopup();
    }
}

// Filtrer les utilisateurs
document.getElementById('mapFiltersForm').addEventListener('submit', function(e) {
    e.preventDefault();

    const search = document.getElementById('search').value;
    const role = document.getElementById('role').value;

    fetch(`{{ route('admin.map.data') }}?search=${search}&role=${role}`)
        .then(response => response.json())
        .then(data => {
            usersData = data;
            displayMarkers(data);
            updateUsersList(data);
            document.getElementById('userCount').textContent = data.length;
        })
        .catch(error => console.error('Error:', error));
});

// Réinitialiser les filtres
function resetFilters() {
    document.getElementById('search').value = '';
    document.getElementById('role').value = '';
    document.getElementById('mapFiltersForm').dispatchEvent(new Event('submit'));
}

// Mettre à jour la liste des utilisateurs
function updateUsersList(users) {
    const usersList = document.getElementById('usersList');

    if (users.length === 0) {
        usersList.innerHTML = '<div class="col-span-3 text-center py-8 text-gray-400"><i class="fas fa-inbox text-4xl mb-2"></i><p>Aucun utilisateur trouvé</p></div>';
        return;
    }

    usersList.innerHTML = users.map(user => {
        const roleColor = user.role === 'vendeur' ? 'bg-red-600' : (user.role === 'livreur' ? 'bg-green-600' : 'bg-purple-600');
        const avatarHtml = user.avatar
            ? `<img src="${user.avatar}" alt="${user.name}" class="w-12 h-12 rounded-full object-cover">`
            : `<div class="w-12 h-12 rounded-full flex items-center justify-center ${roleColor}"><span class="text-white font-bold">${user.name.charAt(0)}</span></div>`;

        return `
            <div class="bg-dark-50 border border-dark-200 rounded-lg p-4 hover:bg-dark-100 transition-colors cursor-pointer"
                onclick="focusOnUser(${user.id}, ${user.latitude}, ${user.longitude})">
                <div class="flex items-center gap-3">
                    ${avatarHtml}
                    <div class="flex-1">
                        <h4 class="text-gray-100 font-medium">${user.name}</h4>
                        <p class="text-gray-400 text-sm">${user.email}</p>
                        <span class="inline-block mt-1 px-2 py-1 rounded text-xs font-medium ${roleColor} text-white">
                            ${user.role.charAt(0).toUpperCase() + user.role.slice(1)}
                        </span>
                    </div>
                    <i class="fas fa-location-arrow text-blue-400"></i>
                </div>
            </div>
        `;
    }).join('');
}
</script>

<style>
/* Style personnalisé pour les popups Leaflet */
.leaflet-popup-content-wrapper {
    background: transparent !important;
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3) !important;
    padding: 0 !important;
    border-radius: 8px !important;
}

.leaflet-popup-content {
    margin: 0 !important;
}

.leaflet-popup-tip {
    background: #1f2937 !important;
}

.custom-marker {
    background: transparent !important;
    border: none !important;
}

/* Style pour les popups */
.leaflet-popup-close-button {
    color: #374151 !important;
    font-size: 20px !important;
    padding: 4px 8px !important;
}

.leaflet-popup-close-button:hover {
    color: #111827 !important;
}
</style>
@endpush
@endsection
