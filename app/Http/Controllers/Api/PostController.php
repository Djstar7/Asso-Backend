<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Models\PostLike;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class PostController extends Controller
{
    /**
     * Liste des posts avec pagination
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = $request->get('per_page', 20);
        $sort = $request->get('sort', 'recent'); // recent | popular

        $query = Post::with([
            'user:id,first_name,last_name,avatar',
        ]);

        // Sorting
        if ($sort === 'popular') {
            $query->popular();
        } else {
            $query->recent();
        }

        $posts = $query->paginate($perPage);

        // Ajouter les infos de réaction de l'utilisateur connecté
        $userId = Auth::id();
        $posts->getCollection()->transform(function ($post) use ($userId) {
            $post->user_reaction = $userId ? $post->getUserReaction($userId) : null;
            $post->is_liked = $userId ? $post->isLikedByUser($userId) : false;
            $post->is_disliked = $userId ? $post->isDislikedByUser($userId) : false;
            $post->is_my_post = $userId && $post->user_id === $userId;

            // Masquer les infos utilisateur si anonyme ET que ce n'est pas mon post
            if ($post->is_anonymous && $post->user_id !== $userId) {
                $post->user = [
                    'id' => null,
                    'first_name' => 'Anonyme',
                    'last_name' => '',
                    'avatar' => null,
                ];
            }

            return $post;
        });

        return response()->json([
            'success' => true,
            'data' => $posts,
        ]);
    }

    /**
     * Mes posts
     */
    public function myPosts(Request $request): JsonResponse
    {
        $perPage = $request->get('per_page', 20);

        $posts = Post::where('user_id', Auth::id())
            ->with(['user:id,first_name,last_name,avatar'])
            ->recent()
            ->paginate($perPage);

        return response()->json($posts);
    }

    /**
     * Créer un post
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'content' => 'required|string|max:5000',
            'is_anonymous' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $post = Post::create([
            'user_id' => Auth::id(),
            'content' => $request->content,
            'is_anonymous' => $request->get('is_anonymous', false),
        ]);

        $post->load('user:id,first_name,last_name,avatar');

        // Ne pas masquer les infos car c'est MON post que je viens de créer
        // L'utilisateur doit voir son propre nom même si anonyme

        return response()->json([
            'success' => true,
            'message' => 'Post créé avec succès',
            'data' => $post,
        ], 201);
    }

    /**
     * Afficher un post spécifique
     */
    public function show(int $id): JsonResponse
    {
        $post = Post::with([
            'user:id,first_name,last_name,avatar',
            'comments.user:id,first_name,last_name,avatar',
            'comments.replies.user:id,first_name,last_name,avatar',
        ])->find($id);

        if (!$post) {
            return response()->json([
                'success' => false,
                'message' => 'Post introuvable',
            ], 404);
        }

        // Ajouter la réaction de l'utilisateur connecté
        $userId = Auth::id();
        $post->user_reaction = $userId ? $post->getUserReaction($userId) : null;
        $post->is_liked = $userId ? $post->isLikedByUser($userId) : false;
        $post->is_disliked = $userId ? $post->isDislikedByUser($userId) : false;
        $post->is_my_post = $userId && $post->user_id === $userId;

        // Masquer les infos utilisateur si anonyme ET que ce n'est pas mon post
        if ($post->is_anonymous && $post->user_id !== $userId) {
            $post->user = [
                'id' => null,
                'first_name' => 'Anonyme',
                'last_name' => '',
                'avatar' => null,
            ];
        }

        // Masquer les infos des commentaires anonymes
        $post->comments->transform(function ($comment) use ($userId) {
            $comment->user_reaction = $userId ? $comment->getUserReaction($userId) : null;
            $comment->is_liked = $userId ? $comment->isLikedByUser($userId) : false;

            // Masquer si anonyme ET que ce n'est pas mon commentaire
            if ($comment->is_anonymous && $comment->user_id !== $userId) {
                $comment->user = [
                    'id' => null,
                    'first_name' => 'Anonyme',
                    'last_name' => '',
                    'avatar' => null,
                ];
            }

            // Masquer les réponses anonymes
            $comment->replies->transform(function ($reply) use ($userId) {
                $reply->user_reaction = $userId ? $reply->getUserReaction($userId) : null;
                $reply->is_liked = $userId ? $reply->isLikedByUser($userId) : false;

                // Masquer si anonyme ET que ce n'est pas ma réponse
                if ($reply->is_anonymous && $reply->user_id !== $userId) {
                    $reply->user = [
                        'id' => null,
                        'first_name' => 'Anonyme',
                        'last_name' => '',
                        'avatar' => null,
                    ];
                }

                return $reply;
            });

            return $comment;
        });

        return response()->json([
            'success' => true,
            'data' => $post,
        ]);
    }

    /**
     * Modifier un post
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $post = Post::find($id);

        if (!$post) {
            return response()->json([
                'success' => false,
                'message' => 'Post introuvable',
            ], 404);
        }

        // Vérifier que l'utilisateur est le propriétaire du post
        if ($post->user_id !== Auth::id()) {
            return response()->json([
                'success' => false,
                'message' => 'Non autorisé',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'content' => 'required|string|max:5000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $post->update([
            'content' => $request->content,
        ]);

        $post->load('user:id,first_name,last_name,avatar');

        // Ne pas masquer les infos car c'est MON post que je suis en train de modifier
        // L'utilisateur doit voir son propre nom même si anonyme

        return response()->json([
            'success' => true,
            'message' => 'Post modifié avec succès',
            'data' => $post,
        ]);
    }

    /**
     * Supprimer un post
     */
    public function destroy(int $id): JsonResponse
    {
        $post = Post::find($id);

        if (!$post) {
            return response()->json([
                'success' => false,
                'message' => 'Post introuvable',
            ], 404);
        }

        // Vérifier que l'utilisateur est le propriétaire du post
        if ($post->user_id !== Auth::id()) {
            return response()->json([
                'success' => false,
                'message' => 'Non autorisé',
            ], 403);
        }

        $post->delete();

        return response()->json([
            'success' => true,
            'message' => 'Post supprimé avec succès',
        ]);
    }

    /**
     * Liker/Disliker un post
     */
    public function react(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'type' => 'required|in:like,dislike',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $post = Post::find($id);

        if (!$post) {
            return response()->json([
                'success' => false,
                'message' => 'Post introuvable',
            ], 404);
        }

        $userId = Auth::id();
        $type = $request->type;

        // Chercher si une réaction existe déjà
        $existingReaction = PostLike::where('user_id', $userId)
            ->where('likeable_id', $id)
            ->where('likeable_type', Post::class)
            ->first();

        if ($existingReaction) {
            // Si même type, on retire la réaction
            if ($existingReaction->type === $type) {
                $existingReaction->delete();
                $message = $type === 'like' ? 'Like retiré' : 'Dislike retiré';
            } else {
                // Sinon on change le type
                $existingReaction->update(['type' => $type]);
                $message = $type === 'like' ? 'Post liké' : 'Post disliké';
            }
        } else {
            // Créer une nouvelle réaction
            PostLike::create([
                'user_id' => $userId,
                'likeable_id' => $id,
                'likeable_type' => Post::class,
                'type' => $type,
            ]);
            $message = $type === 'like' ? 'Post liké' : 'Post disliké';
        }

        // Recharger le post avec les counts mis à jour
        $post->refresh();

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => [
                'likes_count' => $post->likes_count,
                'dislikes_count' => $post->dislikes_count,
                'user_reaction' => $post->getUserReaction($userId),
            ],
        ]);
    }

    /**
     * Retirer une réaction
     */
    public function unreact(int $id): JsonResponse
    {
        $post = Post::find($id);

        if (!$post) {
            return response()->json([
                'success' => false,
                'message' => 'Post introuvable',
            ], 404);
        }

        $userId = Auth::id();

        $reaction = PostLike::where('user_id', $userId)
            ->where('likeable_id', $id)
            ->where('likeable_type', Post::class)
            ->first();

        if ($reaction) {
            $reaction->delete();
        }

        // Recharger le post avec les counts mis à jour
        $post->refresh();

        return response()->json([
            'success' => true,
            'message' => 'Réaction retirée',
            'data' => [
                'likes_count' => $post->likes_count,
                'dislikes_count' => $post->dislikes_count,
                'user_reaction' => null,
            ],
        ]);
    }
}
