<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConversationHide extends Model
{
    protected $fillable = [
        'conversation_id',
        'user_id',
    ];

    /**
     * Conversation cachée
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * Utilisateur qui a caché la conversation
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
