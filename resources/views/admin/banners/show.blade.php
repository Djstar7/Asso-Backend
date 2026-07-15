@extends('admin.layouts.app')

@section('content')
<div class="p-6">
    <!-- Header -->
    <div class="mb-6">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-3">
                <a href="{{ route('admin.banners.index') }}"
                   class="text-gray-400 hover:text-primary-600 transition-colors">
                    <i class="fas fa-arrow-left"></i>
                </a>
                <div>
                    <h1 class="text-2xl font-bold text-white">Détails de la Bannière</h1>
                    <p class="text-gray-400">Visualisez les informations complètes</p>
                </div>
            </div>
            <div class="flex gap-2">
                <a href="{{ route('admin.banners.edit', $banner) }}"
                   class="px-4 py-2 bg-gradient-to-r from-primary-500 to-primary-600 text-white rounded-lg hover:shadow-lg transition-all">
                    <i class="fas fa-edit mr-2"></i>
                    Modifier
                </a>
                <form action="{{ route('admin.banners.destroy', $banner) }}" method="POST"
                      data-confirm="Êtes-vous sûr de vouloir supprimer cette bannière ?"
                      class="inline">
                    @csrf
                    @method('DELETE')
                    <button type="submit"
                            class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors">
                        <i class="fas fa-trash mr-2"></i>
                        Supprimer
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Main Content -->
        <div class="lg:col-span-2 space-y-6">
            <!-- Banner Image -->
            <div class="bg-dark-100 rounded-xl shadow-lg border border-dark-200 overflow-hidden">
                <div class="p-6 border-b border-dark-200">
                    <h3 class="text-lg font-semibold text-white flex items-center">
                        <i class="fas fa-image text-primary-500 mr-2"></i>
                        Image de la Bannière
                    </h3>
                </div>
                <div class="p-6 bg-dark-50">
                    <img src="{{ asset('storage/' . $banner->image_path) }}"
                         alt="{{ $banner->title }}"
                         class="w-full rounded-lg border-2 border-dark-300 shadow-lg">
                </div>
            </div>

            <!-- Banner Content -->
            <div class="bg-dark-100 rounded-xl shadow-lg border border-dark-200 p-6">
                <h3 class="text-lg font-semibold text-white mb-4 flex items-center">
                    <i class="fas fa-align-left text-primary-500 mr-2"></i>
                    Contenu
                </h3>

                <div class="space-y-4">
                    <!-- Title -->
                    <div>
                        <label class="block text-sm font-medium text-gray-400 mb-2">
                            <i class="fas fa-heading text-primary-400 mr-1"></i>
                            Titre
                        </label>
                        @if($banner->title)
                            <p class="text-white bg-dark-50 px-4 py-3 rounded-lg border border-dark-300">
                                {{ $banner->title }}
                            </p>
                        @else
                            <p class="text-gray-500 italic bg-dark-50 px-4 py-3 rounded-lg border border-dark-300">
                                Aucun titre défini
                            </p>
                        @endif
                    </div>

                    <!-- Description -->
                    <div>
                        <label class="block text-sm font-medium text-gray-400 mb-2">
                            <i class="fas fa-paragraph text-primary-400 mr-1"></i>
                            Description
                        </label>
                        @if($banner->description)
                            <div class="text-white bg-dark-50 px-4 py-3 rounded-lg border border-dark-300 whitespace-pre-wrap">
                                {{ $banner->description }}
                            </div>
                        @else
                            <p class="text-gray-500 italic bg-dark-50 px-4 py-3 rounded-lg border border-dark-300">
                                Aucune description définie
                            </p>
                        @endif
                    </div>

                    <!-- Link -->
                    <div>
                        <label class="block text-sm font-medium text-gray-400 mb-2">
                            <i class="fas fa-link text-primary-400 mr-1"></i>
                            Lien de redirection
                        </label>
                        @if($banner->link)
                            <div class="bg-dark-50 px-4 py-3 rounded-lg border border-dark-300">
                                <a href="{{ $banner->link }}" target="_blank"
                                   class="text-primary-400 hover:text-primary-300 inline-flex items-center break-all">
                                    {{ $banner->link }}
                                    <i class="fas fa-external-link-alt ml-2 flex-shrink-0"></i>
                                </a>
                            </div>
                        @else
                            <p class="text-gray-500 italic bg-dark-50 px-4 py-3 rounded-lg border border-dark-300">
                                Aucun lien défini
                            </p>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        <!-- Sidebar -->
        <div class="lg:col-span-1 space-y-6">
            <!-- Status & Position -->
            <div class="bg-dark-100 rounded-xl shadow-lg border border-dark-200 p-6">
                <h3 class="text-lg font-semibold text-white mb-4 flex items-center">
                    <i class="fas fa-cog text-primary-500 mr-2"></i>
                    Paramètres
                </h3>

                <div class="space-y-4">
                    <!-- Status -->
                    <div>
                        <label class="block text-sm font-medium text-gray-400 mb-2">
                            <i class="fas fa-toggle-on text-primary-400 mr-1"></i>
                            Statut
                        </label>
                        <div class="flex items-center gap-2">
                            <span class="px-4 py-2 text-sm font-semibold rounded-lg {{ $banner->is_active ? 'bg-green-500/20 text-green-300 border border-green-500/50' : 'bg-gray-500/20 text-gray-400 border border-gray-500/50' }}">
                                <i class="fas fa-{{ $banner->is_active ? 'check-circle' : 'times-circle' }} mr-1"></i>
                                {{ $banner->is_active ? 'Active' : 'Inactive' }}
                            </span>
                            <form action="{{ route('admin.banners.toggle-status', $banner) }}" method="POST" class="inline">
                                @csrf
                                @method('PATCH')
                                <button type="submit"
                                        class="px-3 py-2 text-sm bg-dark-50 text-gray-300 rounded-lg hover:bg-dark-200 border border-dark-300 transition-colors"
                                        title="Changer le statut">
                                    <i class="fas fa-exchange-alt"></i>
                                </button>
                            </form>
                        </div>
                    </div>

                    <!-- Position -->
                    <div>
                        <label class="block text-sm font-medium text-gray-400 mb-2">
                            <i class="fas fa-sort-numeric-up text-primary-400 mr-1"></i>
                            Position d'affichage
                        </label>
                        <span class="inline-block px-4 py-2 text-sm font-semibold rounded-lg {{ $banner->position > 0 ? 'bg-blue-500/20 text-blue-300 border border-blue-500/50' : 'bg-gray-500/20 text-gray-400 border border-gray-500/50' }}">
                            {{ $banner->position > 0 ? 'Position ' . $banner->position : 'Non défini (0)' }}
                        </span>
                    </div>
                </div>
            </div>

            <!-- Image Info -->
            <div class="bg-dark-100 rounded-xl shadow-lg border border-dark-200 p-6">
                <h3 class="text-lg font-semibold text-white mb-4 flex items-center">
                    <i class="fas fa-info-circle text-primary-500 mr-2"></i>
                    Informations
                </h3>

                <div class="space-y-3 text-sm">
                    <div class="flex items-center justify-between pb-3 border-b border-dark-300">
                        <span class="text-gray-400">
                            <i class="fas fa-file-image w-5 text-primary-400"></i>
                            Chemin image
                        </span>
                    </div>
                    <div class="bg-dark-50 px-3 py-2 rounded text-xs text-gray-300 break-all font-mono">
                        {{ $banner->image_path }}
                    </div>

                    <div class="flex items-center justify-between pt-3 border-t border-dark-300">
                        <span class="text-gray-400">
                            <i class="fas fa-calendar-plus w-5 text-primary-400"></i>
                            Créé le
                        </span>
                        <span class="text-white font-medium">
                            {{ $banner->created_at->format('d/m/Y') }}
                        </span>
                    </div>
                    <div class="text-xs text-gray-500 text-right">
                        {{ $banner->created_at->format('H:i:s') }}
                    </div>

                    <div class="flex items-center justify-between pt-3 border-t border-dark-300">
                        <span class="text-gray-400">
                            <i class="fas fa-calendar-edit w-5 text-primary-400"></i>
                            Modifié le
                        </span>
                        <span class="text-white font-medium">
                            {{ $banner->updated_at->format('d/m/Y') }}
                        </span>
                    </div>
                    <div class="text-xs text-gray-500 text-right">
                        {{ $banner->updated_at->format('H:i:s') }}
                    </div>

                    <div class="flex items-center justify-between pt-3 border-t border-dark-300">
                        <span class="text-gray-400">
                            <i class="fas fa-hashtag w-5 text-primary-400"></i>
                            ID
                        </span>
                        <span class="text-white font-mono font-medium">
                            #{{ $banner->id }}
                        </span>
                    </div>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="bg-gradient-to-br from-primary-500/10 to-primary-600/10 border border-primary-500/30 rounded-xl p-4">
                <h4 class="text-sm font-semibold text-primary-400 mb-3 flex items-center">
                    <i class="fas fa-bolt mr-2"></i>
                    Actions rapides
                </h4>
                <div class="space-y-2">
                    <a href="{{ route('admin.banners.edit', $banner) }}"
                       class="flex items-center justify-between px-3 py-2 bg-dark-100/50 hover:bg-dark-100 rounded-lg transition-all text-sm text-gray-300 hover:text-white border border-dark-300">
                        <span><i class="fas fa-edit w-4 mr-2 text-primary-400"></i>Modifier</span>
                        <i class="fas fa-chevron-right text-xs"></i>
                    </a>
                    <button onclick="window.open('{{ asset('storage/' . $banner->image_path) }}', '_blank')"
                            class="w-full flex items-center justify-between px-3 py-2 bg-dark-100/50 hover:bg-dark-100 rounded-lg transition-all text-sm text-gray-300 hover:text-white border border-dark-300">
                        <span><i class="fas fa-download w-4 mr-2 text-primary-400"></i>Télécharger image</span>
                        <i class="fas fa-chevron-right text-xs"></i>
                    </button>
                    @if($banner->link)
                        <a href="{{ $banner->link }}" target="_blank"
                           class="flex items-center justify-between px-3 py-2 bg-dark-100/50 hover:bg-dark-100 rounded-lg transition-all text-sm text-gray-300 hover:text-white border border-dark-300">
                            <span><i class="fas fa-external-link-alt w-4 mr-2 text-primary-400"></i>Ouvrir le lien</span>
                            <i class="fas fa-chevron-right text-xs"></i>
                        </a>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <!-- Back Button -->
    <div class="mt-6">
        <a href="{{ route('admin.banners.index') }}"
           class="inline-flex items-center px-6 py-3 bg-dark-300 text-white rounded-lg hover:bg-dark-400 transition-all shadow-md">
            <i class="fas fa-arrow-left mr-2"></i>
            Retour à la liste
        </a>
    </div>
</div>
@endsection
