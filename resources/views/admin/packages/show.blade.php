@extends('admin.layouts.app')

@section('content')
<div class="p-6 space-y-6">
    <!-- En-tête -->
    <div class="flex justify-between items-center">
        <div>
            <h1 class="text-3xl font-bold text-gray-100">{{ $package->name }}</h1>
            <p class="text-gray-400 mt-1">{{ $package->type_label }}</p>
        </div>
        <div class="flex gap-3">
            <a href="{{ route('admin.packages.index') }}"
                class="px-4 py-2 bg-dark-100 border border-dark-200 hover:bg-dark-50 text-gray-100 rounded-lg transition-colors">
                <i class="fas fa-arrow-left mr-2"></i>
                Retour
            </a>
            <a href="{{ route('admin.packages.edit', $package) }}"
                class="px-4 py-2 bg-gradient-to-r from-primary-500 to-primary-600 text-white rounded-lg hover:shadow-lg transition-all">
                <i class="fas fa-edit mr-2"></i>
                Modifier
            </a>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Carte principale du package -->
        <div class="lg:col-span-1">
            <div class="bg-dark-100 border-2 {{ $package->is_popular ? 'border-primary-500' : 'border-dark-200' }} rounded-xl overflow-hidden sticky top-24">
                @if($package->is_popular)
                    <div class="bg-primary-500 text-white text-center py-2 text-sm font-bold">
                        <i class="fas fa-star mr-1"></i>
                        PACKAGE POPULAIRE
                    </div>
                @endif

                <div class="bg-gradient-to-r from-{{ $package->type_color }}-600 to-{{ $package->type_color }}-700 p-8">
                    <div class="flex items-center justify-center mb-4">
                        <div class="bg-white bg-opacity-20 p-6 rounded-full">
                            <i class="fas {{ $package->type_icon }} text-white text-5xl"></i>
                        </div>
                    </div>
                    <h3 class="text-2xl font-bold text-white text-center">{{ $package->name }}</h3>
                </div>

                <div class="p-6">
                    <div class="text-center mb-6">
                        <div class="text-5xl font-bold text-gray-100">
                            {{ number_format($package->price, 0, ',', ' ') }}
                        </div>
                        <div class="text-xl text-gray-400 mt-2">XOF / {{ $package->formatted_duration }}</div>
                    </div>

                    <div class="space-y-4">
                        <div class="flex items-center justify-between text-gray-300">
                            <span>Statut</span>
                            @if($package->is_active)
                                <span class="bg-green-500 text-white px-3 py-1 rounded-full text-xs font-bold">
                                    <i class="fas fa-check-circle mr-1"></i>
                                    ACTIF
                                </span>
                            @else
                                <span class="bg-gray-500 text-white px-3 py-1 rounded-full text-xs font-bold">
                                    <i class="fas fa-times-circle mr-1"></i>
                                    INACTIF
                                </span>
                            @endif
                        </div>

                        <div class="flex items-center justify-between text-gray-300">
                            <span>Type</span>
                            <span class="bg-{{ $package->type_color }}-600 text-white px-3 py-1 rounded-full text-xs font-bold">
                                {{ $package->type_label }}
                            </span>
                        </div>

                        <div class="flex items-center justify-between text-gray-300">
                            <span>Durée</span>
                            <span class="font-semibold">{{ $package->duration_days }} jours</span>
                        </div>

                        <div class="flex items-center justify-between text-gray-300">
                            <span>Ordre d'affichage</span>
                            <span class="font-semibold">{{ $package->order }}</span>
                        </div>

                        <div class="flex items-center justify-between text-gray-300">
                            <span>Créé le</span>
                            <span class="text-sm">{{ $package->created_at->format('d/m/Y') }}</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Détails et informations -->
        <div class="lg:col-span-2 space-y-6">
            <!-- Description -->
            <div class="bg-dark-100 border border-dark-200 rounded-lg p-6">
                <h3 class="text-xl font-semibold text-gray-100 mb-4">
                    <i class="fas fa-info-circle text-blue-500 mr-2"></i>
                    Description
                </h3>
                <p class="text-gray-300 leading-relaxed">{{ $package->description }}</p>
            </div>

            <!-- Caractéristiques selon le type -->
            @if($package->type === 'storage')
                <div class="bg-dark-100 border border-dark-200 rounded-lg p-6">
                    <h3 class="text-xl font-semibold text-gray-100 mb-4">
                        <i class="fas fa-database text-blue-500 mr-2"></i>
                        Caractéristiques de Stockage
                    </h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div class="bg-dark-50 p-4 rounded-lg">
                            <div class="text-sm text-gray-400 mb-1">Taille de stockage</div>
                            <div class="text-2xl font-bold text-gray-100">{{ $package->formatted_storage_size }}</div>
                        </div>
                        <div class="bg-dark-50 p-4 rounded-lg">
                            <div class="text-sm text-gray-400 mb-1">En Mégaoctets</div>
                            <div class="text-2xl font-bold text-gray-100">{{ number_format($package->storage_size_mb) }} Mo</div>
                        </div>
                    </div>
                </div>
            @endif

            @if($package->type === 'boost')
                <div class="bg-dark-100 border border-dark-200 rounded-lg p-6">
                    <h3 class="text-xl font-semibold text-gray-100 mb-4">
                        <i class="fas fa-rocket text-purple-500 mr-2"></i>
                        Caractéristiques du Boost
                    </h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div class="bg-dark-50 p-4 rounded-lg">
                            <div class="text-sm text-gray-400 mb-1">Portée</div>
                            <div class="text-2xl font-bold text-gray-100">{{ number_format($package->reach_users) }} users</div>
                        </div>
                        <div class="bg-dark-50 p-4 rounded-lg">
                            <div class="text-sm text-gray-400 mb-1">Durée de boost</div>
                            <div class="text-2xl font-bold text-gray-100">{{ $package->duration_days }} jours</div>
                        </div>
                    </div>
                </div>
            @endif

            @if($package->type === 'certification' && $package->benefits)
                <div class="bg-dark-100 border border-dark-200 rounded-lg p-6">
                    <h3 class="text-xl font-semibold text-gray-100 mb-4">
                        <i class="fas fa-certificate text-green-500 mr-2"></i>
                        Avantages de la Certification
                    </h3>
                    <div class="grid grid-cols-1 gap-3">
                        @foreach($package->benefits as $benefit)
                            <div class="flex items-start bg-dark-50 p-4 rounded-lg">
                                <div class="bg-green-500 bg-opacity-20 p-2 rounded-lg mr-3 flex-shrink-0">
                                    <i class="fas fa-check text-green-500"></i>
                                </div>
                                <div class="flex-1">
                                    <p class="text-gray-200">{{ $benefit }}</p>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            <!-- Actions rapides -->
            <div class="bg-dark-100 border border-dark-200 rounded-lg p-6">
                <h3 class="text-xl font-semibold text-gray-100 mb-4">
                    <i class="fas fa-cog text-primary-500 mr-2"></i>
                    Actions Rapides
                </h3>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <form action="{{ route('admin.packages.toggle-active', $package) }}" method="POST">
                        @csrf
                        <button type="submit" class="w-full px-4 py-3 {{ $package->is_active ? 'bg-orange-600 hover:bg-orange-700' : 'bg-green-600 hover:bg-green-700' }} text-white rounded-lg transition-colors">
                            <i class="fas {{ $package->is_active ? 'fa-times-circle' : 'fa-check-circle' }} mr-2"></i>
                            {{ $package->is_active ? 'Désactiver' : 'Activer' }}
                        </button>
                    </form>

                    <form action="{{ route('admin.packages.toggle-popular', $package) }}" method="POST">
                        @csrf
                        <button type="submit" class="w-full px-4 py-3 {{ $package->is_popular ? 'bg-gray-600 hover:bg-gray-700' : 'bg-yellow-600 hover:bg-yellow-700' }} text-white rounded-lg transition-colors">
                            <i class="fas fa-star mr-2"></i>
                            {{ $package->is_popular ? 'Retirer populaire' : 'Marquer populaire' }}
                        </button>
                    </form>

                    <form action="{{ route('admin.packages.destroy', $package) }}" method="POST"
                        data-confirm="Êtes-vous sûr de vouloir supprimer ce package ?">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="w-full px-4 py-3 bg-red-600 hover:bg-red-700 text-white rounded-lg transition-colors">
                            <i class="fas fa-trash mr-2"></i>
                            Supprimer
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
