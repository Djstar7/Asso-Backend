<?php

namespace App\Models\RealEstate;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Pièce de la visite pièce par pièce (entrée, salon, chambre…). */
class Room extends Model
{
    protected $table = 'real_estate_rooms';

    public const KINDS = ['entrance', 'living', 'bedroom', 'kitchen', 'bathroom', 'outside', 'road', 'other'];

    protected $fillable = ['listing_id', 'kind', 'label', 'position'];

    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    public function media(): HasMany
    {
        return $this->hasMany(Media::class, 'room_id')->orderBy('position');
    }
}
