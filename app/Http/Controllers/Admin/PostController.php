<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Post;
use Illuminate\Http\Request;

class PostController extends Controller
{
    /**
     * Display a listing of the posts.
     */
    public function index(Request $request)
    {
        $query = Post::with(['user', 'comments'])
            ->withCount(['likes', 'dislikes', 'comments']);

        // Filter by search
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('content', 'like', "%{$search}%")
                    ->orWhereHas('user', function ($userQuery) use ($search) {
                        $userQuery->where('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%")
                            ->orWhere('phone', 'like', "%{$search}%");
                    });
            });
        }

        // Filter by anonymous
        if ($request->filled('type')) {
            if ($request->type === 'anonymous') {
                $query->where('is_anonymous', true);
            } elseif ($request->type === 'public') {
                $query->where('is_anonymous', false);
            }
        }

        // Order by
        $orderBy = $request->get('order_by', 'recent');
        if ($orderBy === 'popular') {
            $query->orderByDesc('likes_count');
        } elseif ($orderBy === 'commented') {
            $query->orderByDesc('comments_count');
        } else {
            $query->orderByDesc('created_at');
        }

        $posts = $query->paginate(20);

        // Stats
        $stats = [
            'total' => Post::count(),
            'anonymous' => Post::where('is_anonymous', true)->count(),
            'public' => Post::where('is_anonymous', false)->count(),
            'today' => Post::whereDate('created_at', today())->count(),
        ];

        return view('admin.diaspo.posts.index', compact('posts', 'stats'));
    }

    /**
     * Display the specified post.
     */
    public function show(Post $post)
    {
        $post->load(['user', 'comments.user', 'comments.replies.user', 'likes', 'dislikes']);

        return view('admin.diaspo.posts.show', compact('post'));
    }

    /**
     * Remove the specified post from storage.
     */
    public function destroy(Post $post)
    {
        try {
            // Delete all comments
            $post->allComments()->delete();

            // Delete all reactions
            $post->reactions()->delete();

            // Delete the post
            $post->delete();

            return redirect()
                ->route('admin.diaspo.posts.index')
                ->with('success', 'Le post a été supprimé avec succès.');
        } catch (\Exception $e) {
            return redirect()
                ->back()
                ->with('error', 'Erreur lors de la suppression du post: ' . $e->getMessage());
        }
    }

    /**
     * Delete a comment from a post
     */
    public function deleteComment(Post $post, $commentId)
    {
        try {
            $comment = $post->allComments()->findOrFail($commentId);

            // Delete all replies if any
            if ($comment->replies) {
                $comment->replies()->delete();
            }

            // Delete the comment
            $comment->delete();

            // Update post comments count
            $post->decrement('comments_count');

            return redirect()
                ->back()
                ->with('success', 'Le commentaire a été supprimé avec succès.');
        } catch (\Exception $e) {
            return redirect()
                ->back()
                ->with('error', 'Erreur lors de la suppression du commentaire: ' . $e->getMessage());
        }
    }
}
