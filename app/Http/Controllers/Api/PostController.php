<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Post;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Community feed ("MyVoice") — FAKE/stub implementation for testing.
 * Reads return well-shaped fake data; writes echo back a fabricated resource
 * so the mobile optimistic UI works. No persistence.
 */
class PostController extends Controller
{
    /** Convert a persisted post to the mobile API shape. */
    private function postPayload(Post $post, ?int $currentUserId): array
    {
        $isMine = $currentUserId !== null && $post->user_id === $currentUserId;
        $isAnonymous = (bool) $post->is_anonymous;

        return [
            'id' => $post->id,
            'user_id' => $isAnonymous && !$isMine ? null : $post->user_id,
            'content' => $post->content,
            'is_anonymous' => $isAnonymous,
            'likes_count' => (int) $post->likes_count,
            'dislikes_count' => (int) $post->dislikes_count,
            'comments_count' => (int) $post->comments_count,
            'user_reaction' => $post->getUserReaction($currentUserId),
            'is_liked' => $post->isLikedByUser($currentUserId),
            'is_disliked' => $post->isDislikedByUser($currentUserId),
            'is_my_post' => $isMine,
            'created_at' => $post->created_at?->toIso8601String(),
            'updated_at' => $post->updated_at?->toIso8601String(),
            'user' => $isAnonymous && !$isMine
                ? null
                : ($post->user ? [
                    'id' => $post->user->id,
                    'first_name' => $post->user->first_name,
                    'last_name' => $post->user->last_name,
                    'avatar' => $post->user->avatar,
                ] : null),
        ];
    }

    /** Build a fake post matching the mobile Post.fromJson shape. */
    private function fakePost(int $id, ?int $currentUserId = null): array
    {
        $samples = [
            "Quelqu'un connaît un bon vendeur de tissus wax à Cotonou ? 🙏",
            "Merci à la communauté ASSO, j'ai reçu ma commande en 2 jours ! 🚀",
            "Astuce : pensez à vérifier les avis avant de commander chez un nouveau vendeur.",
            "Je propose mes services de livraison sur Calavi, contactez-moi en privé.",
            "Trop content de la nouvelle fonctionnalité diaspo, ça va aider ma famille ✈️",
        ];
        $content = $samples[$id % count($samples)];
        $anonymous = ($id % 3 === 0);

        return [
            'id' => $id,
            'user_id' => $anonymous ? null : (100 + $id),
            'content' => $content,
            'is_anonymous' => $anonymous,
            'likes_count' => (7 * $id) % 25,
            'dislikes_count' => $id % 3,
            'comments_count' => $id % 5,
            'user_reaction' => null,
            'is_liked' => false,
            'is_disliked' => false,
            'is_my_post' => false,
            'created_at' => Carbon::now()->subHours($id)->toIso8601String(),
            'updated_at' => Carbon::now()->subHours($id)->toIso8601String(),
            'user' => $anonymous ? null : [
                'id' => 100 + $id,
                'first_name' => 'Membre',
                'last_name' => '#' . $id,
                'avatar' => null,
            ],
        ];
    }

    /** Build a fake comment matching PostComment.fromJson shape. */
    private function fakeComment(int $id, int $postId, string $content, bool $anonymous = false): array
    {
        return [
            'id' => $id,
            'post_id' => $postId,
            'user_id' => $anonymous ? null : (200 + $id),
            'parent_id' => null,
            'content' => $content,
            'is_anonymous' => $anonymous,
            'likes_count' => $id % 4,
            'user_reaction' => null,
            'is_liked' => false,
            'is_my_comment' => false,
            'created_at' => Carbon::now()->subMinutes($id * 5)->toIso8601String(),
            'updated_at' => Carbon::now()->subMinutes($id * 5)->toIso8601String(),
            'user' => $anonymous ? null : [
                'id' => 200 + $id,
                'first_name' => 'Membre',
                'last_name' => '#' . $id,
                'avatar' => null,
            ],
            'replies' => [],
        ];
    }

    /** Wrap a list of items into a Laravel-paginator-like envelope. */
    private function paginate(array $items, int $page, int $perPage): array
    {
        return [
            'current_page' => $page,
            'data' => $items,
            'per_page' => $perPage,
            'last_page' => 1,
            'total' => count($items),
            'next_page_url' => null,
            'prev_page_url' => null,
        ];
    }

    /** GET /v1/posts */
    public function index(Request $request)
    {
        $page = (int) $request->query('page', 1);
        $perPage = (int) $request->query('per_page', 20);

        $query = Post::query()->with('user');
        if ($request->query('sort', 'recent') === 'popular') {
            $query->orderByDesc('likes_count');
        } else {
            $query->latest();
        }

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);
        $posts = $paginator->getCollection()
            ->map(fn(Post $post) => $this->postPayload($post, $request->user()?->id))
            ->values()
            ->all();

