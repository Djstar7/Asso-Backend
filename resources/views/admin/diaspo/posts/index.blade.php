@extends('admin.layouts.app')

@section('title', 'Posts DIASPO')
@section('header', 'Posts DIASPO')

@section('content')
<div class="p-6">
    <!-- Header -->
    <div class="flex justify-between items-center mb-6">
        <div>
            <h1 class="text-2xl font-bold text-white">Posts DIASPO</h1>
            <p class="text-gray-400 mt-1">Gérez les posts des utilisateurs</p>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
        <div class="bg-dark-100 rounded-xl shadow-lg p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-gray-400 text-sm">Total Posts</p>
                    <p class="text-2xl font-bold text-white mt-1">{{ $stats['total'] }}</p>
                </div>
                <div class="w-12 h-12 bg-primary-500/20 rounded-lg flex items-center justify-center">
                    <i class="fas fa-comments text-primary-500 text-xl"></i>
                </div>
            </div>
        </div>

        <div class="bg-dark-100 rounded-xl shadow-lg p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-gray-400 text-sm">Posts Publics</p>
                    <p class="text-2xl font-bold text-white mt-1">{{ $stats['public'] }}</p>
                </div>
                <div class="w-12 h-12 bg-green-500/20 rounded-lg flex items-center justify-center">
                    <i class="fas fa-eye text-green-500 text-xl"></i>
                </div>
            </div>
        </div>

        <div class="bg-dark-100 rounded-xl shadow-lg p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-gray-400 text-sm">Posts Anonymes</p>
                    <p class="text-2xl font-bold text-white mt-1">{{ $stats['anonymous'] }}</p>
                </div>
                <div class="w-12 h-12 bg-blue-500/20 rounded-lg flex items-center justify-center">
                    <i class="fas fa-user-secret text-blue-500 text-xl"></i>
                </div>
            </div>
        </div>

        <div class="bg-dark-100 rounded-xl shadow-lg p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-gray-400 text-sm">Aujourd'hui</p>
                    <p class="text-2xl font-bold text-white mt-1">{{ $stats['today'] }}</p>
                </div>
                <div class="w-12 h-12 bg-yellow-500/20 rounded-lg flex items-center justify-center">
                    <i class="fas fa-calendar-day text-yellow-500 text-xl"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="bg-dark-100 rounded-xl shadow-lg p-4 mb-6">
        <form method="GET" action="{{ route('admin.diaspo.posts.index') }}" class="flex flex-wrap gap-3">
            <!-- Search -->
            <div class="flex-1 min-w-[250px]">
                <input type="text"
                       name="search"
                       value="{{ request('search') }}"
                       placeholder="Rechercher par contenu ou auteur..."
                       class="w-full px-4 py-2 bg-dark-50 text-white border border-dark-200 rounded-lg focus:outline-none focus:border-primary-500">
            </div>

            <!-- Type filter -->
            <select name="type" class="px-4 py-2 bg-dark-50 text-white border border-dark-200 rounded-lg focus:outline-none focus:border-primary-500">
                <option value="">Tous les types</option>
                <option value="public" {{ request('type') === 'public' ? 'selected' : '' }}>Publics</option>
                <option value="anonymous" {{ request('type') === 'anonymous' ? 'selected' : '' }}>Anonymes</option>
            </select>

            <!-- Order by -->
            <select name="order_by" class="px-4 py-2 bg-dark-50 text-white border border-dark-200 rounded-lg focus:outline-none focus:border-primary-500">
                <option value="recent" {{ request('order_by') === 'recent' || !request('order_by') ? 'selected' : '' }}>Plus récents</option>
                <option value="popular" {{ request('order_by') === 'popular' ? 'selected' : '' }}>Plus populaires</option>
                <option value="commented" {{ request('order_by') === 'commented' ? 'selected' : '' }}>Plus commentés</option>
            </select>

            <!-- Buttons -->
            <button type="submit" class="px-6 py-2 bg-primary-500 text-white rounded-lg hover:bg-primary-600 transition-all">
                <i class="fas fa-search mr-2"></i>Filtrer
            </button>
            <a href="{{ route('admin.diaspo.posts.index') }}" class="px-6 py-2 bg-dark-200 text-white rounded-lg hover:bg-dark-300 transition-all">
                <i class="fas fa-redo mr-2"></i>Réinitialiser
            </a>
        </form>
    </div>

    <!-- Posts List -->
    @if($posts->isEmpty())
        <div class="bg-dark-100 rounded-xl shadow-lg p-12 text-center">
            <i class="fas fa-comments text-6xl text-gray-600 mb-4"></i>
            <h3 class="text-xl font-semibold text-white mb-2">Aucun post</h3>
            <p class="text-gray-400">Aucun post trouvé avec ces filtres.</p>
        </div>
    @else
        <div class="grid gap-4">
            @foreach($posts as $post)
                <div class="bg-dark-100 rounded-xl shadow-lg hover:shadow-xl transition-all">
                    <div class="p-6">
                        <div class="flex items-start justify-between">
                            <div class="flex items-start space-x-4 flex-1">
                                <!-- User Avatar -->
                                <div class="w-12 h-12 rounded-full bg-gradient-to-br from-primary-500 to-primary-600 flex items-center justify-center text-white font-bold flex-shrink-0">
                                    @if($post->is_anonymous)
                                        <i class="fas fa-user-secret"></i>
                                    @else
                                        {{ strtoupper(substr($post->user->first_name ?? 'U', 0, 1)) }}
                                    @endif
                                </div>

                                <!-- Post Info -->
                                <div class="flex-1">
                                    <div class="flex items-center gap-3 mb-2">
                                        <h3 class="text-lg font-semibold text-white">
                                            @if($post->is_anonymous)
                                                Anonyme
                                            @else
                                                {{ $post->user->first_name }} {{ $post->user->last_name }}
                                            @endif
                                        </h3>
                                        @if($post->is_anonymous)
                                            <span class="px-3 py-1 bg-blue-500/20 text-blue-400 text-xs font-semibold rounded-full">
                                                <i class="fas fa-user-secret mr-1"></i>Anonyme
                                            </span>
                                        @endif
                                    </div>

                                    @if(!$post->is_anonymous)
                                        <div class="text-xs text-gray-500 mb-3">
                                            <i class="fas fa-phone mr-1"></i>{{ $post->user->phone }}
                                        </div>
                                    @endif

                                    <!-- Content -->
                                    <p class="text-gray-300 mb-3 line-clamp-3">{{ $post->content }}</p>

                                    <!-- Stats -->
                                    <div class="flex items-center gap-4 text-sm text-gray-400">
                                        <span><i class="fas fa-thumbs-up mr-1 text-green-500"></i>{{ $post->likes_count }} J'aime</span>
                                        <span><i class="fas fa-thumbs-down mr-1 text-red-500"></i>{{ $post->dislikes_count }} N'aime pas</span>
                                        <span><i class="fas fa-comments mr-1 text-blue-500"></i>{{ $post->comments_count }} Commentaires</span>
                                        <span class="ml-auto"><i class="fas fa-calendar mr-1"></i>{{ $post->created_at->format('d/m/Y à H:i') }}</span>
                                    </div>
                                </div>
                            </div>

                            <!-- Actions -->
                            <div class="flex items-center gap-2 ml-4">
                                <a href="{{ route('admin.diaspo.posts.show', $post->id) }}"
                                   class="px-4 py-2 bg-gradient-to-r from-primary-500 to-primary-600 text-white rounded-lg hover:shadow-lg transition-all">
                                    <i class="fas fa-eye mr-1"></i>Voir
                                </a>
                                <form action="{{ route('admin.diaspo.posts.destroy', $post->id) }}"
                                      method="POST"
                                      class="inline"
                                      onsubmit="return confirm('Êtes-vous sûr de vouloir supprimer ce post ?')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="px-4 py-2 bg-red-500 text-white rounded-lg hover:bg-red-600 transition-all">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        <!-- Pagination -->
        <div class="mt-6">
            {{ $posts->links() }}
        </div>
    @endif
</div>
@endsection
