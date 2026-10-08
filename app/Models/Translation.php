<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Valeur d'un champ dans une langue autre que le français.
 * Voir App\Models\Concerns\HasTranslations.
 */
class Translation extends Model
{
    protected $fillable = ['locale', 'field', 'value'];

    public function translatable(): MorphTo
    {
        return $this->morphTo();
    }
}