        return response()->json([
            'success' => true,
            'data' => array_merge($paginator->toArray(), ['data' => $posts]),
        ]);
    }

    /** GET /v1/posts/my-posts */
    public function myPosts(Request $request)
    {
        return response()->json([
            'success' => true,
            'data' => $this->paginate([], (int) $request->query('page', 1), (int) $request->query('per_page', 20)),
        ]);
    }

    /** GET /v1/posts/{id} */
    public function show(Request $request, $id)
    {
        $post = Post::with('user')->find($id);

        if (!$post) {
            return response()->json([
                'success' => false,
                'message' => 'Post introuvable',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $this->postPayload($post, $request->user()?->id),
        ]);
    }

    /** POST /v1/posts */
    public function store(Request $request)
    {
        $request->validate(['content' => 'required|string']);
        $post = Post::create([
            'user_id' => $request->user()->id,
            'content' => $request->input('content'),
            'is_anonymous' => (bool) $request->input('is_anonymous', false),
        ])->load('user');

        return response()->json([
            'success' => true,
            'message' => 'Publication créée',
            'data' => $this->postPayload($post, $request->user()->id),
        ], 201);
    }

    /** PUT /v1/posts/{id} */
    public function update(Request $request, $id)
    {
        $request->validate(['content' => 'required|string']);
        $post = $this->fakePost((int) $id);
        $post['content'] = $request->input('content');
        $post['is_my_post'] = true;

        return response()->json(['success' => true, 'message' => 'Publication mise à jour', 'data' => $post]);
    }

    /** DELETE /v1/posts/{id} */
    public function destroy($id)
    {
        return response()->json(['success' => true, 'message' => 'Publication supprimée']);
    }

    /** POST /v1/posts/{id}/react */
    public function react(Request $request, $id)
    {
        $type = $request->input('type', 'like'); // like | dislike
        return response()->json([
            'success' => true,
            'data' => [
                'likes_count' => $type === 'like' ? 1 : 0,
                'dislikes_count' => $type === 'dislike' ? 1 : 0,
                'user_reaction' => $type,
            ],
        ]);
    }

    /** DELETE /v1/posts/{id}/react */
    public function unreact($id)
    {
        return response()->json([
            'success' => true,
            'data' => ['likes_count' => 0, 'dislikes_count' => 0, 'user_reaction' => null],
        ]);
    }

    // ==================== COMMENTS ====================

    /** GET /v1/posts/{postId}/comments */
    public function comments($postId)
    {
        $list = [
            $this->fakeComment(1, (int) $postId, 'Bonne question, je suis preneur aussi !'),
            $this->fakeComment(2, (int) $postId, 'Regarde au marché Dantokpa, il y a du choix.', true),
        ];

        return response()->json(['success' => true, 'data' => $list]);
    }

    /** POST /v1/posts/{postId}/comments */
    public function storeComment(Request $request, $postId)
    {
        $request->validate(['content' => 'required|string']);
        $comment = $this->fakeComment(random_int(1000, 9999), (int) $postId, $request->input('content'), (bool) $request->input('is_anonymous', false));
        $comment['is_my_comment'] = true;
        $comment['parent_id'] = $request->input('parent_id');

        return response()->json(['success' => true, 'message' => 'Commentaire ajouté', 'data' => $comment], 201);
    }

    /** PUT /v1/posts/{postId}/comments/{commentId} */
    public function updateComment(Request $request, $postId, $commentId)
    {
        $request->validate(['content' => 'required|string']);
        $comment = $this->fakeComment((int) $commentId, (int) $postId, $request->input('content'));
        $comment['is_my_comment'] = true;

        return response()->json(['success' => true, 'message' => 'Commentaire mis à jour', 'data' => $comment]);
    }

    /** DELETE /v1/posts/{postId}/comments/{commentId} */
    public function destroyComment($postId, $commentId)
    {
        return response()->json(['success' => true, 'message' => 'Commentaire supprimé']);
    }

    /** POST /v1/posts/{postId}/comments/{commentId}/react */
    public function reactComment($postId, $commentId)
    {
        return response()->json(['success' => true, 'data' => ['likes_count' => 1, 'user_reaction' => 'like']]);
    }

    /** DELETE /v1/posts/{postId}/comments/{commentId}/react */
    public function unreactComment($postId, $commentId)
    {
        return response()->json(['success' => true, 'data' => ['likes_count' => 0, 'user_reaction' => null]]);
    }
}
