<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Currency extends Model
{
    protected $fillable = [
        'code',
        'name',
        'symbol',
        'countries',
        'is_active',
    ];

    protected $casts = [
        'countries' => 'array',
        'is_active' => 'boolean',
    ];
}
