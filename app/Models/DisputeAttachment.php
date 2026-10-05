<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Preuve d'un litige : photo du client, élément du vendeur ou pièce ajoutée par ASSO. */
class DisputeAttachment extends Model
{
    protected $fillable = ['dispute_id', 'author_type', 'author_id', 'path'];

    public function dispute(): BelongsTo { return $this->belongsTo(Dispute::class); }

    public function url(): ?string
    {
        return media_url($this->path);
    }

    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'author_type' => $this->author_type,
            'url' => $this->url(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
