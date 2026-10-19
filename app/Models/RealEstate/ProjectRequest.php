<?php

namespace App\Models\RealEstate;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** « Confier mon projet » : demande transmise à ASSO. */
class ProjectRequest extends Model
{
    protected $table = 'real_estate_project_requests';

    protected $fillable = [
        'user_id', 'has_land', 'city', 'district', 'surface_m2', 'budget',
        'name', 'phone', 'message', 'status',
    ];

    protected $casts = ['has_land' => 'boolean'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
