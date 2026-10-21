<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeliveryAgency extends Model
{
    protected $fillable = ['deliverer_company_id', 'code', 'name', 'city', 'address', 'phone', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(DelivererCompany::class, 'deliverer_company_id');
    }

    public function toPickupArray(): array
    {
        return $this->only(['id', 'code', 'name', 'city', 'address', 'phone']);
    }
}
