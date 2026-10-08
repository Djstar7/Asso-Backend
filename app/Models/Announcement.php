<?php

namespace App\Models;

use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Announcement extends Model
{
    use HasFactory, HasTranslations;

    /** Champs traduisibles (voir HasTranslations). */
    protected array $translatable = ['title', 'message'];

    protected $fillable = [
        'title',
        'message',
        'channel',
        'target_type',
        'user_id',
        'status',
        'scheduled_at',
        'sent_at',
        'sent_count',
        'failed_count',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'sent_at' => 'datetime',
        'sent_count' => 'integer',
        'failed_count' => 'integer',
    ];

    /**
     * Relation avec l'utilisateur cible (si target_type = specific)
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope pour les annonces envoyées
     */
    public function scopeSent($query)
    {
        return $query->where('status', 'sent');
    }

    /**
     * Scope pour les brouillons
     */
    public function scopeDraft($query)
    {
        return $query->where('status', 'draft');
    }

    /**
     * Scope pour les annonces programmées
     */
    public function scopeScheduled($query)
    {
        return $query->where('status', 'scheduled');
    }

    /**
     * Scope pour un canal spécifique
     */
    public function scopeByChannel($query, $channel)
    {
        return $query->where('channel', $channel);
    }

    /**
     * Scope pour les annonces à tous les utilisateurs
     */
    public function scopeToAll($query)
    {
        return $query->where('target_type', 'all');
    }

    /**
     * Scope pour les annonces à un utilisateur spécifique
     */
    public function scopeToSpecific($query)
    {
        return $query->where('target_type', 'specific');
    }

    /**
     * Obtenir le libellé du canal
     */
    public function getChannelLabelAttribute(): string
    {
        return match($this->channel) {
            'sms' => 'SMS',
            'whatsapp' => 'WhatsApp',
            'email' => 'Email',
            'push' => 'Push Notification',
            default => $this->channel,
        };
    }

    /**
     * Obtenir le libellé du statut
     */
    public function getStatusLabelAttribute(): string
    {
        return match($this->status) {
            'draft' => 'Brouillon',
            'sent' => 'Envoyé',
            'scheduled' => 'Programmé',
            default => $this->status,
        };
    }

    /**
     * Obtenir le libellé du type de cible
     */
    public function getTargetLabelAttribute(): string
    {
        return match($this->target_type) {
            'all' => 'Tous les utilisateurs',
            'specific' => 'Utilisateur spécifique',
            default => $this->target_type,
        };
    }

    /**
     * Obtenir l'icône du canal
     */
    public function getChannelIconAttribute(): string
    {
        return match($this->channel) {
            'sms' => 'fa-sms',
            'whatsapp' => 'fa-whatsapp',
            'email' => 'fa-envelope',
            'push' => 'fa-bell',
            default => 'fa-paper-plane',
        };
    }

    /**
     * Obtenir la couleur du canal
     */
    public function getChannelColorAttribute(): string
    {
        return match($this->channel) {
            'sms' => 'blue',
            'whatsapp' => 'green',
            'email' => 'purple',
            'push' => 'orange',
            default => 'gray',
        };
    }
}
