<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** Alerte reçue d'un fournisseur (journal et dédoublonnage par message_id). */
class SupplierWebhookEvent extends Model
{
    public const PENDING = 'pending';

    public const PROCESSED = 'processed';

    public const IGNORED = 'ignored';

    public const FAILED = 'failed';

    public const STATUS_LABELS = [
        self::PENDING => 'En attente',
        self::PROCESSED => 'Traitée',
        self::IGNORED => 'Ignorée',
        self::FAILED => 'En erreur',
    ];

    protected $fillable = [
        'supplier_id', 'message_id', 'type', 'message_type', 'signature_verified',
        'payload', 'status', 'note', 'processed_at',
    ];

    protected $casts = [
        'signature_verified' => 'boolean',
        'payload' => 'array',
        'processed_at' => 'datetime',
    ];

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    public function finish(string $status, ?string $note = null): void
    {
        $this->forceFill([
            'status' => $status,
            'note' => $note !== null ? Str::limit($note, 500) : null,
            'processed_at' => now(),
        ])->save();
    }
}
