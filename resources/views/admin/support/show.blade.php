@extends('admin.layouts.app')

@section('content')
<div class="p-6 space-y-6">
    <!-- En-tête -->
    <div class="flex justify-between items-center">
        <div class="flex items-center gap-4">
            <a href="{{ route('admin.support.index') }}"
                class="px-4 py-2 bg-dark-100 border border-dark-200 hover:bg-dark-50 text-gray-100 rounded-lg transition-colors">
                <i class="fas fa-arrow-left mr-2"></i>
                Retour
            </a>
            <div>
                <h1 class="text-2xl font-bold text-gray-100">{{ $ticket->subject }}</h1>
                <p class="text-gray-400 mt-1">Ticket #{{ $ticket->ticket_number }}</p>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Conversation principale -->
        <div class="lg:col-span-2 space-y-6">
            <!-- Message initial -->
            <div class="bg-dark-100 border border-dark-200 rounded-lg p-6">
                <div class="flex items-start gap-4">
                    <div class="flex-shrink-0">
                        <div class="w-12 h-12 rounded-full bg-gradient-to-br from-primary-500 to-primary-600 flex items-center justify-center text-white font-bold">
                            {{ strtoupper(substr($ticket->user->name, 0, 1)) }}
                        </div>
                    </div>
                    <div class="flex-1">
                        <div class="flex items-center justify-between mb-2">
                            <div>
                                <p class="font-semibold text-gray-100">{{ $ticket->user->name }}</p>
                                <p class="text-sm text-gray-400">{{ $ticket->created_at->format('d/m/Y à H:i') }}</p>
                            </div>
                            <div class="flex items-center gap-2">
                                <span class="bg-{{ $ticket->status_color }}-600 text-white px-2 py-1 rounded text-xs font-bold">
                                    {{ $ticket->status_label }}
                                </span>
                                <span class="bg-{{ $ticket->priority_color }}-600 text-white px-2 py-1 rounded text-xs font-bold">
                                    {{ $ticket->priority_label }}
                                </span>
                            </div>
                        </div>
                        <div class="bg-dark-50 rounded-lg p-4 mt-3">
                            <p class="text-gray-200 leading-relaxed whitespace-pre-line">{{ $ticket->message }}</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Réponses -->
            @foreach($ticket->replies as $reply)
                <div class="bg-dark-100 border border-dark-200 rounded-lg p-6">
                    <div class="flex items-start gap-4 {{ $reply->is_admin ? 'flex-row-reverse' : '' }}">
                        <div class="flex-shrink-0">
                            <div class="w-10 h-10 rounded-full {{ $reply->is_admin ? 'bg-gradient-to-br from-green-500 to-green-600' : 'bg-gradient-to-br from-primary-500 to-primary-600' }} flex items-center justify-center text-white font-bold">
                                @if($reply->is_admin)
                                    <i class="fas fa-user-shield text-sm"></i>
                                @else
                                    {{ strtoupper(substr($reply->user->name, 0, 1)) }}
                                @endif
                            </div>
                        </div>
                        <div class="flex-1">
                            <div class="flex items-center {{ $reply->is_admin ? 'justify-end' : 'justify-start' }} gap-2 mb-2">
                                <p class="font-semibold text-gray-100">
                                    {{ $reply->user->name }}
                                    @if($reply->is_admin)
                                        <span class="text-xs text-green-400">(Support)</span>
                                    @endif
                                </p>
                                <p class="text-sm text-gray-400">{{ $reply->created_at->format('d/m/Y à H:i') }}</p>
                            </div>
                            <div class="bg-{{ $reply->is_admin ? 'green-900' : 'dark-50' }} bg-opacity-30 rounded-lg p-4">
                                <p class="text-gray-200 leading-relaxed whitespace-pre-line">{{ $reply->message }}</p>
                            </div>
                        </div>
                    </div>
                </div>
            @endforeach

            <!-- Formulaire de réponse -->
            @if($ticket->status !== 'closed')
                <div class="bg-dark-100 border border-dark-200 rounded-lg p-6">
                    <h3 class="text-lg font-semibold text-gray-100 mb-4">
                        <i class="fas fa-reply text-primary-500 mr-2"></i>
                        Répondre au ticket
                    </h3>
                    <form action="{{ route('admin.support.reply', $ticket) }}" method="POST">
                        @csrf
                        <div class="space-y-4">
                            <div>
                                <label for="message" class="block text-sm font-medium text-gray-300 mb-2">Votre réponse</label>
                                <textarea id="message" name="message" rows="6" required
                                    class="w-full px-4 py-3 bg-dark-50 border border-dark-200 rounded-lg text-gray-100 focus:ring-2 focus:ring-primary-500 resize-none"
                                    placeholder="Écrivez votre réponse ici...">{{ old('message') }}</textarea>
                                @error('message')
                                    <p class="text-red-500 text-sm mt-1">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="flex gap-3">
                                <button type="submit"
                                    class="px-6 py-2 bg-gradient-to-r from-primary-500 to-primary-600 text-white rounded-lg hover:shadow-lg transition-all">
                                    <i class="fas fa-paper-plane mr-2"></i>
                                    Envoyer la réponse
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            @else
                <div class="bg-gray-900 bg-opacity-50 border border-gray-700 rounded-lg p-6 text-center">
                    <i class="fas fa-lock text-4xl text-gray-500 mb-3"></i>
                    <p class="text-gray-400">Ce ticket est fermé. Impossible de répondre.</p>
                </div>
            @endif
        </div>

        <!-- Sidebar avec actions -->
        <div class="lg:col-span-1 space-y-6">
            <!-- Informations du ticket -->
            <div class="bg-dark-100 border border-dark-200 rounded-lg p-6 sticky top-24">
                <h3 class="text-lg font-semibold text-gray-100 mb-4">
                    <i class="fas fa-info-circle text-blue-500 mr-2"></i>
                    Informations
                </h3>

                <div class="space-y-4">
                    <!-- Utilisateur -->
                    <div>
                        <p class="text-sm text-gray-400 mb-1">Utilisateur</p>
                        <div class="flex items-center gap-2">
                            <div class="w-8 h-8 rounded-full bg-gradient-to-br from-primary-500 to-primary-600 flex items-center justify-center text-white text-sm font-bold">
                                {{ strtoupper(substr($ticket->user->name, 0, 1)) }}
                            </div>
                            <div>
                                <p class="text-gray-100 font-medium">{{ $ticket->user->name }}</p>
                                <p class="text-xs text-gray-400">{{ $ticket->user->email }}</p>
                            </div>
                        </div>
                    </div>

                    <!-- Catégorie -->
                    <div>
                        <p class="text-sm text-gray-400 mb-1">Catégorie</p>
                        <span class="inline-flex items-center bg-gray-600 text-white px-3 py-1 rounded text-sm">
                            <i class="fas {{ $ticket->category_icon }} mr-2"></i>
                            {{ $ticket->category_label }}
                        </span>
                    </div>

                    <!-- Admin assigné -->
                    <div>
                        <p class="text-sm text-gray-400 mb-1">Admin assigné</p>
                        @if($ticket->admin)
                            <p class="text-gray-100">
                                <i class="fas fa-user-shield text-green-500 mr-1"></i>
                                {{ $ticket->admin->name }}
                            </p>
                        @else
                            <p class="text-gray-500 italic">Non assigné</p>
                        @endif
                    </div>

                    <!-- Créé le -->
                    <div>
                        <p class="text-sm text-gray-400 mb-1">Créé le</p>
                        <p class="text-gray-100">{{ $ticket->created_at->format('d/m/Y à H:i') }}</p>
                        <p class="text-xs text-gray-500">{{ $ticket->created_at->diffForHumans() }}</p>
                    </div>

                    @if($ticket->resolved_at)
                        <div>
                            <p class="text-sm text-gray-400 mb-1">Résolu le</p>
                            <p class="text-gray-100">{{ $ticket->resolved_at->format('d/m/Y à H:i') }}</p>
                        </div>
                    @endif
                </div>

                <!-- Actions rapides -->
                <div class="mt-6 pt-6 border-t border-dark-200">
                    <h4 class="text-sm font-semibold text-gray-100 mb-3">Actions rapides</h4>

                    <!-- Changer le statut -->
                    <form action="{{ route('admin.support.update-status', $ticket) }}" method="POST" class="mb-3">
                        @csrf
                        <label class="block text-xs text-gray-400 mb-1">Statut</label>
                        <select name="status" onchange="this.form.submit()"
                            class="w-full px-3 py-2 bg-dark-50 border border-dark-200 rounded text-gray-100 text-sm">
                            <option value="open" {{ $ticket->status === 'open' ? 'selected' : '' }}>Ouvert</option>
                            <option value="in_progress" {{ $ticket->status === 'in_progress' ? 'selected' : '' }}>En cours</option>
                            <option value="resolved" {{ $ticket->status === 'resolved' ? 'selected' : '' }}>Résolu</option>
                            <option value="closed" {{ $ticket->status === 'closed' ? 'selected' : '' }}>Fermé</option>
                        </select>
                    </form>

                    <!-- Changer la priorité -->
                    <form action="{{ route('admin.support.update-priority', $ticket) }}" method="POST" class="mb-3">
                        @csrf
                        <label class="block text-xs text-gray-400 mb-1">Priorité</label>
                        <select name="priority" onchange="this.form.submit()"
                            class="w-full px-3 py-2 bg-dark-50 border border-dark-200 rounded text-gray-100 text-sm">
                            <option value="low" {{ $ticket->priority === 'low' ? 'selected' : '' }}>Basse</option>
                            <option value="medium" {{ $ticket->priority === 'medium' ? 'selected' : '' }}>Moyenne</option>
                            <option value="high" {{ $ticket->priority === 'high' ? 'selected' : '' }}>Haute</option>
                            <option value="urgent" {{ $ticket->priority === 'urgent' ? 'selected' : '' }}>Urgente</option>
                        </select>
                    </form>

                    <!-- Assigner à moi -->
                    @if(!$ticket->admin_id || $ticket->admin_id !== auth()->id())
                        <form action="{{ route('admin.support.assign', $ticket) }}" method="POST" class="mb-3">
                            @csrf
                            <button type="submit"
                                class="w-full px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded text-sm transition-colors">
                                <i class="fas fa-user-plus mr-2"></i>
                                M'assigner ce ticket
                            </button>
                        </form>
                    @endif

                    <!-- Supprimer -->
                    <form action="{{ route('admin.support.destroy', $ticket) }}" method="POST"
                        data-confirm="Êtes-vous sûr de vouloir supprimer ce ticket ?">
                        @csrf
                        @method('DELETE')
                        <button type="submit"
                            class="w-full px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded text-sm transition-colors">
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
