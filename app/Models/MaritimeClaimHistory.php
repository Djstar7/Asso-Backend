<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaritimeClaimHistory extends Model
{
    protected $fillable = [
        'action',
        'from_status',
        'to_status',
        'actor_user_id',
        'reason',
        'amount_xaf',
        'operation_reference',
    ];

    protected function casts(): array
    {
        return ['amount_xaf' => 'decimal:2'];
    }

    public function claim(): BelongsTo
    {
        return $this->belongsTo(MaritimeIndemnityClaim::class, 'maritime_indemnity_claim_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
