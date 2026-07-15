@extends('admin.layouts.app')

@section('content')
<div class="p-6">
    <!-- Header -->
    <div class="mb-6">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-3">
                <a href="{{ route('admin.announcements.index') }}"
                   class="text-gray-400 hover:text-primary-600 transition-colors">
                    <i class="fas fa-arrow-left"></i>
                </a>
                <div>
                    <h1 class="text-2xl font-bold text-white">Détails de l'Annonce</h1>
                    <p class="text-gray-400">Visualisez les informations complètes</p>
                </div>
            </div>
            <div class="flex gap-2">
                @if($announcement->status !== 'sent')
                    <a href="{{ route('admin.announcements.edit', $announcement) }}"
                       class="px-4 py-2 bg-gradient-to-r from-primary-500 to-primary-600 text-white rounded-lg hover:shadow-lg transition-all">
                        <i class="fas fa-edit mr-2"></i>
                        Modifier
                    </a>
                    <form action="{{ route('admin.announcements.send', $announcement) }}" method="POST"
                          data-confirm="Êtes-vous sûr de vouloir envoyer cette annonce ?"
                          class="inline">
                        @csrf
                        <button type="submit"
                                class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors">
                            <i class="fas fa-paper-plane mr-2"></i>
                            Envoyer
                        </button>
                    </form>
                @endif
                <form action="{{ route('admin.announcements.destroy', $announcement) }}" method="POST"
                      data-confirm="Êtes-vous sûr de vouloir supprimer cette annonce ?"
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
            <!-- Message Content -->
            <div class="bg-dark-100 rounded-xl shadow-lg border border-dark-200 p-6">
                <h3 class="text-lg font-semibold text-white mb-4 flex items-center">
                    <i class="fas fa-message text-primary-500 mr-2"></i>
                    Contenu du Message
                </h3>

                <div class="space-y-4">
                    <!-- Title -->
                    <div>
                        <label class="block text-sm font-medium text-gray-400 mb-2">
                            <i class="fas fa-heading text-primary-400 mr-1"></i>
                            Titre
                        </label>
                        @if($announcement->title)
                            <p class="text-white bg-dark-50 px-4 py-3 rounded-lg border border-dark-300">
                                {{ $announcement->title }}
                            </p>
                        @else
                            <p class="text-gray-500 italic bg-dark-50 px-4 py-3 rounded-lg border border-dark-300">
                                Aucun titre défini
                            </p>
                        @endif
                    </div>

                    <!-- Message -->
                    <div>
                        <label class="block text-sm font-medium text-gray-400 mb-2">
                            <i class="fas fa-paragraph text-primary-400 mr-1"></i>
                            Message
                        </label>
                        <div class="text-white bg-dark-50 px-4 py-3 rounded-lg border border-dark-300 whitespace-pre-wrap">
                            {{ $announcement->message }}
                        </div>
                    </div>
                </div>
            </div>

            <!-- Target Info -->
            <div class="bg-dark-100 rounded-xl shadow-lg border border-dark-200 p-6">
                <h3 class="text-lg font-semibold text-white mb-4 flex items-center">
                    <i class="fas fa-users text-primary-500 mr-2"></i>
                    Destinataires
                </h3>

                <div class="space-y-3">
                    <div class="flex items-center justify-between p-4 bg-dark-50 rounded-lg border border-dark-300">
                        <div>
                            <label class="block text-sm font-medium text-gray-400 mb-1">
                                Type de destinataire
                            </label>
                            <p class="text-white font-medium">
                                @if($announcement->target_type === 'all')
                                    <i class="fas fa-users text-primary-500 mr-2"></i>
                                    Tous les utilisateurs
                                @else
                                    <i class="fas fa-user text-primary-500 mr-2"></i>
                                    Utilisateur spécifique
                                @endif
                            </p>
                        </div>
                    </div>

                    @if($announcement->target_type === 'specific' && $announcement->user)
                        <div class="flex items-center justify-between p-4 bg-dark-50 rounded-lg border border-dark-300">
                            <div>
                                <label class="block text-sm font-medium text-gray-400 mb-1">
                                    Utilisateur cible
                                </label>
                                <p class="text-white font-medium">{{ $announcement->user->name }}</p>
                                <p class="text-gray-400 text-sm">{{ $announcement->user->email }}</p>
                            </div>
                        </div>
                    @endif
                </div>
            </div>

            <!-- Statistics (if sent) -->
            @if($announcement->status === 'sent')
                <div class="bg-dark-100 rounded-xl shadow-lg border border-dark-200 p-6">
                    <h3 class="text-lg font-semibold text-white mb-4 flex items-center">
                        <i class="fas fa-chart-bar text-primary-500 mr-2"></i>
                        Statistiques d'envoi
                    </h3>

                    <div class="grid grid-cols-2 gap-4">
                        <div class="bg-green-500/10 border border-green-500/30 rounded-lg p-4">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-xs text-gray-400 mb-1">Envoyés</p>
                                    <p class="text-2xl font-bold text-green-400">{{ $announcement->sent_count }}</p>
                                </div>
                                <i class="fas fa-check-circle text-green-500 text-3xl"></i>
                            </div>
                        </div>

                        <div class="bg-red-500/10 border border-red-500/30 rounded-lg p-4">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-xs text-gray-400 mb-1">Échecs</p>
                                    <p class="text-2xl font-bold text-red-400">{{ $announcement->failed_count }}</p>
                                </div>
                                <i class="fas fa-times-circle text-red-500 text-3xl"></i>
                            </div>
                        </div>
                    </div>

                    @if($announcement->sent_at)
                        <div class="mt-4 p-3 bg-dark-50 rounded-lg border border-dark-300">
                            <p class="text-sm text-gray-400">
                                <i class="fas fa-calendar-check text-green-400 mr-2"></i>
                                Envoyé le {{ $announcement->sent_at->format('d/m/Y à H:i') }}
                            </p>
                        </div>
                    @endif
                </div>
            @endif
        </div>

        <!-- Sidebar -->
        <div class="lg:col-span-1 space-y-6">
            <!-- Status -->
            <div class="bg-dark-100 rounded-xl shadow-lg border border-dark-200 p-6">
                <h3 class="text-lg font-semibold text-white mb-4 flex items-center">
                    <i class="fas fa-info-circle text-primary-500 mr-2"></i>
                    Statut
                </h3>

                <div class="space-y-3">
                    <div class="flex items-center justify-center p-4 bg-dark-50 rounded-lg border border-dark-300">
                        @if($announcement->status === 'sent')
                            <span class="px-4 py-2 text-lg font-semibold rounded-full bg-green-500/20 text-green-300 border border-green-500/50">
                                <i class="fas fa-check-circle mr-2"></i>
                                Envoyé
                            </span>
                        @elseif($announcement->status === 'scheduled')
                            <span class="px-4 py-2 text-lg font-semibold rounded-full bg-blue-500/20 text-blue-300 border border-blue-500/50">
                                <i class="fas fa-clock mr-2"></i>
                                Programmé
                            </span>
                        @else
                            <span class="px-4 py-2 text-lg font-semibold rounded-full bg-gray-500/20 text-gray-400 border border-gray-500/50">
                                <i class="fas fa-file mr-2"></i>
                                Brouillon
                            </span>
                        @endif
                    </div>

                    @if($announcement->scheduled_at && $announcement->status === 'scheduled')
                        <div class="p-3 bg-blue-500/10 border border-blue-500/30 rounded-lg">
                            <p class="text-sm text-blue-300">
                                <i class="fas fa-calendar text-blue-400 mr-2"></i>
                                Programmé pour le {{ $announcement->scheduled_at->format('d/m/Y à H:i') }}
                            </p>
                        </div>
                    @endif
                </div>
            </div>

            <!-- Channel -->
            <div class="bg-dark-100 rounded-xl shadow-lg border border-dark-200 p-6">
                <h3 class="text-lg font-semibold text-white mb-4 flex items-center">
                    <i class="fas fa-paper-plane text-primary-500 mr-2"></i>
                    Canal d'envoi
                </h3>

                <div class="flex items-center justify-center p-4 bg-{{ $announcement->channel_color }}-500/10 border border-{{ $announcement->channel_color }}-500/30 rounded-lg">
                    <i class="fas {{ $announcement->channel_icon }} text-{{ $announcement->channel_color }}-500 text-3xl mr-3"></i>
                    <div>
                        <p class="text-{{ $announcement->channel_color }}-300 font-semibold text-lg">{{ $announcement->channel_label }}</p>
                        <p class="text-xs text-gray-400">Canal de diffusion</p>
                    </div>
                </div>
            </div>

            <!-- Info -->
            <div class="bg-dark-100 rounded-xl shadow-lg border border-dark-200 p-6">
                <h3 class="text-lg font-semibold text-white mb-4 flex items-center">
                    <i class="fas fa-clock text-primary-500 mr-2"></i>
                    Informations
                </h3>

                <div class="space-y-3 text-sm">
                    <div class="flex items-center justify-between pb-3 border-b border-dark-300">
                        <span class="text-gray-400">
                            <i class="fas fa-calendar-plus w-5 text-primary-400"></i>
                            Créé le
                        </span>
                        <span class="text-white font-medium">
                            {{ $announcement->created_at->format('d/m/Y') }}
                        </span>
                    </div>
                    <div class="text-xs text-gray-500 text-right">
                        {{ $announcement->created_at->format('H:i:s') }}
                    </div>

                    <div class="flex items-center justify-between pt-3 border-t border-dark-300">
                        <span class="text-gray-400">
                            <i class="fas fa-calendar-edit w-5 text-primary-400"></i>
                            Modifié le
                        </span>
                        <span class="text-white font-medium">
                            {{ $announcement->updated_at->format('d/m/Y') }}
                        </span>
                    </div>
                    <div class="text-xs text-gray-500 text-right">
                        {{ $announcement->updated_at->format('H:i:s') }}
                    </div>

                    <div class="flex items-center justify-between pt-3 border-t border-dark-300">
                        <span class="text-gray-400">
                            <i class="fas fa-hashtag w-5 text-primary-400"></i>
                            ID
                        </span>
                        <span class="text-white font-mono font-medium">
                            #{{ $announcement->id }}
                        </span>
                    </div>
                </div>
            </div>

            <!-- Quick Actions -->
            @if($announcement->status !== 'sent')
                <div class="bg-gradient-to-br from-primary-500/10 to-primary-600/10 border border-primary-500/30 rounded-xl p-4">
                    <h4 class="text-sm font-semibold text-primary-400 mb-3 flex items-center">
                        <i class="fas fa-bolt mr-2"></i>
                        Actions rapides
                    </h4>
                    <div class="space-y-2">
                        <a href="{{ route('admin.announcements.edit', $announcement) }}"
                           class="flex items-center justify-between px-3 py-2 bg-dark-100/50 hover:bg-dark-100 rounded-lg transition-all text-sm text-gray-300 hover:text-white border border-dark-300">
                            <span><i class="fas fa-edit w-4 mr-2 text-primary-400"></i>Modifier</span>
                            <i class="fas fa-chevron-right text-xs"></i>
                        </a>
                        <form action="{{ route('admin.announcements.send', $announcement) }}" method="POST"
                              data-confirm="Êtes-vous sûr de vouloir envoyer cette annonce ?">
                            @csrf
                            <button type="submit"
                                    class="w-full flex items-center justify-between px-3 py-2 bg-dark-100/50 hover:bg-dark-100 rounded-lg transition-all text-sm text-gray-300 hover:text-white border border-dark-300">
                                <span><i class="fas fa-paper-plane w-4 mr-2 text-green-400"></i>Envoyer maintenant</span>
                                <i class="fas fa-chevron-right text-xs"></i>
                            </button>
                        </form>
                    </div>
                </div>
            @endif
        </div>
    </div>

    <!-- Back Button -->
    <div class="mt-6">
        <a href="{{ route('admin.announcements.index') }}"
           class="inline-flex items-center px-6 py-3 bg-dark-300 text-white rounded-lg hover:bg-dark-400 transition-all shadow-md">
            <i class="fas fa-arrow-left mr-2"></i>
            Retour à la liste
        </a>
    </div>
</div>
@endsection
