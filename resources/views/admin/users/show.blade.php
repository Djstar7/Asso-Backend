@extends('admin.layouts.app')

@section('content')
<div class="p-6">
    <!-- Header -->
    <div class="mb-6">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-3">
                <a href="{{ route('admin.users.index') }}"
                   class="text-gray-400 hover:text-primary-500 transition-colors">
                    <i class="fas fa-arrow-left"></i>
                </a>
                <div>
                    <h1 class="text-2xl font-bold text-white">Profil Utilisateur</h1>
                    <p class="text-gray-400">Détails et informations complètes</p>
                </div>
            </div>
            <div class="flex gap-3">
                <a href="{{ route('admin.users.edit', $user) }}"
                   class="px-4 py-2 bg-gradient-to-r from-primary-500 to-primary-600 text-white rounded-lg hover:shadow-lg transition-all">
                    <i class="fas fa-edit mr-2"></i>
                    Modifier
                </a>
                <form action="{{ route('admin.users.destroy', $user) }}"
                      method="POST"
                      data-confirm="Êtes-vous sûr de vouloir supprimer cet utilisateur ?">
                    @csrf
                    @method('DELETE')
                    <button type="submit"
                            class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-all">
                        <i class="fas fa-trash mr-2"></i>
                        Supprimer
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- User Profile Card -->
        <div class="lg:col-span-1">
            <div class="bg-dark-100 rounded-xl shadow-lg border border-dark-200 p-6">
                <!-- Avatar -->
                <div class="flex flex-col items-center mb-6">
                    <div class="h-32 w-32 rounded-full bg-gradient-to-br from-primary-500 to-primary-600 flex items-center justify-center text-white text-4xl font-bold mb-4 shadow-lg shadow-primary-500/30">
                        {{ strtoupper(substr($user->first_name, 0, 1)) }}{{ strtoupper(substr($user->last_name, 0, 1)) }}
                    </div>
                    <h2 class="text-2xl font-bold text-white">{{ $user->name }}</h2>
                    <p class="text-gray-400">{{ $user->email }}</p>

                    <!-- Role Badge -->
                    <div class="mt-4">
                        @if($user->role == 'client')
                            <span class="px-4 py-2 inline-flex text-sm leading-5 font-semibold rounded-full bg-blue-500/20 text-blue-300 border border-blue-500/50">
                                <i class="fas fa-user mr-2"></i> Client
                            </span>
                        @elseif($user->role == 'vendeur')
                            <span class="px-4 py-2 inline-flex text-sm leading-5 font-semibold rounded-full bg-green-500/20 text-green-300 border border-green-500/50">
                                <i class="fas fa-store mr-2"></i> Vendeur
                            </span>
                        @elseif($user->role == 'livreur')
                            <span class="px-4 py-2 inline-flex text-sm leading-5 font-semibold rounded-full bg-primary-500/20 text-primary-300 border border-primary-500/50">
                                <i class="fas fa-truck mr-2"></i> Livreur
                            </span>
                        @elseif($user->role == 'admin')
                            <span class="px-4 py-2 inline-flex text-sm leading-5 font-semibold rounded-full bg-purple-500/20 text-purple-300 border border-purple-500/50">
                                <i class="fas fa-crown mr-2"></i> Admin
                            </span>
                        @endif
                    </div>
                </div>

                <!-- Quick Stats -->
                <div class="border-t border-dark-200 pt-6 space-y-4">
                    <div class="flex items-center justify-between">
                        <span class="text-gray-400">
                            <i class="fas fa-store text-primary-500 mr-2"></i>
                            Boutiques
                        </span>
                        <span class="font-semibold text-white">{{ $user->shops->count() }}</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-gray-400">
                            <i class="fas fa-box text-primary-500 mr-2"></i>
                            Produits
                        </span>
                        <span class="font-semibold text-white">{{ $user->products->count() }}</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-gray-400">
                            <i class="fas fa-calendar text-primary-500 mr-2"></i>
                            Membre depuis
                        </span>
                        <span class="font-semibold text-white">{{ $user->created_at->format('d/m/Y') }}</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- User Details & Shops -->
        <div class="lg:col-span-2 space-y-6">
            <!-- Personal Information -->
            <div class="bg-dark-100 rounded-xl shadow-lg border border-dark-200 p-6">
                <h3 class="text-xl font-bold text-white mb-4 flex items-center">
                    <i class="fas fa-info-circle text-primary-500 mr-2"></i>
                    Informations Personnelles
                </h3>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="text-sm text-gray-500">Prénom</label>
                        <p class="text-white font-medium">{{ $user->first_name }}</p>
                    </div>
                    <div>
                        <label class="text-sm text-gray-500">Nom</label>
                        <p class="text-white font-medium">{{ $user->last_name }}</p>
                    </div>
                    <div>
                        <label class="text-sm text-gray-500">Email</label>
                        <p class="text-white font-medium">{{ $user->email }}</p>
                    </div>
                    <div>
                        <label class="text-sm text-gray-500">Téléphone</label>
                        <p class="text-white font-medium">{{ $user->phone ?? 'Non renseigné' }}</p>
                    </div>
                    <div>
                        <label class="text-sm text-gray-500">Genre</label>
                        <p class="text-white font-medium">
                            @if($user->gender == 'male')
                                <i class="fas fa-mars text-blue-400 mr-1"></i> Homme
                            @elseif($user->gender == 'female')
                                <i class="fas fa-venus text-pink-400 mr-1"></i> Femme
                            @elseif($user->gender == 'other')
                                <i class="fas fa-venus-mars text-purple-400 mr-1"></i> Autre
                            @else
                                Non renseigné
                            @endif
                        </p>
                    </div>
                    <div>
                        <label class="text-sm text-gray-500">Date de naissance</label>
                        <p class="text-white font-medium">
                            {{ $user->birth_date ? $user->birth_date->format('d/m/Y') : 'Non renseigné' }}
                        </p>
                    </div>
                    <div>
                        <label class="text-sm text-gray-500">Pays</label>
                        <p class="text-white font-medium">{{ $user->country ?? 'Non renseigné' }}</p>
                    </div>
                    <div>
                        <label class="text-sm text-gray-500">Date d'inscription</label>
                        <p class="text-white font-medium">{{ $user->created_at->format('d/m/Y à H:i') }}</p>
                    </div>
                </div>

            </div>

            <!-- Map Section -->
            @if($user->latitude && $user->longitude)
                <div class="mt-6 pt-6 border-t border-dark-200">
                    @include('admin.partials.google-map-view', [
                        'id' => 'user-location-map',
                        'label' => 'Localisation de l\'utilisateur',
                        'latitude' => $user->latitude,
                        'longitude' => $user->longitude,
                        'address' => $user->address,
                        'zoom' => 15
                    ])
                </div>
            @elseif($user->address)
                <div class="mt-6">
                    <label class="text-sm text-gray-500">Adresse</label>
                    <p class="text-white font-medium">{{ $user->address }}</p>
                </div>
            @endif
            </div>

            <!-- User's Shops -->
            <div class="bg-dark-100 rounded-xl shadow-lg border border-dark-200 p-6">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-xl font-bold text-white flex items-center">
                        <i class="fas fa-store text-primary-500 mr-2"></i>
                        Boutiques ({{ $user->shops->count() }})
                    </h3>
                    @if($user->role == 'vendeur' || $user->role == 'client')
                        <a href="{{ route('admin.shops.create', ['user_id' => $user->id]) }}"
                           class="px-4 py-2 bg-gradient-to-r from-primary-500 to-primary-600 text-white rounded-lg hover:shadow-lg transition-all text-sm">
                            <i class="fas fa-plus mr-2"></i>
                            Ajouter une boutique
                        </a>
                    @endif
                </div>

                @if($user->shops->count() > 0)
                    <div class="grid grid-cols-1 gap-4">
                        @foreach($user->shops as $shop)
                            <div class="border border-dark-200 bg-dark-50 rounded-lg p-4 hover:border-primary-500 transition-all">
                                <div class="flex items-start justify-between">
                                    <div class="flex-1">
                                        <div class="flex items-center gap-2 mb-2">
                                            <h4 class="text-lg font-semibold text-white">{{ $shop->name }}</h4>
                                            @if($shop->status == 'active')
                                                <span class="px-2 py-1 text-xs rounded-full bg-green-500/20 text-green-300 border border-green-500/50">
                                                    <i class="fas fa-check-circle"></i> Active
                                                </span>
                                            @else
                                                <span class="px-2 py-1 text-xs rounded-full bg-gray-500/20 text-gray-300 border border-gray-500/50">
                                                    <i class="fas fa-times-circle"></i> Inactive
                                                </span>
                                            @endif
                                        </div>

                                        @if($shop->description)
                                            <p class="text-gray-400 text-sm mb-3">{{ $shop->description }}</p>
                                        @endif

                                        <div class="flex items-center gap-4 text-sm text-gray-400">
                                            <span>
                                                <i class="fas fa-box text-primary-500 mr-1"></i>
                                                {{ $shop->products->count() }} produits
                                            </span>
                                            @if($shop->shop_link)
                                                <a href="{{ $shop->shop_link }}"
                                                   target="_blank"
                                                   class="text-primary-500 hover:text-primary-400">
                                                    <i class="fas fa-external-link-alt mr-1"></i>
                                                    Visiter
                                                </a>
                                            @endif
                                        </div>
                                    </div>

                                    <div class="flex gap-2 ml-4">
                                        <a href="{{ route('admin.shops.show', $shop) }}"
                                           class="text-blue-400 hover:text-blue-300"
                                           title="Voir">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <a href="{{ route('admin.shops.edit', $shop) }}"
                                           class="text-primary-400 hover:text-primary-300"
                                           title="Modifier">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="text-center py-12 text-gray-500">
                        <i class="fas fa-store text-4xl text-gray-600 mb-3"></i>
                        <p class="text-lg">Aucune boutique</p>
                        <p class="text-sm">Cet utilisateur n'a pas encore de boutique</p>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection
