<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Models\PostLike;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Voice of Customer (« MyVoice ») : publications de la communauté ASSO.
 * Tout est persisté (posts, réactions) ; les commentaires sont servis par
 * PostCommentController.
 */
class PostController extends Controller
{
    public const MAX_CONTENT_LENGTH = 5000;

    /** Convertit un post au format attendu par le mobile (Post.fromJson). */
    public static function payload(Post $post, ?int $currentUserId): array
    {
        $isMine = $currentUserId !== null && (int) $post->user_id === $currentUserId;
        $isAnonymous = (bool) $post->is_anonymous;
        $hideAuthor = $isAnonymous && !$isMine;
        $reaction = $currentUserId ? $post->getUserReaction($currentUserId) : null;

        return [
            'id' => $post->id,
            'user_id' => $hideAuthor ? null : $post->user_id,
            'content' => $post->content,
            'is_anonymous' => $isAnonymous,
            'likes_count' => (int) $post->likes_count,
            'dislikes_count' => (int) $post->dislikes_count,
            'comments_count' => (int) $post->comments_count,
            'user_reaction' => $reaction,
            'is_liked' => $reaction === 'like',
            'is_disliked' => $reaction === 'dislike',
            'is_my_post' => $isMine,
            'created_at' => $post->created_at?->toIso8601String(),
            'updated_at' => $post->updated_at?->toIso8601String(),
            'user' => $hideAuthor || !$post->user ? null : [
                'id' => $post->user->id,
                'first_name' => $post->user->first_name,
                'last_name' => $post->user->last_name,
                'avatar' => $post->user->avatar,
            ],
        ];
    }

    private function paginated(Request $request, $query): JsonResponse
    {
        $perPage = max(1, min(50, (int) $request->query('per_page', 20)));
        $paginator = $query->paginate($perPage)->withQueryString();
        $userId = $request->user()?->id;

        $posts = $paginator->getCollection()
            ->map(fn (Post $post) => self::payload($post, $userId))
            ->values()
            ->all();

        return response()->json([
            'success' => true,
            'data' => array_merge($paginator->toArray(), ['data' => $posts]),
        ]);
    }

    private function notFound(): JsonResponse
    {
        return response()->json(['success' => false, 'message' => __('posts.not_found')], 404);
    }

    private function validationError($validator): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $validator->errors()->first(),
            'errors' => $validator->errors(),
        ], 422);
    }

    private function contentRules(): array
    {
        return ['required', 'string', 'min:2', 'max:' . self::MAX_CONTENT_LENGTH];
    }

    private function messages(): array
    {
        return [
            'content.required' => __('posts.content_required'),
            'content.min' => __('posts.content_too_short'),
            'content.max' => __('posts.content_max', ['max' => self::MAX_CONTENT_LENGTH]),
        ];
    }

    /** GET /v1/posts */
    public function index(Request $request)
    {
        $query = Post::query()->with('user');
        if ($request->query('sort', 'recent') === 'popular') {
            $query->orderByDesc('likes_count')->orderByDesc('comments_count');
        }
        $query->latest()->orderByDesc('id');

        return $this->paginated($request, $query);
    }

    /** GET /v1/posts/my-posts */
    public function myPosts(Request $request)
    {
        $query = Post::query()
            ->with('user')
            ->where('user_id', $request->user()->id)
            ->latest()
            ->orderByDesc('id');

        return $this->paginated($request, $query);
    }

    /** GET /v1/posts/{id} */
    public function show(Request $request, $id)
    {
        $post = Post::with('user')->find($id);
        if (!$post) {
            return $this->notFound();
        }

        return response()->json([
            'success' => true,
            'data' => self::payload($post, $request->user()?->id),
        ]);
    }

    /** POST /v1/posts */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'content' => $this->contentRules(),
            'is_anonymous' => 'sometimes|boolean',
        ], $this->messages());
        if ($validator->fails()) {
            return $this->validationError($validator);
        }

        $post = Post::create([
            'user_id' => $request->user()->id,
            'content' => trim((string) $request->input('content')),
            'is_anonymous' => $request->boolean('is_anonymous'),
        ])->load('user');

        return response()->json([
            'success' => true,
            'message' => __('posts.created'),
            'data' => self::payload($post, $request->user()->id),
        ], 201);
    }

    /** PUT /v1/posts/{id} */
    public function update(Request $request, $id)
    {
        $post = Post::with('user')->find($id);
        if (!$post) {
            return $this->notFound();
        }
        if ((int) $post->user_id !== (int) $request->user()->id) {
            return response()->json(['success' => false, 'message' => __('common.unauthorized')], 403);
        }

        $validator = Validator::make($request->all(), ['content' => $this->contentRules()], $this->messages());
        if ($validator->fails()) {
            return $this->validationError($validator);
        }

        $post->update(['content' => trim((string) $request->input('content'))]);

        return response()->json([
            'success' => true,
            'message' => __('posts.updated'),
            'data' => self::payload($post->fresh('user'), $request->user()->id),
        ]);
    }

    /** DELETE /v1/posts/{id} */
    public function destroy(Request $request, $id)
    {
        $post = Post::find($id);
        if (!$post) {
            return $this->notFound();
        }
        if ((int) $post->user_id !== (int) $request->user()->id) {
            return response()->json(['success' => false, 'message' => __('common.unauthorized')], 403);
        }

        $post->delete();

        return response()->json(['success' => true, 'message' => __('posts.deleted')]);
    }

    /**
     * POST /v1/posts/{id}/react — type = like | dislike.
     * Même réaction une seconde fois = retrait ; réaction opposée = bascule.
     */
    public function react(Request $request, $id)
    {
        $validator = Validator::make($request->all(), ['type' => 'required|in:like,dislike']);
        if ($validator->fails()) {
            return $this->validationError($validator);
        }

        $post = Post::find($id);
        if (!$post) {
            return $this->notFound();
        }

        $userId = (int) $request->user()->id;
        $type = $request->input('type');

        DB::transaction(function () use ($post, $userId, $type) {
            $existing = PostLike::where('user_id', $userId)
                ->where('likeable_id', $post->id)
                ->where('likeable_type', Post::class)
                ->lockForUpdate()
                ->first();

            if (!$existing) {
                PostLike::create([
                    'user_id' => $userId,
                    'likeable_id' => $post->id,
                    'likeable_type' => Post::class,
                    'type' => $type,
                ]);
            } elseif ($existing->type === $type) {
                $existing->delete();
            } else {
                $existing->update(['type' => $type]);
            }
        });

        return $this->reactionResponse($post, $userId);
    }

    /** DELETE /v1/posts/{id}/react */
    public function unreact(Request $request, $id)
    {
        $post = Post::find($id);
        if (!$post) {
            return $this->notFound();
        }

        $userId = (int) $request->user()->id;
        PostLike::where('user_id', $userId)
            ->where('likeable_id', $post->id)
            ->where('likeable_type', Post::class)
            ->first()
            ?->delete();

        return $this->reactionResponse($post, $userId);
    }

    private function reactionResponse(Post $post, int $userId): JsonResponse
    {
        $post->refresh();

        return response()->json([
            'success' => true,
            'data' => [
                'likes_count' => (int) $post->likes_count,
                'dislikes_count' => (int) $post->dislikes_count,
                'user_reaction' => $post->getUserReaction($userId),
            ],
        ]);
    }
}
