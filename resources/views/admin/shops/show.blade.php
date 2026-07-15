@extends('admin.layouts.app')

@section('content')
<div class="p-6">
    <!-- Header -->
    <div class="mb-6">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-3">
                <a href="{{ route('admin.shops.index') }}"
                   class="text-gray-400 hover:text-primary-600 transition-colors">
                    <i class="fas fa-arrow-left"></i>
                </a>
                <div>
                    <h1 class="text-2xl font-bold text-white">Détails de la Boutique</h1>
                    <p class="text-gray-400">Informations complètes sur {{ $shop->name }}</p>
                </div>
            </div>
            <div class="flex gap-3">
                <a href="{{ route('admin.shops.edit', $shop) }}"
                   class="px-4 py-2 bg-gradient-to-r from-primary-500 to-primary-600 hover:shadow-lg text-white rounded-lg transition-all">
                    <i class="fas fa-edit mr-2"></i>
                    Modifier
                </a>
                <form action="{{ route('admin.shops.destroy', $shop) }}"
                      method="POST"
                      data-confirm="Êtes-vous sûr de vouloir supprimer cette boutique ?">
                    @csrf
                    @method('DELETE')
                    <button type="submit"
                            class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-lg transition-all">
                        <i class="fas fa-trash mr-2"></i>
                        Supprimer
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Verification Status Alert -->
    @if($shop->isPending())
        <div class="mb-6 p-6 bg-orange-900/20 border-l-4 border-orange-500 rounded-lg">
            <div class="flex items-start justify-between">
                <div class="flex-1">
                    <h3 class="text-lg font-semibold text-orange-300 mb-2">
                        <i class="fas fa-clock mr-2"></i>
                        Boutique en attente de vérification
                    </h3>
                    <p class="text-orange-200 text-sm mb-4">
                        Cette boutique a été créée par {{ $shop->user->name }} et attend votre validation.
                    </p>
                </div>
            </div>
            <div class="flex gap-3">
                <form action="{{ route('admin.shops.verify', $shop) }}" method="POST" class="inline">
                    @csrf
                    <button type="submit" class="px-6 py-3 bg-green-600 hover:bg-green-700 text-white rounded-lg transition-all flex items-center gap-2">
                        <i class="fas fa-check-circle"></i>
                        Vérifier et Activer
                    </button>
                </form>

                <button onclick="openRejectModal()" class="px-6 py-3 bg-red-600 hover:bg-red-700 text-white rounded-lg transition-all flex items-center gap-2">
                    <i class="fas fa-times-circle"></i>
                    Rejeter
                </button>
            </div>
        </div>
    @endif

    @if($shop->isRejected())
        <div class="mb-6 p-6 bg-red-900/20 border-l-4 border-red-500 rounded-lg">
            <h3 class="text-lg font-semibold text-red-300 mb-2">
                <i class="fas fa-ban mr-2"></i>
                Boutique rejetée
            </h3>
            <p class="text-red-200 text-sm mb-2">
                <strong>Raison du rejet:</strong> {{ $shop->rejection_reason ?? 'Non spécifiée' }}
            </p>
            <p class="text-red-200 text-xs">
                Rejetée le {{ $shop->rejected_at ? $shop->rejected_at->format('d/m/Y à H:i') : 'N/A' }}
            </p>
            <div class="mt-4">
                <form action="{{ route('admin.shops.verify', $shop) }}" method="POST" class="inline">
                    @csrf
                    <button type="submit" class="px-6 py-3 bg-green-600 hover:bg-green-700 text-white rounded-lg transition-all flex items-center gap-2">
                        <i class="fas fa-check-circle"></i>
                        Vérifier et Activer
                    </button>
                </form>
            </div>
        </div>
    @endif

    @if($shop->isVerified() && $shop->status === 'active')
        <div class="mb-6 p-6 bg-green-900/20 border-l-4 border-green-500 rounded-lg">
            <div class="flex items-start justify-between">
                <div>
                    <h3 class="text-lg font-semibold text-green-300 mb-2">
                        <i class="fas fa-check-circle mr-2"></i>
                        Boutique vérifiée et active
                    </h3>
                    <p class="text-green-200 text-sm">
                        Vérifiée le {{ $shop->verified_at->format('d/m/Y à H:i') }}
                    </p>
                </div>
                <form action="{{ route('admin.shops.toggleStatus', $shop) }}" method="POST">
                    @csrf
                    <button type="submit" class="px-4 py-2 bg-orange-600 hover:bg-orange-700 text-white rounded-lg transition-all text-sm">
                        <i class="fas fa-pause mr-2"></i>
                        Désactiver
                    </button>
                </form>
            </div>
        </div>
    @endif

    @if($shop->isVerified() && $shop->status === 'inactive')
        <div class="mb-6 p-6 bg-blue-900/20 border-l-4 border-blue-500 rounded-lg">
            <div class="flex items-start justify-between">
                <div>
                    <h3 class="text-lg font-semibold text-blue-300 mb-2">
                        <i class="fas fa-check-circle mr-2"></i>
                        Boutique vérifiée mais inactive
                    </h3>
                    <p class="text-blue-200 text-sm">
                        Vérifiée le {{ $shop->verified_at->format('d/m/Y à H:i') }} - Actuellement désactivée
                    </p>
                </div>
                <form action="{{ route('admin.shops.toggleStatus', $shop) }}" method="POST">
                    @csrf
                    <button type="submit" class="px-4 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg transition-all text-sm">
                        <i class="fas fa-play mr-2"></i>
                        Activer
                    </button>
                </form>
            </div>
        </div>
    @endif

    <!-- Location Change Requests -->
    @if($shop->locationRequests->where('status', 'pending')->count() > 0)
    <div class="mb-6 bg-dark-100 rounded-xl shadow-lg p-6">
        <h3 class="text-lg font-bold text-white mb-4">
            <i class="fas fa-map-marker-alt text-orange-500 mr-2 animate-pulse"></i>
            Demandes de changement de localisation ({{ $shop->locationRequests->where('status', 'pending')->count() }})
        </h3>

        @foreach($shop->locationRequests->where('status', 'pending') as $request)
        <div class="bg-orange-500/10 border border-orange-500/30 rounded-lg p-4 mb-4">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <!-- Current Location -->
                <div>
                    <h4 class="text-sm font-semibold text-white mb-2">
                        <i class="fas fa-map-marker text-gray-400 mr-1"></i>
                        Position actuelle
                    </h4>
                    <p class="text-gray-300 text-sm">
                        Latitude: <span class="font-mono text-blue-300">{{ $shop->latitude }}</span><br>
                        Longitude: <span class="font-mono text-blue-300">{{ $shop->longitude }}</span>
                    </p>
                </div>

                <!-- Requested Location -->
                <div>
                    <h4 class="text-sm font-semibold text-white mb-2">
                        <i class="fas fa-map-marker-alt text-orange-400 mr-1"></i>
                        Position demandée
                    </h4>
                    <p class="text-gray-300 text-sm">
                        Latitude: <span class="font-mono text-orange-300">{{ $request->latitude }}</span><br>
                        Longitude: <span class="font-mono text-orange-300">{{ $request->longitude }}</span>
                    </p>
                </div>
            </div>

            <!-- Request Info -->
            <div class="mt-3 pt-3 border-t border-orange-500/20">
                <p class="text-gray-400 text-xs">
                    <i class="fas fa-clock mr-1"></i>
                    Demandé le {{ $request->created_at->format('d/m/Y à H:i') }}
                </p>
            </div>

            <!-- Action Buttons -->
            <div class="flex gap-3 mt-4">
                <form action="{{ route('admin.shops.location-requests.approve', ['shop' => $shop, 'request' => $request]) }}"
                      method="POST"
                      class="flex-1"
                      data-confirm="Voulez-vous approuver ce changement de localisation? Le vendeur sera notifié.">
                    @csrf
                    <button type="submit"
                            class="w-full px-4 py-3 bg-gradient-to-r from-green-500 to-green-600 hover:shadow-lg text-white rounded-lg transition-all flex items-center justify-center gap-2 font-semibold">
                        <i class="fas fa-check-circle"></i>
                        Approuver
                    </button>
                </form>

                <form action="{{ route('admin.shops.location-requests.reject', ['shop' => $shop, 'request' => $request]) }}"
                      method="POST"
                      class="flex-1"
                      data-confirm="Voulez-vous rejeter ce changement de localisation? Le vendeur sera notifié.">
                    @csrf
                    <button type="submit"
                            class="w-full px-4 py-3 bg-gradient-to-r from-red-500 to-red-600 hover:shadow-lg text-white rounded-lg transition-all flex items-center justify-center gap-2 font-semibold">
                        <i class="fas fa-times-circle"></i>
                        Rejeter
                    </button>
                </form>
            </div>
        </div>
        @endforeach
    </div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Shop Info Card -->
        <div class="lg:col-span-1">
            <div class="bg-dark-100 rounded-xl shadow-lg p-6">
                <!-- Shop Header -->
                <div class="text-center mb-6 pb-6 border-b border-dark-200">
                    @if($shop->logo_url)
                        <div class="h-32 w-32 mx-auto mb-4">
                            <img src="{{ $shop->logo_url }}"
                                 alt="Logo {{ $shop->name }}"
                                 class="w-full h-full object-cover rounded-xl border-4 border-primary-500 shadow-lg"
                                 onerror="this.onerror=null; this.parentElement.innerHTML='<div class=\'h-full w-full rounded-xl bg-gradient-to-br from-primary-500 to-primary-600 flex items-center justify-center text-white text-3xl font-bold shadow-lg shadow-primary-500/30\'><i class=\'fas fa-store\'></i></div>';">
                        </div>
                    @else
                        <div class="h-32 w-32 mx-auto rounded-xl bg-gradient-to-br from-primary-500 to-primary-600 flex items-center justify-center text-white text-3xl font-bold mb-4 shadow-lg shadow-primary-500/30">
                            <i class="fas fa-store"></i>
                        </div>
                    @endif
                    <h2 class="text-2xl font-bold text-white mb-1">{{ $shop->name }}</h2>
                    <p class="text-gray-500 text-sm mb-3">{{ $shop->slug }}</p>

                    <!-- Status Badge -->
                    @if($shop->status == 'active')
                        <span class="px-4 py-2 inline-flex text-sm leading-5 font-semibold rounded-full bg-green-500/20 text-green-300 border border-green-500/50">
                            <i class="fas fa-check-circle mr-2"></i> Active
                        </span>
                    @else
                        <span class="px-4 py-2 inline-flex text-sm leading-5 font-semibold rounded-full bg-dark-50 text-white">
                            <i class="fas fa-times-circle mr-2"></i> Inactive
                        </span>
                    @endif
                </div>

                <!-- Quick Stats -->
                <div class="space-y-4">
                    <div class="flex items-center justify-between">
                        <span class="text-gray-400">
                            <i class="fas fa-box text-primary-500 mr-2"></i>
                            Produits
                        </span>
                        <span class="font-semibold text-white">{{ $shop->products->count() }}</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-gray-400">
                            <i class="fas fa-calendar-plus text-primary-500 mr-2"></i>
                            Créée le
                        </span>
                        <span class="font-semibold text-white">{{ $shop->created_at->format('d/m/Y') }}</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-gray-400">
                            <i class="fas fa-calendar-check text-primary-500 mr-2"></i>
                            Mise à jour
                        </span>
                        <span class="font-semibold text-white">{{ $shop->updated_at->format('d/m/Y') }}</span>
                    </div>
                </div>

                <!-- Categories Section -->
                @if($shop->categories && count($shop->categories) > 0)
                    <div class="mt-6 pt-6 border-t border-dark-200">
                        <h4 class="text-sm font-semibold text-gray-400 mb-3">
                            <i class="fas fa-tag text-primary-500 mr-2"></i>
                            Catégories
                        </h4>
                        <div class="flex flex-wrap gap-2">
                            @foreach($shop->categories as $category)
                                <span class="px-3 py-1 text-xs rounded-full bg-primary-500/20 text-primary-300 border border-primary-500/50">
                                    {{ $category }}
                                </span>
                            @endforeach
                        </div>
                    </div>
                @endif

                <!-- External Link -->
                @if($shop->shop_link)
                    <div class="mt-6 pt-6 border-t border-dark-200">
                        <a href="{{ $shop->shop_link }}"
                           target="_blank"
                           class="block w-full text-center px-4 py-3 bg-gradient-to-r from-primary-500 to-primary-600 text-white rounded-lg hover:shadow-lg transition-all shadow-lg hover:shadow-xl">
                            <i class="fas fa-external-link-alt mr-2"></i>
                            Visiter la boutique
                        </a>
                    </div>
                @endif
            </div>
        </div>

        <!-- Details & Products -->
        <div class="lg:col-span-2 space-y-6">
            <!-- Shop Information -->
            <div class="bg-dark-100 rounded-xl shadow-lg p-6">
                <h3 class="text-xl font-bold text-white mb-4 flex items-center">
                    <i class="fas fa-info-circle text-primary-500 mr-2"></i>
                    Informations de la Boutique
                </h3>

                <div class="space-y-4">
                    <!-- Owner -->
                    <div class="flex items-start">
                        <div class="flex-shrink-0 mr-4">
                            <div class="h-12 w-12 rounded-full bg-gradient-to-br from-blue-400 to-blue-600 flex items-center justify-center text-white font-bold">
                                {{ strtoupper(substr($shop->user->first_name, 0, 1)) }}{{ strtoupper(substr($shop->user->last_name, 0, 1)) }}
                            </div>
                        </div>
                        <div class="flex-1">
                            <label class="text-sm text-gray-500">Propriétaire</label>
                            <p class="text-white font-medium">{{ $shop->user->name }}</p>
                            <p class="text-sm text-gray-500">
                                <i class="fas fa-envelope mr-1"></i>{{ $shop->user->email }}
                                @if($shop->user->phone)
                                    | <i class="fas fa-phone mr-1"></i>{{ $shop->user->phone }}
                                @endif
                            </p>
                            <a href="{{ route('admin.users.show', $shop->user) }}"
                               class="text-primary-500 hover:text-primary-600 text-sm mt-1 inline-block">
                                <i class="fas fa-arrow-right mr-1"></i>
                                Voir le profil
                            </a>
                        </div>
                    </div>

                    <!-- Description -->
                    <div>
                        <label class="text-sm text-gray-500">Description</label>
                        <p class="text-white font-medium">
                            {{ $shop->description ?? 'Aucune description fournie' }}
                        </p>
                    </div>

                    <!-- Shop Link -->
                    @if($shop->shop_link)
                        <div>
                            <label class="text-sm text-gray-500">Lien externe</label>
                            <p class="text-white font-medium break-all">
                                <i class="fas fa-link text-primary-500 mr-1"></i>
                                <a href="{{ $shop->shop_link }}"
                                   target="_blank"
                                   class="text-blue-400 hover:text-blue-300 underline">
                                    {{ $shop->shop_link }}
                                </a>
                            </p>
                        </div>
                    @endif
                </div>

                <!-- Map Section -->
                @if($shop->latitude && $shop->longitude)
                    <div class="mt-6 pt-6 border-t border-dark-200">
                        @include('admin.partials.google-map-view', [
                            'id' => 'shop-location-map',
                            'label' => 'Localisation de la boutique',
                            'latitude' => $shop->latitude,
                            'longitude' => $shop->longitude,
                            'address' => $shop->address,
                            'zoom' => 16
                        ])
                    </div>
                @endif
            </div>

            <!-- Products Section -->
            <div class="bg-dark-100 rounded-xl shadow-lg p-6">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-xl font-bold text-white flex items-center">
                        <i class="fas fa-box text-primary-500 mr-2"></i>
                        Produits ({{ $shop->products->count() }})
                    </h3>
                    <a href="{{ route('admin.products.index') }}?shop_id={{ $shop->id }}"
                       class="px-4 py-2 bg-gradient-to-r from-primary-500 to-primary-600 hover:shadow-lg text-white rounded-lg transition-all text-sm">
                        <i class="fas fa-plus mr-2"></i>
                        Ajouter un produit
                    </a>
                </div>

                @if($shop->products->count() > 0)
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-dark-200">
                            <thead class="bg-dark-50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-semibold text-white uppercase">Produit</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold text-white uppercase">Catégorie</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold text-white uppercase">Prix</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold text-white uppercase">Stock</th>
                                    <th class="px-4 py-3 text-right text-xs font-semibold text-white uppercase">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-dark-100 divide-y divide-dark-200">
                                @foreach($shop->products as $product)
                                    <tr class="hover:bg-dark-50 transition-colors">
                                        <td class="px-4 py-3">
                                            <div class="text-sm font-medium text-white">{{ $product->name }}</div>
                                        </td>
                                        <td class="px-4 py-3">
                                            <span class="px-2 py-1 text-xs rounded-full bg-blue-500/20 text-blue-300 border border-blue-500/50">
                                                {{ $product->category->name ?? 'N/A' }}
                                            </span>
                                        </td>
                                        <td class="px-4 py-3 text-sm text-white">
                                            {{ number_format($product->price, 0, ',', ' ') }} FCFA
                                        </td>
                                        <td class="px-4 py-3 text-sm text-white">
                                            {{ $product->stock }}
                                        </td>
                                        <td class="px-4 py-3 text-right text-sm">
                                            <a href="{{ route('admin.products.show', $product) }}"
                                               class="text-primary-500 hover:text-primary-400 mr-2">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <a href="{{ route('admin.products.edit', $product) }}"
                                               class="text-primary-400 hover:text-primary-300">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <div class="text-center py-12 text-gray-500">
                        <i class="fas fa-box-open text-4xl text-gray-300 mb-3"></i>
                        <p class="text-lg">Aucun produit</p>
                        <p class="text-sm">Cette boutique ne contient pas encore de produits</p>
                    </div>
                @endif
            </div>
        </div>
    </div>

    <!-- Reject Modal -->
    <div id="rejectModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
        <div class="bg-dark-100 rounded-xl shadow-2xl max-w-md w-full mx-4">
            <div class="p-6">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-xl font-bold text-white">Rejeter la boutique</h3>
                    <button onclick="closeRejectModal()" class="text-gray-400 hover:text-white">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>

                <form action="{{ route('admin.shops.reject', $shop) }}" method="POST">
                    @csrf
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-white mb-2">
                            Raison du rejet <span class="text-red-500">*</span>
                        </label>
                        <textarea name="reason"
                                  required
                                  rows="4"
                                  class="w-full px-4 py-2 bg-dark-50 border border-dark-300 text-white placeholder-gray-400 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500"
                                  placeholder="Ex: Logo inapproprié, informations incomplètes..."></textarea>
                    </div>

                    <div class="flex gap-3 justify-end">
                        <button type="button"
                                onclick="closeRejectModal()"
                                class="px-4 py-2 bg-gray-600 hover:bg-gray-700 text-white rounded-lg transition-all">
                            Annuler
                        </button>
                        <button type="submit"
                                class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-lg transition-all">
                            <i class="fas fa-times-circle mr-2"></i>
                            Rejeter
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function openRejectModal() {
    document.getElementById('rejectModal').classList.remove('hidden');
    document.getElementById('rejectModal').classList.add('flex');
}

function closeRejectModal() {
    document.getElementById('rejectModal').classList.add('hidden');
    document.getElementById('rejectModal').classList.remove('flex');
}

// Close modal on outside click
document.getElementById('rejectModal')?.addEventListener('click', function(e) {
    if (e.target === this) {
        closeRejectModal();
    }
});
</script>

@endsection
