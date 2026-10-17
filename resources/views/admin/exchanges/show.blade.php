@extends('admin.layouts.app')

@section('content')
<div class="p-6 space-y-6">
    <!-- En-tête -->
    <div class="flex justify-between items-center">
        <div>
            <h1 class="text-3xl font-bold text-gray-100">Conversation #{{ $conversation->id }}</h1>
            <p class="text-gray-400 mt-1">{{ $conversation->messages->count() }} message(s)</p>
        </div>
        <a href="{{ route('admin.exchanges.conversations') }}"
            class="px-4 py-2 bg-dark-100 border border-dark-200 hover:bg-dark-50 text-gray-100 rounded-lg transition-colors">
            <i class="fas fa-arrow-left mr-2"></i>
            Retour
        </a>
    </div>

    <!-- Informations de la conversation -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Participant 1 -->
        <div class="bg-dark-100 border border-dark-200 rounded-lg p-6">
            <h3 class="text-lg font-semibold text-gray-100 mb-4">Participant 1</h3>
            <div class="flex items-center gap-4 mb-4">
                @if($conversation->user1->avatar)
                    <img src="{{ media_url($conversation->user1->avatar) }}" alt="{{ $conversation->user1->name }}"
                        class="w-16 h-16 rounded-full object-cover">
                @else
                    <div class="w-16 h-16 bg-blue-600 rounded-full flex items-center justify-center">
                        <span class="text-white text-xl font-bold">{{ substr($conversation->user1->name, 0, 1) }}</span>
                    </div>
                @endif
                <div>
                    <h4 class="text-gray-100 font-medium">{{ $conversation->user1->name }}</h4>
                    <p class="text-gray-400 text-sm">{{ $conversation->user1->email }}</p>
                </div>
            </div>
            @if($conversation->user1->phone)
                <div class="text-sm text-gray-300">
                    <i class="fas fa-phone mr-2"></i>
                    {{ $conversation->user1->phone }}
                </div>
            @endif
        </div>

        <!-- Participant 2 -->
        <div class="bg-dark-100 border border-dark-200 rounded-lg p-6">
            <h3 class="text-lg font-semibold text-gray-100 mb-4">Participant 2</h3>
            <div class="flex items-center gap-4 mb-4">
                @if($conversation->user2->avatar)
                    <img src="{{ media_url($conversation->user2->avatar) }}" alt="{{ $conversation->user2->name }}"
                        class="w-16 h-16 rounded-full object-cover">
                @else
                    <div class="w-16 h-16 bg-purple-600 rounded-full flex items-center justify-center">
                        <span class="text-white text-xl font-bold">{{ substr($conversation->user2->name, 0, 1) }}</span>
                    </div>
                @endif
                <div>
                    <h4 class="text-gray-100 font-medium">{{ $conversation->user2->name }}</h4>
                    <p class="text-gray-400 text-sm">{{ $conversation->user2->email }}</p>
                </div>
            </div>
            @if($conversation->user2->phone)
                <div class="text-sm text-gray-300">
                    <i class="fas fa-phone mr-2"></i>
                    {{ $conversation->user2->phone }}
                </div>
            @endif
        </div>

        <!-- Produit concerné -->
        <div class="bg-dark-100 border border-dark-200 rounded-lg p-6">
            <h3 class="text-lg font-semibold text-gray-100 mb-4">Produit Concerné</h3>
            @if($conversation->product)
                <div>
                    @php
                        $primaryImage = $conversation->product->primaryImage;
                    @endphp
                    @if($primaryImage)
                        <img src="{{ media_url($primaryImage->image_path) }}"
                            alt="{{ $conversation->product->name }}"
                            class="w-full h-32 object-cover rounded-lg mb-3">
                    @else
                        <div class="w-full h-32 bg-dark-50 rounded-lg flex items-center justify-center mb-3">
                            <i class="fas fa-image text-gray-600 text-3xl"></i>
                        </div>
                    @endif
                    <h4 class="text-gray-100 font-medium mb-1">{{ $conversation->product->name }}</h4>
                    <p class="text-blue-400 font-bold mb-2">{{ number_format($conversation->product->price, 0, ',', ' ') }} XOF</p>
                    <a href="{{ route('admin.products.show', $conversation->product) }}"
                        class="inline-flex items-center text-sm text-blue-400 hover:text-blue-300">
                        Voir le produit <i class="fas fa-arrow-right ml-2"></i>
                    </a>
                </div>
            @else
                <div class="text-center py-8 text-gray-500">
                    <i class="fas fa-times-circle text-4xl mb-2"></i>
                    <p>Produit supprimé</p>
                </div>
            @endif
        </div>
    </div>

    <!-- Fil de conversation -->
    <div class="bg-dark-100 border border-dark-200 rounded-lg p-6">
        <h3 class="text-xl font-semibold text-gray-100 mb-6">Fil de Conversation</h3>

        <div class="space-y-4 max-h-[600px] overflow-y-auto">
            @forelse($conversation->messages as $message)
                @php
                    $isUser1 = $message->sender_id === $conversation->user1_id;
                    $bgColor = $isUser1 ? 'bg-blue-600' : 'bg-purple-600';
                @endphp

                <div class="flex {{ $isUser1 ? 'justify-start' : 'justify-end' }}">
                    <div class="max-w-[70%]">
                        <div class="flex items-center gap-2 mb-1 {{ $isUser1 ? '' : 'justify-end' }}">
                            <span class="text-sm font-medium text-gray-300">{{ $message->sender->name }}</span>
                            <span class="text-xs text-gray-500">{{ $message->created_at->displayTz()->format('d/m/Y H:i') }}</span>
                        </div>

                        <div class="{{ $bgColor }} rounded-lg p-4">
                            <p class="text-white">{{ $message->message }}</p>
                        </div>

                        <div class="flex items-center gap-2 mt-1 {{ $isUser1 ? '' : 'justify-end' }}">
                            @if($message->is_read)
                                <span class="text-xs text-green-400">
                                    <i class="fas fa-check-double mr-1"></i>
                                    Lu {{ $message->read_at?->displayTz()->format('d/m/Y H:i') }}
                                </span>
                            @else
                                <span class="text-xs text-gray-500">
                                    <i class="fas fa-check mr-1"></i>
                                    Non lu
                                </span>
                            @endif
                        </div>
                    </div>
                </div>
            @empty
                <div class="py-12 text-center text-gray-400">
                    <i class="fas fa-comments text-5xl mb-3"></i>
                    <p class="text-lg">Aucun message dans cette conversation</p>
                </div>
            @endforelse
        </div>
    </div>

    <!-- Métadonnées -->
    <div class="bg-dark-100 border border-dark-200 rounded-lg p-6">
        <h3 class="text-xl font-semibold text-gray-100 mb-4">Métadonnées</h3>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
                <span class="text-gray-400 text-sm">Créée le</span>
                <p class="text-gray-100 font-medium">{{ $conversation->created_at->displayTz()->format('d/m/Y H:i:s') }}</p>
            </div>
            <div>
                <span class="text-gray-400 text-sm">Dernier message</span>
                <p class="text-gray-100 font-medium">{{ $conversation->last_message_at?->displayTz()->format('d/m/Y H:i:s') ?? 'N/A' }}</p>
            </div>
            <div>
                <span class="text-gray-400 text-sm">Messages non lus</span>
                <p class="text-gray-100 font-medium">{{ $conversation->messages->where('is_read', false)->count() }}</p>
            </div>
        </div>
    </div>
</div>
@endsection
