<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeliveryTerritory extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['level' => 'integer', 'latitude' => 'float', 'longitude' => 'float', 'metadata' => 'array'];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_code', 'code');
    }
}
