<?php

namespace App\Models\RealEstate;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Pièces envoyées par un bailleur pour faire vérifier son profil. */
class LandlordVerification extends Model
{
    public const PROOF_TYPES = ['land_title', 'sale_deed', 'certificate', 'lease_contract', 'other'];

    protected $fillable = [
        'user_id', 'id_document_type', 'id_front_path', 'id_back_path', 'ownership_proof_type',
        'ownership_proof_path', 'video_path', 'status', 'rejection_reason', 'reviewed_by', 'reviewed_at',
    ];

    protected $casts = ['reviewed_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
