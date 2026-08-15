@extends('admin.layouts.app')

@section('title', 'Détail du Post')
@section('header', 'Détail du Post')

@section('content')
<div class="p-6">
    <!-- Back Button -->
    <div class="mb-6">
        <a href="{{ route('admin.diaspo.posts.index') }}" class="inline-flex items-center text-primary-500 hover:text-primary-600 transition-colors">
            <i class="fas fa-arrow-left mr-2"></i>
            Retour à la liste
        </a>
    </div>

    <!-- Post Details -->
    <div class="bg-dark-100 rounded-xl shadow-lg p-6 mb-6">
        <div class="flex items-start justify-between mb-6">
            <div class="flex items-start space-x-4 flex-1">
                <!-- User Avatar -->
                <div class="w-16 h-16 rounded-full bg-gradient-to-br from-primary-500 to-primary-600 flex items-center justify-center text-white font-bold text-xl flex-shrink-0">
                    @if($post->is_anonymous)
                        <i class="fas fa-user-secret text-2xl"></i>
                    @else
                        {{ strtoupper(substr($post->user->first_name ?? 'U', 0, 1)) }}{{ strtoupper(substr($post->user->last_name ?? '', 0, 1)) }}
                    @endif
                </div>

                <!-- Post Info -->
                <div class="flex-1">
                    <div class="flex items-center gap-3 mb-2">
                        <h2 class="text-2xl font-bold text-white">
                            @if($post->is_anonymous)
                                Utilisateur Anonyme
                            @else
                                {{ $post->user->first_name }} {{ $post->user->last_name }}
                            @endif
                        </h2>
                        @if($post->is_anonymous)
                            <span class="px-3 py-1 bg-blue-500/20 text-blue-400 text-sm font-semibold rounded-full">
                                <i class="fas fa-user-secret mr-1"></i>Anonyme
                            </span>
                        @endif
                    </div>

                    @if(!$post->is_anonymous)
                        <div class="flex items-center gap-4 text-sm text-gray-400 mb-4">
                            <span><i class="fas fa-phone mr-1"></i>{{ $post->user->phone }}</span>
                            @if($post->user->email)
                                <span><i class="fas fa-envelope mr-1"></i>{{ $post->user->email }}</span>
                            @endif
                        </div>
                    @endif

                    <div class="text-sm text-gray-500">
                        <i class="fas fa-calendar mr-1"></i>
                        Publié le {{ $post->created_at->format('d/m/Y à H:i') }}
                    </div>
                </div>
            </div>

            <!-- Delete Action -->
            <form action="{{ route('admin.diaspo.posts.destroy', $post->id) }}"
                  method="POST"
                  class="inline"
                  onsubmit="return confirm('Êtes-vous sûr de vouloir supprimer ce post et tous ses commentaires ?')">
                @csrf
                @method('DELETE')
                <button type="submit" class="px-4 py-2 bg-red-500 text-white rounded-lg hover:bg-red-600 transition-all">
                    <i class="fas fa-trash mr-2"></i>Supprimer le post
                </button>
            </form>
        </div>

        <!-- Content -->
        <div class="bg-dark-50 rounded-lg p-6 mb-6">
            <h3 class="text-lg font-semibold text-white mb-3">Contenu du post</h3>
            <p class="text-gray-300 whitespace-pre-wrap">{{ $post->content }}</p>
        </div>

        <!-- Statistics -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div class="bg-dark-50 rounded-lg p-4">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-400 text-sm">J'aime</p>
                        <p class="text-2xl font-bold text-green-500 mt-1">{{ $post->likes()->count() }}</p>
                    </div>
                    <i class="fas fa-thumbs-up text-3xl text-green-500/20"></i>
                </div>
            </div>

            <div class="bg-dark-50 rounded-lg p-4">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-400 text-sm">N'aime pas</p>
                        <p class="text-2xl font-bold text-red-500 mt-1">{{ $post->dislikes()->count() }}</p>
                    </div>
                    <i class="fas fa-thumbs-down text-3xl text-red-500/20"></i>
                </div>
            </div>

            <div class="bg-dark-50 rounded-lg p-4">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-400 text-sm">Commentaires</p>
                        <p class="text-2xl font-bold text-blue-500 mt-1">{{ $post->allComments()->count() }}</p>
                    </div>
                    <i class="fas fa-comments text-3xl text-blue-500/20"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Comments Section -->
    @if($post->comments->isNotEmpty())
        <div class="bg-dark-100 rounded-xl shadow-lg p-6">
            <h3 class="text-xl font-bold text-white mb-4">
                <i class="fas fa-comments mr-2"></i>
                Commentaires ({{ $post->allComments()->count() }})
            </h3>

            <div class="space-y-4">
                @foreach($post->comments as $comment)
                    <div class="bg-dark-50 rounded-lg p-4">
                        <div class="flex items-start justify-between">
                            <div class="flex items-start space-x-3 flex-1">
                                <!-- Comment Author Avatar -->
                                <div class="w-10 h-10 rounded-full bg-gradient-to-br from-blue-500 to-blue-600 flex items-center justify-center text-white font-bold flex-shrink-0">
                                    @if($comment->is_anonymous)
                                        <i class="fas fa-user-secret"></i>
                                    @else
                                        {{ strtoupper(substr($comment->user->first_name ?? 'U', 0, 1)) }}
                                    @endif
                                </div>

                                <!-- Comment Content -->
                                <div class="flex-1">
                                    <div class="flex items-center gap-2 mb-1">
                                        <span class="font-semibold text-white">
                                            @if($comment->is_anonymous)
                                                Anonyme
                                            @else
                                                {{ $comment->user->first_name }} {{ $comment->user->last_name }}
                                            @endif
                                        </span>
                                        @if($comment->is_anonymous)
                                            <span class="px-2 py-0.5 bg-blue-500/20 text-blue-400 text-xs rounded-full">
                                                Anonyme
                                            </span>
                                        @endif
                                    </div>
                                    <p class="text-gray-300 text-sm">{{ $comment->content }}</p>
                                    <div class="text-xs text-gray-500 mt-1">
                                        {{ $comment->created_at->diffForHumans() }}
                                    </div>
                                </div>
                            </div>

                            <!-- Delete Comment -->
                            <form action="{{ route('admin.diaspo.posts.comments.destroy', [$post->id, $comment->id]) }}"
                                  method="POST"
                                  class="inline"
                                  onsubmit="return confirm('Supprimer ce commentaire ?')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="text-red-500 hover:text-red-600 transition-colors">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </form>
                        </div>

                        <!-- Replies -->
                        @if($comment->replies && $comment->replies->isNotEmpty())
                            <div class="ml-12 mt-3 space-y-3">
                                @foreach($comment->replies as $reply)
                                    <div class="bg-dark-100 rounded-lg p-3">
                                        <div class="flex items-start justify-between">
                                            <div class="flex items-start space-x-2 flex-1">
                                                <div class="w-8 h-8 rounded-full bg-gradient-to-br from-purple-500 to-purple-600 flex items-center justify-center text-white text-xs font-bold flex-shrink-0">
                                                    @if($reply->is_anonymous)
                                                        <i class="fas fa-user-secret"></i>
                                                    @else
                                                        {{ strtoupper(substr($reply->user->first_name ?? 'U', 0, 1)) }}
                                                    @endif
                                                </div>
                                                <div class="flex-1">
                                                    <div class="flex items-center gap-2 mb-1">
                                                        <span class="font-semibold text-white text-sm">
                                                            @if($reply->is_anonymous)
                                                                Anonyme
                                                            @else
                                                                {{ $reply->user->first_name }} {{ $reply->user->last_name }}
                                                            @endif
                                                        </span>
                                                    </div>
                                                    <p class="text-gray-300 text-xs">{{ $reply->content }}</p>
                                                    <div class="text-xs text-gray-500 mt-1">
                                                        {{ $reply->created_at->diffForHumans() }}
                                                    </div>
                                                </div>
                                            </div>
                                            <form action="{{ route('admin.diaspo.posts.comments.destroy', [$post->id, $reply->id]) }}"
                                                  method="POST"
                                                  class="inline"
                                                  onsubmit="return confirm('Supprimer cette réponse ?')">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="text-red-500 hover:text-red-600 transition-colors text-sm">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @else
        <div class="bg-dark-100 rounded-xl shadow-lg p-12 text-center">
            <i class="fas fa-comments text-6xl text-gray-600 mb-4"></i>
            <h3 class="text-xl font-semibold text-white mb-2">Aucun commentaire</h3>
            <p class="text-gray-400">Ce post n'a pas encore de commentaires.</p>
        </div>
    @endif
</div>
@endsection
