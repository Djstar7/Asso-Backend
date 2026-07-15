@extends('admin.layouts.app')

@section('content')
<div class="p-6">
    <!-- Header -->
    <div class="mb-6">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-3">
                <a href="{{ route('admin.deliverers.index') }}" class="text-gray-400 hover:text-primary-500 transition-colors">
                    <i class="fas fa-arrow-left"></i>
                </a>
                <div>
                    <h1 class="text-2xl font-bold text-white">{{ $deliverer->name }}</h1>
                    <p class="text-gray-400">Entreprise de livraison</p>
                </div>
            </div>
            <div class="flex gap-3">
                <a href="{{ route('admin.deliverers.syncs.company', $deliverer) }}"
                   class="px-4 py-2 bg-gradient-to-r from-blue-500 to-blue-600 text-white rounded-lg hover:shadow-lg transition-all">
                    <i class="fas fa-users mr-2"></i> Synchronisations
                    @if($deliverer->codeSyncs->count() > 0)
                        <span class="ml-2 px-2 py-0.5 bg-white/20 rounded-full text-xs">
                            {{ $deliverer->codeSyncs->count() }}
                        </span>
                    @endif
                </a>
                <a href="{{ route('admin.deliverers.edit', $deliverer) }}"
                   class="px-4 py-2 bg-gradient-to-r from-primary-500 to-primary-600 text-white rounded-lg hover:shadow-lg transition-all">
                    <i class="fas fa-edit mr-2"></i> Modifier
                </a>
                <form action="{{ route('admin.deliverers.destroy', $deliverer) }}" method="POST"
                      data-confirm="Supprimer cette entreprise de livraison ? Toutes les zones et tarifs associés seront également supprimés.">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-all">
                        <i class="fas fa-trash mr-2"></i> Supprimer
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Left: Company Card -->
        <div class="lg:col-span-1 space-y-6">
            <!-- Company Card -->
            <div class="bg-dark-100 rounded-xl shadow-lg border border-dark-200 p-6">
                <div class="flex flex-col items-center mb-6">
                    @if($deliverer->logo)
                        <img src="{{ Storage::url($deliverer->logo) }}" alt="{{ $deliverer->name }}"
                             class="w-28 h-28 rounded-xl object-cover border-2 border-dark-300 mb-4 shadow-lg">
                    @else
                        <div class="w-28 h-28 rounded-xl bg-gradient-to-br from-primary-500 to-primary-600 flex items-center justify-center text-white text-4xl font-bold mb-4 shadow-lg">
                            {{ strtoupper(substr($deliverer->name, 0, 2)) }}
                        </div>
                    @endif
                    <h2 class="text-xl font-bold text-white text-center">{{ $deliverer->name }}</h2>

                    <div class="mt-3 flex flex-col gap-2 w-full">
                        @if($deliverer->user_id)
                            <span class="px-4 py-2 inline-flex items-center justify-center text-sm font-semibold rounded-lg bg-green-900/30 text-green-400 border border-green-500/50">
                                <i class="fas fa-check-circle mr-2"></i> Synchronisé
                            </span>
                        @else
                            <span class="px-4 py-2 inline-flex items-center justify-center text-sm font-semibold rounded-lg bg-yellow-900/30 text-yellow-400 border border-yellow-500/50">
                                <i class="fas fa-clock mr-2"></i> En attente
                            </span>
                        @endif

                        <span class="px-4 py-2 inline-flex items-center justify-center text-sm font-semibold rounded-lg {{ $deliverer->is_active ? 'bg-blue-900/30 text-blue-400 border-blue-500/50' : 'bg-gray-900/30 text-gray-400 border-gray-500/50' }} border">
                            <i class="fas fa-{{ $deliverer->is_active ? 'toggle-on' : 'toggle-off' }} mr-2"></i>
                            {{ $deliverer->is_active ? 'Active' : 'Inactive' }}
                        </span>
                    </div>
                </div>

                <div class="border-t border-dark-200 pt-4 space-y-3">
                    <div class="flex items-center justify-between">
                        <span class="text-gray-400"><i class="fas fa-map-marked-alt text-primary-500 mr-2"></i> Zones</span>
                        <span class="font-medium text-white">{{ $deliverer->deliveryZones->count() }}</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-gray-400"><i class="fas fa-calendar text-primary-500 mr-2"></i> Créé</span>
                        <span class="font-medium text-white">{{ $deliverer->created_at->format('d/m/Y') }}</span>
                    </div>
                </div>
            </div>

            <!-- Sync Status -->
            @if($deliverer->user_id)
                <div class="bg-dark-100 rounded-xl shadow-lg border border-green-500/30 p-6">
                    <h3 class="text-lg font-semibold text-white mb-4 flex items-center">
                        <i class="fas fa-user-check text-green-500 mr-2"></i> Livreur Synchronisé
                    </h3>
                    <div class="space-y-3">
                        <div>
                            <label class="text-sm text-gray-500">Nom</label>
                            <p class="text-white font-medium">{{ $deliverer->user->name ?? 'N/A' }}</p>
                        </div>
                        <div>
                            <label class="text-sm text-gray-500">Email</label>
                            <p class="text-white font-medium">{{ $deliverer->user->email ?? 'N/A' }}</p>
                        </div>
                        <div>
                            <label class="text-sm text-gray-500">Téléphone</label>
                            <p class="text-white font-medium">{{ $deliverer->user->phone ?? 'N/A' }}</p>
                        </div>
                    </div>
                </div>
            @else
                <!-- Sync Code -->
                @if($deliverer->syncCodes->first())
                    @php $latestCode = $deliverer->syncCodes->first(); @endphp
                    <div class="bg-dark-100 rounded-xl shadow-lg border border-yellow-500/30 p-6">
                        <h3 class="text-lg font-semibold text-white mb-4 flex items-center">
                            <i class="fas fa-key text-yellow-500 mr-2"></i> Code de Synchronisation
                        </h3>
                        <div class="space-y-3">
                            <div class="p-4 bg-dark-50 rounded-lg border border-dark-300 text-center">
                                <code class="text-2xl font-mono text-primary-400 font-bold">{{ $latestCode->sync_code }}</code>
                            </div>
                            <div class="space-y-2 text-sm">
                                <div class="flex justify-between">
                                    <span class="text-gray-500">Statut:</span>
                                    @if($latestCode->is_used)
                                        <span class="text-green-400">Utilisé</span>
                                    @elseif($latestCode->isExpired())
                                        <span class="text-red-400">Expiré</span>
                                    @else
                                        <span class="text-green-400">Valide</span>
                                    @endif
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-gray-500">Envoyé via:</span>
                                    <span class="text-white">
                                        @if($latestCode->sent_via == 'email')
                                            <i class="fas fa-envelope"></i> Email
                                        @endif
                                    </span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-gray-500">Expire le:</span>
                                    <span class="text-white">{{ $latestCode->expires_at->format('d/m/Y') }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                @endif
            @endif
        </div>

        <!-- Right: Details -->
        <div class="lg:col-span-2 space-y-6">
            <!-- Contact Info -->
            <div class="bg-dark-100 rounded-xl shadow-lg border border-dark-200 p-6">
                <h3 class="text-xl font-bold text-white mb-4 flex items-center">
                    <i class="fas fa-address-card text-primary-500 mr-2"></i>
                    Informations de Contact
                </h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="text-sm text-gray-500">Email</label>
                        <p class="text-white font-medium">
                            <i class="fas fa-envelope text-primary-500 mr-1"></i>
                            {{ $deliverer->email }}
                        </p>
                    </div>
                    <div>
                        <label class="text-sm text-gray-500">Téléphone</label>
                        <p class="text-white font-medium">
                            <i class="fas fa-phone text-primary-500 mr-1"></i>
                            {{ $deliverer->phone }}
                        </p>
                    </div>
                </div>

                @if($deliverer->description)
                    <div class="mt-4 pt-4 border-t border-dark-200">
                        <label class="text-sm text-gray-500">Description</label>
                        <p class="text-white mt-1">{{ $deliverer->description }}</p>
                    </div>
                @endif
            </div>

            <!-- Delivery Zones -->
            <div class="bg-dark-100 rounded-xl shadow-lg border border-dark-200 p-6">
                <h3 class="text-xl font-bold text-white mb-4 flex items-center">
                    <i class="fas fa-map-marked-alt text-primary-500 mr-2"></i>
                    Zones de Livraison ({{ $deliverer->deliveryZones->count() }})
                </h3>

                @forelse($deliverer->deliveryZones as $zone)
                    <div class="mb-4 last:mb-0 p-4 bg-dark-50 rounded-lg border border-dark-300">
                        <div class="flex items-start justify-between mb-3">
                            <div>
                                <h4 class="text-white font-semibold text-lg flex items-center gap-2">
                                    <i class="fas fa-map-pin text-primary-500"></i>
                                    {{ $zone->name }}
                                </h4>
                                <p class="text-gray-400 text-sm mt-1">
                                    <i class="fas fa-crosshairs mr-1"></i>
                                    Centre: {{ number_format($zone->center_latitude, 6) }}, {{ number_format($zone->center_longitude, 6) }}
                                </p>
                            </div>
                            <span class="px-3 py-1 rounded-full text-xs font-semibold {{ $zone->is_active ? 'bg-green-900/30 text-green-400 border border-green-500/50' : 'bg-gray-900/30 text-gray-400 border border-gray-500/50' }}">
                                {{ $zone->is_active ? 'Active' : 'Inactive' }}
                            </span>
                        </div>

                        @if($zone->pricelist)
                            <div class="mt-3 p-3 bg-dark-100 rounded border border-dark-200">
                                <p class="text-sm text-gray-400 mb-2">
                                    <i class="fas fa-dollar-sign text-primary-500 mr-1"></i>
                                    Type de tarification:
                                    <span class="text-white font-medium">
                                        @if($zone->pricelist->pricing_type == 'fixed')
                                            Prix Fixe
                                        @elseif($zone->pricelist->pricing_type == 'weight_category')
                                            Par Catégorie de Poids
                                        @elseif($zone->pricelist->pricing_type == 'volumetric_weight')
                                            Poids Volumétrique
                                        @endif
                                    </span>
                                </p>

                                <div class="text-xs">
                                    <p class="text-gray-500 mb-1">Données de tarification:</p>
                                    <div class="bg-dark-50 p-2 rounded font-mono text-gray-300 overflow-x-auto">
                                        {{ json_encode($zone->pricelist->pricing_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}
                                    </div>
                                </div>
                            </div>
                        @else
                            <p class="text-gray-500 text-sm">Aucune tarification configurée</p>
                        @endif
                    </div>
                @empty
                    <div class="text-center py-8">
                        <i class="fas fa-map text-gray-600 text-4xl mb-3"></i>
                        <p class="text-gray-500">Aucune zone de livraison configurée</p>
                    </div>
                @endforelse
            </div>

            <!-- Sync Codes History -->
            @if($deliverer->syncCodes->count() > 0)
                <div class="bg-dark-100 rounded-xl shadow-lg border border-dark-200 p-6">
                    <h3 class="text-xl font-bold text-white mb-4 flex items-center">
                        <i class="fas fa-history text-primary-500 mr-2"></i>
                        Historique des Codes de Synchronisation
                    </h3>

                    <div class="space-y-3">
                        @foreach($deliverer->syncCodes as $code)
                            <div class="p-3 bg-dark-50 rounded-lg border border-dark-300 flex items-center justify-between">
                                <div>
                                    <code class="text-primary-400 font-mono font-bold">{{ $code->sync_code }}</code>
                                    <p class="text-xs text-gray-500 mt-1">
                                        Créé le {{ $code->created_at->format('d/m/Y H:i') }}
                                        • Expire le {{ $code->expires_at->format('d/m/Y') }}
                                    </p>
                                </div>
                                <div>
                                    @if($code->is_used)
                                        <span class="px-2 py-1 bg-green-900/30 text-green-400 text-xs rounded-full border border-green-500/50">
                                            <i class="fas fa-check-circle"></i> Utilisé
                                        </span>
                                    @elseif($code->isExpired())
                                        <span class="px-2 py-1 bg-red-900/30 text-red-400 text-xs rounded-full border border-red-500/50">
                                            <i class="fas fa-times-circle"></i> Expiré
                                        </span>
                                    @else
                                        <span class="px-2 py-1 bg-blue-900/30 text-blue-400 text-xs rounded-full border border-blue-500/50">
                                            <i class="fas fa-clock"></i> Valide
                                        </span>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    </div>
</div>
@endsection
