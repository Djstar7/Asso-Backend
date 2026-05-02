<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Post extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'content',
        'is_anonymous',
        'likes_count',
        'dislikes_count',
        'comments_count',
    ];

    protected $casts = [
        'is_anonymous' => 'boolean',
        'likes_count' => 'integer',
        'dislikes_count' => 'integer',
        'comments_count' => 'integer',
    ];

    protected $with = [];

    /**
     * Relations
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(PostComment::class)->whereNull('parent_id');
    }

    public function allComments(): HasMany
    {
        return $this->hasMany(PostComment::class);
    }

    public function likes(): MorphMany
    {
        return $this->morphMany(PostLike::class, 'likeable')->where('type', 'like');
    }

    public function dislikes(): MorphMany
    {
        return $this->morphMany(PostLike::class, 'likeable')->where('type', 'dislike');
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

    public function scopePopular($query)
    {
        return $query->orderBy('likes_count', 'desc');
    }

    public function scopePublic($query)
    {
        return $query->where('is_anonymous', false);
    }

    public function scopeAnonymous($query)
    {
        return $query->where('is_anonymous', true);
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

    public function isDislikedByUser(?int $userId = null): bool
    {
        if (!$userId) {
            $userId = auth()->id();
        }

        return $this->dislikes()->where('user_id', $userId)->exists();
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

    public function incrementDislikes()
    {
        $this->increment('dislikes_count');
    }

    public function decrementDislikes()
    {
        $this->decrement('dislikes_count');
    }

    public function incrementComments()
    {
        $this->increment('comments_count');
    }

    public function decrementComments()
    {
        $this->decrement('comments_count');
    }
}
