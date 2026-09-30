<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Models\PostComment;
use App\Models\PostLike;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class PostCommentController extends Controller
{
    public const MAX_CONTENT_LENGTH = 2000;

    /**
     * Format attendu par le mobile (PostComment.fromJson). L'auteur d'un
     * commentaire anonyme n'est jamais exposé, sauf à lui-même.
     */
    private function payload(PostComment $comment, ?int $userId, bool $withReplies = true): array
    {
        $isMine = $userId !== null && (int) $comment->user_id === $userId;
        $hideAuthor = $comment->is_anonymous && !$isMine;
        $reaction = $userId ? $comment->getUserReaction($userId) : null;

        $data = [
            'id' => $comment->id,
            'post_id' => (int) $comment->post_id,
            'user_id' => $hideAuthor ? null : $comment->user_id,
            'parent_id' => $comment->parent_id,
            'content' => $comment->content,
            'is_anonymous' => (bool) $comment->is_anonymous,
            'likes_count' => (int) $comment->likes_count,
            'user_reaction' => $reaction,
            'is_liked' => $reaction === 'like',
            'is_my_comment' => $isMine,
            'created_at' => $comment->created_at?->toIso8601String(),
            'updated_at' => $comment->updated_at?->toIso8601String(),
            'user' => $hideAuthor || !$comment->user
                ? ['id' => null, 'first_name' => 'Anonyme', 'last_name' => '', 'avatar' => null]
                : [
                    'id' => $comment->user->id,
                    'first_name' => $comment->user->first_name,
                    'last_name' => $comment->user->last_name,
                    'avatar' => $comment->user->avatar,
                ],
        ];

        if ($withReplies) {
            $data['replies'] = $comment->relationLoaded('replies')
                ? $comment->replies
                    ->sortBy('created_at')
                    ->map(fn (PostComment $reply) => $this->payload($reply, $userId, false))
                    ->values()
                    ->all()
                : [];
        }

        return $data;
    }

    private function findComment(int $postId, int $commentId): ?PostComment
    {
        return PostComment::where('post_id', $postId)->find($commentId);
    }

    private function notFound(?string $message = null): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message ?? __('posts.comment_not_found')], 404);
    }

    private function validateInput(Request $request, array $rules): ?JsonResponse
    {
        $validator = Validator::make($request->all(), $rules, [
            'content.required' => __('posts.comment_required'),
            'content.max' => __('posts.comment_max', ['max' => self::MAX_CONTENT_LENGTH]),
            'parent_id.exists' => __('posts.comment_parent_missing'),
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        return null;
    }

    /** Liste des commentaires d'un post (avec leurs réponses). */
    public function index(int $postId): JsonResponse
    {
        if (!Post::whereKey($postId)->exists()) {
            return $this->notFound(__('posts.not_found'));
        }

        $userId = Auth::id();
        $comments = PostComment::where('post_id', $postId)
            ->whereNull('parent_id')
            ->with(['user:id,first_name,last_name,avatar', 'replies.user:id,first_name,last_name,avatar'])
            ->recent()
            ->get()
            ->map(fn (PostComment $comment) => $this->payload($comment, $userId))
            ->values();

        return response()->json(['success' => true, 'data' => $comments]);
    }

    /** Créer un commentaire ou une réponse. */
    public function store(Request $request, int $postId): JsonResponse
    {
        if (!Post::whereKey($postId)->exists()) {
            return $this->notFound(__('posts.not_found'));
        }

        if ($error = $this->validateInput($request, [
            'content' => 'required|string|max:' . self::MAX_CONTENT_LENGTH,
            'is_anonymous' => 'sometimes|boolean',
            'parent_id' => 'nullable|integer|exists:post_comments,id',
        ])) {
            return $error;
        }

        $parentId = null;
        if ($request->filled('parent_id')) {
            $parent = PostComment::find($request->integer('parent_id'));
            if ((int) $parent->post_id !== $postId) {
                return response()->json([
                    'success' => false,
                    'message' => __('posts.comment_parent_mismatch'),
                ], 422);
            }
            // Un seul niveau de réponses : répondre à une réponse la rattache au fil.
            $parentId = $parent->parent_id ?? $parent->id;
        }

        $content = trim((string) $request->input('content'));
        if ($content === '') {
            return response()->json(['success' => false, 'message' => __('posts.comment_required')], 422);
        }

        $comment = PostComment::create([
            'post_id' => $postId,
            'user_id' => Auth::id(),
            'parent_id' => $parentId,
            'content' => $content,
            'is_anonymous' => $request->boolean('is_anonymous'),
        ])->load('user:id,first_name,last_name,avatar');

        return response()->json([
            'success' => true,
            'message' => __('posts.comment_added'),
            'data' => $this->payload($comment, Auth::id()),
            'comments_count' => (int) Post::whereKey($postId)->value('comments_count'),
        ], 201);
    }

    /** Modifier un commentaire (auteur uniquement). */
    public function update(Request $request, int $postId, int $commentId): JsonResponse
    {
        $comment = $this->findComment($postId, $commentId);
        if (!$comment) {
            return $this->notFound();
        }
        if ((int) $comment->user_id !== (int) Auth::id()) {
            return response()->json(['success' => false, 'message' => __('common.unauthorized')], 403);
        }
        if ($error = $this->validateInput($request, ['content' => 'required|string|max:' . self::MAX_CONTENT_LENGTH])) {
            return $error;
        }

        $comment->update(['content' => trim((string) $request->input('content'))]);

        return response()->json([
            'success' => true,
            'message' => __('posts.comment_updated'),
            'data' => $this->payload($comment->load('user:id,first_name,last_name,avatar', 'replies.user'), Auth::id()),
        ]);
    }

    /** Supprimer un commentaire (auteur uniquement) et ses réponses. */
    public function destroy(int $postId, int $commentId): JsonResponse
    {
        $comment = $this->findComment($postId, $commentId);
        if (!$comment) {
            return $this->notFound();
        }
        if ((int) $comment->user_id !== (int) Auth::id()) {
            return response()->json(['success' => false, 'message' => __('common.unauthorized')], 403);
        }

        $comment->deleteWithReplies();

        return response()->json([
            'success' => true,
            'message' => __('posts.comment_deleted'),
            'comments_count' => (int) Post::whereKey($postId)->value('comments_count'),
        ]);
    }

    /** Liker / retirer son like d'un commentaire (bascule). */
    public function react(int $postId, int $commentId): JsonResponse
    {
        $comment = $this->findComment($postId, $commentId);
        if (!$comment) {
            return $this->notFound();
        }

        $userId = (int) Auth::id();
        DB::transaction(function () use ($comment, $userId) {
            $existing = PostLike::where('user_id', $userId)
                ->where('likeable_id', $comment->id)
                ->where('likeable_type', PostComment::class)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $existing->delete();
            } else {
                PostLike::create([
                    'user_id' => $userId,
                    'likeable_id' => $comment->id,
                    'likeable_type' => PostComment::class,
                    'type' => 'like',
                ]);
            }
        });

        return $this->reactionResponse($comment, $userId);
    }

    /** Retirer son like d'un commentaire. */
    public function unreact(int $postId, int $commentId): JsonResponse
    {
        $comment = $this->findComment($postId, $commentId);
        if (!$comment) {
            return $this->notFound();
        }

        $userId = (int) Auth::id();
        PostLike::where('user_id', $userId)
            ->where('likeable_id', $comment->id)
            ->where('likeable_type', PostComment::class)
            ->first()
            ?->delete();

        return $this->reactionResponse($comment, $userId);
    }

    private function reactionResponse(PostComment $comment, int $userId): JsonResponse
    {
        $comment->refresh();

        return response()->json([
            'success' => true,
            'data' => [
                'likes_count' => (int) $comment->likes_count,
                'user_reaction' => $comment->getUserReaction($userId),
            ],
        ]);
    }
}
