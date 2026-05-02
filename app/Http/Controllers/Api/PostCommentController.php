<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Models\PostComment;
use App\Models\PostLike;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class PostCommentController extends Controller
{
    /**
     * Liste des commentaires d'un post
     */
    public function index(int $postId): JsonResponse
    {
        $post = Post::find($postId);

        if (!$post) {
            return response()->json([
                'success' => false,
                'message' => 'Post introuvable',
            ], 404);
        }

        $comments = PostComment::where('post_id', $postId)
            ->whereNull('parent_id')
            ->with([
                'user:id,first_name,last_name,avatar',
                'replies.user:id,first_name,last_name,avatar',
            ])
            ->recent()
            ->get();

        $userId = Auth::id();

        // Masquer les infos des commentaires anonymes et ajouter user_reaction
        $comments->transform(function ($comment) use ($userId) {
            $comment->user_reaction = $userId ? $comment->getUserReaction($userId) : null;
            $comment->is_liked = $userId ? $comment->isLikedByUser($userId) : false;
            $comment->is_my_comment = $userId && $comment->user_id === $userId;

            // Masquer les infos utilisateur si anonyme ET que ce n'est pas mon commentaire
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
                $reply->is_my_comment = $userId && $reply->user_id === $userId;

                // Masquer les infos utilisateur si anonyme ET que ce n'est pas ma réponse
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
            'data' => $comments,
        ]);
    }

    /**
     * Créer un commentaire
     */
    public function store(Request $request, int $postId): JsonResponse
    {
        $post = Post::find($postId);

        if (!$post) {
            return response()->json([
                'success' => false,
                'message' => 'Post introuvable',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'content' => 'required|string|max:2000',
            'is_anonymous' => 'boolean',
            'parent_id' => 'nullable|exists:post_comments,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        // Si parent_id existe, vérifier qu'il appartient au même post
        if ($request->parent_id) {
            $parentComment = PostComment::find($request->parent_id);
            if ($parentComment->post_id !== $postId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Le commentaire parent n\'appartient pas à ce post',
                ], 422);
            }
        }

        $comment = PostComment::create([
            'post_id' => $postId,
            'user_id' => Auth::id(),
            'parent_id' => $request->parent_id,
            'content' => $request->content,
            'is_anonymous' => $request->get('is_anonymous', false),
        ]);

        $comment->load('user:id,first_name,last_name,avatar');

        $userId = Auth::id();
        $comment->is_my_comment = $userId && $comment->user_id === $userId;

        // Masquer les infos utilisateur si anonyme ET que ce n'est pas mon commentaire
        if ($comment->is_anonymous && $comment->user_id !== $userId) {
            $comment->user = [
                'id' => null,
                'first_name' => 'Anonyme',
                'last_name' => '',
                'avatar' => null,
            ];
        }

        return response()->json([
            'success' => true,
            'message' => 'Commentaire créé avec succès',
            'data' => $comment,
        ], 201);
    }

    /**
     * Modifier un commentaire
     */
    public function update(Request $request, int $postId, int $commentId): JsonResponse
    {
        $comment = PostComment::where('post_id', $postId)->find($commentId);

        if (!$comment) {
            return response()->json([
                'success' => false,
                'message' => 'Commentaire introuvable',
            ], 404);
        }

        // Vérifier que l'utilisateur est le propriétaire du commentaire
        if ($comment->user_id !== Auth::id()) {
            return response()->json([
                'success' => false,
                'message' => 'Non autorisé',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'content' => 'required|string|max:2000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $comment->update([
            'content' => $request->content,
        ]);

        $comment->load('user:id,first_name,last_name,avatar');

        $userId = Auth::id();
        $comment->is_my_comment = $userId && $comment->user_id === $userId;

        // Masquer les infos utilisateur si anonyme ET que ce n'est pas mon commentaire
        if ($comment->is_anonymous && $comment->user_id !== $userId) {
            $comment->user = [
                'id' => null,
                'first_name' => 'Anonyme',
                'last_name' => '',
                'avatar' => null,
            ];
        }

        return response()->json([
            'success' => true,
            'message' => 'Commentaire modifié avec succès',
            'data' => $comment,
        ]);
    }

    /**
     * Supprimer un commentaire
     */
    public function destroy(int $postId, int $commentId): JsonResponse
    {
        $comment = PostComment::where('post_id', $postId)->find($commentId);

        if (!$comment) {
            return response()->json([
                'success' => false,
                'message' => 'Commentaire introuvable',
            ], 404);
        }

        // Vérifier que l'utilisateur est le propriétaire du commentaire
        if ($comment->user_id !== Auth::id()) {
            return response()->json([
                'success' => false,
                'message' => 'Non autorisé',
            ], 403);
        }

        $comment->delete();

        return response()->json([
            'success' => true,
            'message' => 'Commentaire supprimé avec succès',
        ]);
    }

    /**
     * Liker un commentaire
     */
    public function react(Request $request, int $postId, int $commentId): JsonResponse
    {
        $comment = PostComment::where('post_id', $postId)->find($commentId);

        if (!$comment) {
            return response()->json([
                'success' => false,
                'message' => 'Commentaire introuvable',
            ], 404);
        }

        $userId = Auth::id();

        // Chercher si un like existe déjà
        $existingLike = PostLike::where('user_id', $userId)
            ->where('likeable_id', $commentId)
            ->where('likeable_type', PostComment::class)
            ->first();

        if ($existingLike) {
            // Si déjà liké, retirer le like
            $existingLike->delete();
            $message = 'Like retiré';
        } else {
            // Créer un nouveau like
            PostLike::create([
                'user_id' => $userId,
                'likeable_id' => $commentId,
                'likeable_type' => PostComment::class,
                'type' => 'like',
            ]);
            $message = 'Commentaire liké';
        }

        // Recharger le commentaire avec les counts mis à jour
        $comment->refresh();

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => [
                'likes_count' => $comment->likes_count,
                'user_reaction' => $comment->getUserReaction($userId),
            ],
        ]);
    }

    /**
     * Retirer un like d'un commentaire
     */
    public function unreact(int $postId, int $commentId): JsonResponse
    {
        $comment = PostComment::where('post_id', $postId)->find($commentId);

        if (!$comment) {
            return response()->json([
                'success' => false,
                'message' => 'Commentaire introuvable',
            ], 404);
        }

        $userId = Auth::id();

        $like = PostLike::where('user_id', $userId)
            ->where('likeable_id', $commentId)
            ->where('likeable_type', PostComment::class)
            ->first();

        if ($like) {
            $like->delete();
        }

        // Recharger le commentaire avec les counts mis à jour
        $comment->refresh();

        return response()->json([
            'success' => true,
            'message' => 'Like retiré',
            'data' => [
                'likes_count' => $comment->likes_count,
                'user_reaction' => null,
            ],
        ]);
    }
}
