@extends('admin.layouts.app')

@section('content')
<div class="p-6 space-y-6">
    <!-- En-tête -->
    <div class="flex justify-between items-center">
        <div>
            <h1 class="text-3xl font-bold text-gray-100">Toutes les Conversations</h1>
            <p class="text-gray-400 mt-1">{{ number_format($conversations->total()) }} conversation(s) au total</p>
        </div>
        <a href="{{ route('admin.exchanges.index') }}"
            class="px-4 py-2 bg-dark-100 border border-dark-200 hover:bg-dark-50 text-gray-100 rounded-lg transition-colors">
            <i class="fas fa-arrow-left mr-2"></i>
            Retour
        </a>
    </div>

    <!-- Filtres -->
    <div class="bg-dark-100 border border-dark-200 rounded-lg p-4">
        <form method="GET" action="{{ route('admin.exchanges.conversations') }}" class="flex flex-wrap gap-4 items-end">
            <div class="flex-1 min-w-[200px]">
                <label for="search" class="block text-sm font-medium text-gray-300 mb-2">Rechercher</label>
                <input type="text" id="search" name="search"
                    value="{{ request('search') }}"
                    placeholder="Nom d'utilisateur..."
                    class="w-full px-4 py-2 bg-dark-50 border border-dark-200 rounded-lg text-gray-100 focus:ring-2 focus:ring-blue-500">
            </div>
            <div>
                <label class="flex items-center gap-2 text-gray-300">
                    <input type="checkbox" name="unread" value="1"
                        {{ request('unread') == '1' ? 'checked' : '' }}
                        class="rounded bg-dark-50 border-dark-200 text-blue-600 focus:ring-blue-500">
                    <span>Seulement non lus</span>
                </label>
            </div>
            <button type="submit" class="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition-colors">
                Filtrer
            </button>
            @if(request()->hasAny(['search', 'unread']))
                <a href="{{ route('admin.exchanges.conversations') }}"
                    class="px-6 py-2 bg-dark-50 border border-dark-200 hover:bg-dark-100 text-gray-100 rounded-lg transition-colors">
                    Réinitialiser
                </a>
            @endif
        </form>
    </div>

    <!-- Liste des conversations -->
    <div class="bg-dark-100 border border-dark-200 rounded-lg overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead>
                    <tr class="bg-dark-50 border-b border-dark-200">
                        <th class="text-left py-3 px-4 text-gray-300 font-medium">ID</th>
                        <th class="text-left py-3 px-4 text-gray-300 font-medium">Participants</th>
                        <th class="text-left py-3 px-4 text-gray-300 font-medium">Produit</th>
                        <th class="text-left py-3 px-4 text-gray-300 font-medium">Dernier Message</th>
                        <th class="text-center py-3 px-4 text-gray-300 font-medium">Messages</th>
                        <th class="text-center py-3 px-4 text-gray-300 font-medium">Non Lus</th>
                        <th class="text-left py-3 px-4 text-gray-300 font-medium">Date</th>
                        <th class="text-center py-3 px-4 text-gray-300 font-medium">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($conversations as $conversation)
                    <tr class="border-b border-dark-200 hover:bg-dark-50 transition-colors">
                        <td class="py-3 px-4 text-gray-300 font-mono text-sm">#{{ $conversation->id }}</td>
                        <td class="py-3 px-4">
                            <div class="space-y-1">
                                <div class="flex items-center gap-2">
                                    @if($conversation->user1->avatar)
                                        <img src="{{ media_url($conversation->user1->avatar) }}" alt="{{ $conversation->user1->name }}"
                                            class="w-6 h-6 rounded-full object-cover">
                                    @else
                                        <div class="w-6 h-6 bg-blue-600 rounded-full flex items-center justify-center">
                                            <span class="text-white text-xs">{{ substr($conversation->user1->name, 0, 1) }}</span>
                                        </div>
                                    @endif
                                    <span class="text-gray-100 text-sm">{{ $conversation->user1->name }}</span>
                                </div>
                                <div class="flex items-center gap-2">
                                    @if($conversation->user2->avatar)
                                        <img src="{{ media_url($conversation->user2->avatar) }}" alt="{{ $conversation->user2->name }}"
                                            class="w-6 h-6 rounded-full object-cover">
                                    @else
                                        <div class="w-6 h-6 bg-purple-600 rounded-full flex items-center justify-center">
                                            <span class="text-white text-xs">{{ substr($conversation->user2->name, 0, 1) }}</span>
                                        </div>
                                    @endif
                                    <span class="text-gray-100 text-sm">{{ $conversation->user2->name }}</span>
                                </div>
                            </div>
                        </td>
                        <td class="py-3 px-4">
                            @if($conversation->product)
                                <span class="text-gray-300">{{ Str::limit($conversation->product->name, 25) }}</span>
                            @else
                                <span class="text-gray-500 italic">Produit supprimé</span>
                            @endif
                        </td>
                        <td class="py-3 px-4">
                            @if($conversation->latestMessage)
                                <div class="text-sm">
                                    <span class="text-gray-400">{{ $conversation->latestMessage->sender->name }}:</span>
                                    <span class="text-gray-300">{{ Str::limit($conversation->latestMessage->message, 35) }}</span>
                                </div>
                            @else
                                <span class="text-gray-500 italic">Aucun message</span>
                            @endif
                        </td>
                        <td class="py-3 px-4 text-center">
                            <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-blue-600 text-white">
                                {{ $conversation->messages->count() }}
                            </span>
                        </td>
                        <td class="py-3 px-4 text-center">
                            @php
                                $unreadCount = $conversation->messages->where('is_read', false)->count();
                            @endphp
                            @if($unreadCount > 0)
                                <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-orange-600 text-white">
                                    {{ $unreadCount }}
                                </span>
                            @else
                                <span class="text-gray-500">-</span>
                            @endif
                        </td>
                        <td class="py-3 px-4">
                            <div class="text-sm">
                                <div class="text-gray-300">{{ $conversation->last_message_at?->displayTz()->format('d/m/Y') ?? 'N/A' }}</div>
                                <div class="text-gray-500">{{ $conversation->last_message_at?->displayTz()->format('H:i') ?? '' }}</div>
                            </div>
                        </td>
                        <td class="py-3 px-4 text-center">
                            <a href="{{ route('admin.exchanges.show', $conversation) }}"
                                class="inline-flex items-center px-3 py-1 bg-blue-600 hover:bg-blue-700 text-white text-sm rounded-lg transition-colors">
                                <i class="fas fa-eye mr-2"></i>
                                Voir
                            </a>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="8" class="py-12 text-center text-gray-400">
                            <i class="fas fa-inbox text-5xl mb-3"></i>
                            <p class="text-lg">Aucune conversation trouvée</p>
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <!-- Pagination -->
    @if($conversations->hasPages())
        <div class="flex justify-center">
            {{ $conversations->links() }}
        </div>
    @endif
</div>
@endsection
