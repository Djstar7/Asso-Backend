<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class PostLike extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'likeable_id',
        'likeable_type',
        'type',
    ];

    protected $casts = [
        'type' => 'string',
    ];

    /**
     * Relations
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function likeable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Boot method - Auto increment/decrement counters
     */
    protected static function booted()
    {
        static::created(function ($like) {
            if ($like->type === 'like') {
                $like->likeable->incrementLikes();
            } else {
                $like->likeable->incrementDislikes();
            }
        });

        static::deleted(function ($like) {
            if ($like->type === 'like') {
                $like->likeable->decrementLikes();
            } else {
                $like->likeable->decrementDislikes();
            }
        });

        static::updated(function ($like) {
            // Si le type change (like -> dislike ou vice-versa)
            if ($like->isDirty('type')) {
                $oldType = $like->getOriginal('type');
                $newType = $like->type;

                if ($oldType === 'like' && $newType === 'dislike') {
                    $like->likeable->decrementLikes();
                    $like->likeable->incrementDislikes();
                } elseif ($oldType === 'dislike' && $newType === 'like') {
                    $like->likeable->decrementDislikes();
                    $like->likeable->incrementLikes();
                }
            }
        });
    }
}
