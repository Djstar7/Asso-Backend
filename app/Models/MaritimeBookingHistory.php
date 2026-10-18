<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaritimeBookingHistory extends Model
{
    protected $fillable = [
        'maritime_booking_id',
        'event',
        'status',
        'actor_user_id',
        'reason',
        'metadata',
    ];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(MaritimeBooking::class, 'maritime_booking_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
