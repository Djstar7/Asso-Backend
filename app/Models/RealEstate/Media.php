<?php

namespace App\Models\RealEstate;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Media extends Model
{
    protected $table = 'real_estate_media';

    protected $fillable = ['listing_id', 'room_id', 'type', 'path', 'poster_path', 'position', 'is_cover'];

    protected $casts = ['is_cover' => 'boolean'];

    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'url' => media_url($this->path),
            'poster_url' => media_url($this->poster_path),
            'room_id' => $this->room_id,
            'is_cover' => (bool) $this->is_cover,
        ];
    }
}
