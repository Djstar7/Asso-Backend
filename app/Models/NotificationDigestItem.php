<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationDigestItem extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['texts' => 'array', 'data' => 'array'];
}
