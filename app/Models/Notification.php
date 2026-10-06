<?php

namespace App\Models;

use App\Support\Translation\LocalizedText;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Notification extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'title',
        'body',
        'type',
        'data',
        'is_read',
        'is_sent',
        'read_at',
        'sent_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'data' => 'array',
        'is_read' => 'boolean',
        'is_sent' => 'boolean',
        'read_at' => 'datetime',
        'sent_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Ajoute à `data` la clé et les valeurs des textes traduisibles
     * (data.i18n.title / data.i18n.body).
     */
    public static function withI18n(array $data, string|LocalizedText $title, string|LocalizedText $body): array
    {
        $i18n = array_filter([
            'title' => $title instanceof LocalizedText ? $title->toArray() : null,
            'body' => $body instanceof LocalizedText ? $body->toArray() : null,
        ]);

        return $i18n ? $data + ['i18n' => $i18n] : $data;
    }

    /** Titre dans la langue de la requête quand sa clé est connue, sinon le texte enregistré. */
    public function getTitleAttribute(?string $value): ?string
    {
        return LocalizedText::renderStored($this->data['i18n']['title'] ?? null, $value);
    }

    /** Texte dans la langue de la requête quand sa clé est connue, sinon le texte enregistré. */
    public function getBodyAttribute(?string $value): ?string
    {
        return LocalizedText::renderStored($this->data['i18n']['body'] ?? null, $value);
    }

    /**
     * Get the user that owns the notification.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope a query to only include unread notifications.
     */
    public function scopeUnread($query)
    {
        return $query->where('is_read', false);
    }

    /**
     * Scope a query to only include read notifications.
     */
    public function scopeRead($query)
    {
        return $query->where('is_read', true);
    }

    /**
     * Scope a query to filter by type.
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }

    /**
     * Mark this notification as read.
     */
    public function markAsRead(): void
    {
        if (!$this->is_read) {
            $this->update([
                'is_read' => true,
                'read_at' => now(),
            ]);
        }
    }

    /**
     * Mark this notification as unread.
     */
    public function markAsUnread(): void
    {
        $this->update([
            'is_read' => false,
            'read_at' => null,
        ]);
    }
}
