<?php

namespace App\Models\RealEstate;

use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Model;

/** Atout, équipement ou service, géré dans le back-office. */
class Amenity extends Model
{
    use HasTranslations;

    protected $table = 'real_estate_amenities';

    public const KINDS = ['highlight', 'equipment', 'service'];

    protected array $translatable = ['name'];

    protected $fillable = ['kind', 'code', 'name', 'icon', 'position', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'code' => $this->code,
            'name' => $this->name,
            'icon' => $this->icon,
        ];
    }
}
