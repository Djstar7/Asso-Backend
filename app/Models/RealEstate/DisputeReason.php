<?php

namespace App\Models\RealEstate;

use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Motif de litige immobilier, réglé dans le back-office. */
class DisputeReason extends Model
{
    use HasTranslations;

    protected $table = 'real_estate_dispute_reasons';

    protected array $translatable = ['label'];

    protected $fillable = ['kind', 'label', 'position', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function scopeFor(Builder $q, string $kind): Builder
    {
        return $q->where('is_active', true)->whereIn('kind', [$kind, 'all'])->orderBy('position')->orderBy('id');
    }
}
