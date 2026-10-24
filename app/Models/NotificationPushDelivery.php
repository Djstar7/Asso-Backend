<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationPushDelivery extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['next_attempt_at' => 'datetime', 'accepted_at' => 'datetime', 'attempts' => 'integer'];

    public function notification()
    {
        return $this->belongsTo(Notification::class);
    }

    public function device()
    {
        return $this->belongsTo(DeviceToken::class, 'device_token_id');
    }
}
