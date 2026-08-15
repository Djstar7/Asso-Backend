<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    use HasFactory;

    protected $fillable = [
        'conversation_id',
        'sender_id',
        'message',
        'image_path',
        'is_system',
        'product_id',
        'diaspo_offer_id',
        'is_read',
        'read_at',
    ];

    protected $casts = [
        'is_read' => 'boolean',
        'is_system' => 'boolean',
        'read_at' => 'datetime',
    ];

    /**
     * Conversation parente
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * Expéditeur du message
     */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    /**
     * Produit tagué dans le message (optionnel)
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Offre Diaspo taguée dans le message (optionnel)
     */
    public function diaspoOffer(): BelongsTo
    {
        return $this->belongsTo(DiaspoOffer::class);
    }

    /**
     * Scope pour les messages non lus
     */
    public function scopeUnread($query)
    {
        return $query->where('is_read', false);
    }

    /**
     * Scope pour les messages lus
     */
    public function scopeRead($query)
    {
        return $query->where('is_read', true);
    }

    /**
     * Marquer comme lu
     */
    public function markAsRead()
    {
        $this->update([
            'is_read' => true,
            'read_at' => now(),
        ]);
    }
}
