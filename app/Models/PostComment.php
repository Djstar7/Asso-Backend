<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PostComment extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'post_id',
        'user_id',
        'parent_id',
        'content',
        'is_anonymous',
        'likes_count',
    ];

    protected $casts = [
        'is_anonymous' => 'boolean',
        'likes_count' => 'integer',
    ];

    /**
     * Relations
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(PostComment::class, 'parent_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(PostComment::class, 'parent_id');
    }

    public function likes(): MorphMany
    {
        return $this->morphMany(PostLike::class, 'likeable')->where('type', 'like');
    }

    public function reactions(): MorphMany
    {
        return $this->morphMany(PostLike::class, 'likeable');
    }

    /**
     * Scopes
     */
    public function scopeRecent($query)
    {
        return $query->orderBy('created_at', 'desc');
    }

    public function scopeTopLevel($query)
    {
        return $query->whereNull('parent_id');
    }

    /**
     * Helpers
     */
    public function isLikedByUser(?int $userId = null): bool
    {
        if (!$userId) {
            $userId = auth()->id();
        }

        return $this->likes()->where('user_id', $userId)->exists();
    }

    public function getUserReaction(?int $userId = null): ?string
    {
        if (!$userId) {
            $userId = auth()->id();
        }

        $reaction = $this->reactions()->where('user_id', $userId)->first();
        return $reaction?->type;
    }

    /**
     * Increment/Decrement counters
     */
    public function incrementLikes()
    {
        $this->increment('likes_count');
    }

    public function decrementLikes()
    {
        $this->decrement('likes_count');
    }

    /**
     * Boot method - Auto increment/decrement post comments_count
     */
    protected static function booted()
    {
        static::created(function ($comment) {
            $comment->post->incrementComments();
        });

        static::deleted(function ($comment) {
            $comment->post->decrementComments();
        });
    }
}
