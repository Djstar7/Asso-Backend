<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Conversation extends Model
{
    use HasFactory;

    protected $fillable = [
        'user1_id',
        'user2_id',
        'product_id',
        'diaspo_offer_id',
        'last_message_at',
    ];

    protected $casts = [
        'last_message_at' => 'datetime',
    ];

    /**
     * Premier utilisateur
     */
    public function user1(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user1_id');
    }

    /**
     * Deuxième utilisateur
     */
    public function user2(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user2_id');
    }

    /**
     * Produit concerné
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Offre Diaspo concernée
     */
    public function diaspoOffer(): BelongsTo
    {
        return $this->belongsTo(DiaspoOffer::class);
    }

    /**
     * Messages de la conversation
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /**
     * Utilisateurs qui ont caché cette conversation
     */
    public function hides(): HasMany
    {
        return $this->hasMany(ConversationHide::class);
    }

    /**
     * Dernier message
     */
    public function latestMessage()
    {
        return $this->hasOne(Message::class)->latestOfMany();
    }

    /**
     * Nombre de messages non lus
     */
    public function unreadCount(): int
    {
        return $this->messages()->where('is_read', false)->count();
    }

    /**
     * Obtenir l'autre participant de la conversation
     */
    public function getOtherUser($currentUserId)
    {
        return $this->user1_id == $currentUserId ? $this->user2 : $this->user1;
    }

    /**
     * Scope pour les conversations récentes
     */
    public function scopeRecent($query)
    {
        return $query->orderBy('last_message_at', 'desc');
    }

    /**
     * Scope pour les conversations avec messages non lus
     */
    public function scopeWithUnread($query)
    {
        return $query->whereHas('messages', function($q) {
            $q->where('is_read', false);
        });
    }

    /**
     * Scope pour exclure les conversations cachées par un utilisateur
     * Une conversation est considérée comme cachée SI:
     * - L'utilisateur l'a cachée ET
     * - Il n'y a PAS de nouveau message après la date du hide
     */
    public function scopeNotHiddenBy($query, $userId)
    {
        return $query->where(function($q) use ($userId) {
            // Soit pas de hide du tout
            $q->whereDoesntHave('hides', function($hideQuery) use ($userId) {
                $hideQuery->where('user_id', $userId);
            })
            // Soit hide mais avec un nouveau message après
            ->orWhere(function($orQuery) use ($userId) {
                $orQuery->whereHas('hides', function($hideQuery) use ($userId) {
                    $hideQuery->where('user_id', $userId);
                })
                ->whereHas('messages', function($msgQuery) use ($userId) {
                    // Il y a au moins un message créé après le hide
                    $msgQuery->whereRaw('created_at > (SELECT created_at FROM conversation_hides WHERE conversation_id = conversations.id AND user_id = ?)', [$userId]);
                });
            });
        });
    }

    /**
     * Vérifier si une conversation est cachée par un utilisateur
     */
    public function isHiddenBy($userId): bool
    {
        return $this->hides()->where('user_id', $userId)->exists();
    }

    /**
     * Cacher la conversation pour un utilisateur
     * Si une entrée existe déjà, on la supprime et on en crée une nouvelle
     * pour avoir la bonne date de hide (created_at)
     */
    public function hideFor($userId): void
    {
        // Supprimer l'ancienne entrée si elle existe
        ConversationHide::where('conversation_id', $this->id)
            ->where('user_id', $userId)
            ->delete();

        // Créer une nouvelle entrée avec la date actuelle
        ConversationHide::create([
            'conversation_id' => $this->id,
            'user_id' => $userId,
        ]);
    }

    /**
     * Afficher la conversation pour un utilisateur (annuler le hide)
     * NOTE: On ne supprime plus l'entrée de hide, on la garde pour filtrer les anciens messages
     * La conversation sera automatiquement réaffichée dans la liste quand il y a un nouveau message
     */
    public function unhideFor($userId): void
    {
        // Ne rien faire - on garde l'entrée de hide pour filtrer les anciens messages
        // Le scope notHiddenBy() réaffichera automatiquement la conversation
        // quand il y aura un nouveau message après la date du hide
    }
}
