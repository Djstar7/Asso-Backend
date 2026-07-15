@extends('admin.layouts.app')

@section('content')
<div class="p-6">
    <!-- Header -->
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-white">Annonces Générales</h1>
            <p class="text-gray-400">Gérez et envoyez des annonces via SMS, WhatsApp, Email ou Push</p>
        </div>
        <a href="{{ route('admin.announcements.create') }}"
           class="px-4 py-2 bg-gradient-to-r from-primary-500 to-primary-600 text-white rounded-lg hover:shadow-lg transition-all">
            <i class="fas fa-plus mr-2"></i>
            Nouvelle Annonce
        </a>
    </div>

    @if(session('success'))
        <div class="mb-6 p-4 bg-green-900/20 border-l-4 border-green-500 rounded">
            <p class="text-green-300"><i class="fas fa-check-circle mr-2"></i>{{ session('success') }}</p>
        </div>
    @endif

    @if(session('error'))
        <div class="mb-6 p-4 bg-red-900/20 border-l-4 border-red-500 rounded">
            <p class="text-red-300"><i class="fas fa-exclamation-circle mr-2"></i>{{ session('error') }}</p>
        </div>
    @endif

    <!-- Announcements List -->
    <div class="bg-dark-100 rounded-xl shadow-lg border border-dark-200">
        @if($announcements->count() > 0)
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-dark-200">
                    <thead class="bg-dark-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-white uppercase tracking-wider">Message</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-white uppercase tracking-wider">Canal</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-white uppercase tracking-wider">Destinataire</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-white uppercase tracking-wider">Statut</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-white uppercase tracking-wider">Date</th>
                            <th class="px-6 py-3 text-right text-xs font-semibold text-white uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="bg-dark-100 divide-y divide-dark-200">
                        @foreach($announcements as $announcement)
                            <tr class="hover:bg-dark-50 transition-colors">
                                <!-- Message -->
                                <td class="px-6 py-4">
                                    <div class="text-sm">
                                        @if($announcement->title)
                                            <div class="font-medium text-white mb-1">{{ $announcement->title }}</div>
                                        @endif
                                        <div class="text-gray-400 text-xs line-clamp-2">{{ Str::limit($announcement->message, 100) }}</div>
                                    </div>
                                </td>

                                <!-- Channel -->
                                <td class="px-6 py-4">
                                    <span class="px-3 py-1 text-sm font-semibold rounded-full bg-{{ $announcement->channel_color }}-500/20 text-{{ $announcement->channel_color }}-300 border border-{{ $announcement->channel_color }}-500/50">
                                        <i class="fas {{ $announcement->channel_icon }} mr-1"></i>
                                        {{ $announcement->channel_label }}
                                    </span>
                                </td>

                                <!-- Target -->
                                <td class="px-6 py-4">
                                    <div class="text-sm">
                                        @if($announcement->target_type === 'all')
                                            <span class="text-gray-300">
                                                <i class="fas fa-users text-primary-400 mr-1"></i>
                                                Tous les utilisateurs
                                            </span>
                                        @else
                                            <span class="text-gray-300">
                                                <i class="fas fa-user text-primary-400 mr-1"></i>
                                                {{ $announcement->user?->name ?? 'Utilisateur supprimé' }}
                                            </span>
                                        @endif
                                    </div>
                                </td>

                                <!-- Status -->
                                <td class="px-6 py-4">
                                    @if($announcement->status === 'sent')
                                        <span class="px-3 py-1 text-sm font-semibold rounded-full bg-green-500/20 text-green-300 border border-green-500/50">
                                            <i class="fas fa-check-circle mr-1"></i>
                                            Envoyé
                                        </span>
                                    @elseif($announcement->status === 'scheduled')
                                        <span class="px-3 py-1 text-sm font-semibold rounded-full bg-blue-500/20 text-blue-300 border border-blue-500/50">
                                            <i class="fas fa-clock mr-1"></i>
                                            Programmé
                                        </span>
                                    @else
                                        <span class="px-3 py-1 text-sm font-semibold rounded-full bg-gray-500/20 text-gray-400 border border-gray-500/50">
                                            <i class="fas fa-file mr-1"></i>
                                            Brouillon
                                        </span>
                                    @endif
                                    @if($announcement->status === 'sent')
                                        <div class="text-xs text-gray-500 mt-1">
                                            {{ $announcement->sent_count }} envoyé(s)
                                            @if($announcement->failed_count > 0)
                                                <span class="text-red-400">· {{ $announcement->failed_count }} échec(s)</span>
                                            @endif
                                        </div>
                                    @endif
                                </td>

                                <!-- Date -->
                                <td class="px-6 py-4 text-sm text-gray-400">
                                    @if($announcement->sent_at)
                                        <div class="text-xs">
                                            <i class="fas fa-paper-plane text-green-400 mr-1"></i>
                                            {{ $announcement->sent_at->format('d/m/Y H:i') }}
                                        </div>
                                    @elseif($announcement->scheduled_at)
                                        <div class="text-xs">
                                            <i class="fas fa-calendar text-blue-400 mr-1"></i>
                                            {{ $announcement->scheduled_at->format('d/m/Y H:i') }}
                                        </div>
                                    @else
                                        <div class="text-xs">
                                            <i class="fas fa-calendar-plus text-gray-500 mr-1"></i>
                                            {{ $announcement->created_at->format('d/m/Y H:i') }}
                                        </div>
                                    @endif
                                </td>

                                <!-- Actions -->
                                <td class="px-6 py-4 text-right text-sm">
                                    <div class="flex justify-end gap-2">
                                        <a href="{{ route('admin.announcements.show', $announcement) }}"
                                           class="px-3 py-1.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors"
                                           title="Voir">
                                            <i class="fas fa-eye"></i>
                                        </a>

                                        @if($announcement->status !== 'sent')
                                            <a href="{{ route('admin.announcements.edit', $announcement) }}"
                                               class="px-3 py-1.5 bg-gradient-to-r from-primary-500 to-primary-600 text-white rounded-lg hover:shadow-lg transition-all"
                                               title="Modifier">
                                                <i class="fas fa-edit"></i>
                                            </a>

                                            <form action="{{ route('admin.announcements.send', $announcement) }}" method="POST"
                                                  data-confirm="Êtes-vous sûr de vouloir envoyer cette annonce ?"
                                                  class="inline">
                                                @csrf
                                                <button type="submit"
                                                        class="px-3 py-1.5 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors"
                                                        title="Envoyer">
                                                    <i class="fas fa-paper-plane"></i>
                                                </button>
                                            </form>
                                        @endif

                                        <form action="{{ route('admin.announcements.destroy', $announcement) }}" method="POST"
                                              data-confirm="Êtes-vous sûr de vouloir supprimer cette annonce ?"
                                              class="inline">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit"
                                                    class="px-3 py-1.5 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors"
                                                    title="Supprimer">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <div class="px-6 py-4 border-t border-dark-200">
                {{ $announcements->links() }}
            </div>
        @else
            <div class="text-center py-12 text-gray-400">
                <i class="fas fa-bullhorn text-6xl text-gray-600 mb-4"></i>
                <p class="text-lg">Aucune annonce trouvée</p>
                <p class="text-sm mt-2">Créez votre première annonce pour commencer</p>
            </div>
        @endif
    </div>
</div>
@endsection
