<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pièce fournie par un voyageur Diaspo : pièce d'identité (recto/verso),
 * titre de séjour ou visa, selfie, ou justificatif de voyage d'une offre.
 */
class DiaspoIdentityDocument extends Model
{
    public const TYPE_CNI = 'cni';
    public const TYPE_PASSPORT = 'passport';
    public const TYPE_RESIDENCE_PERMIT = 'residence_permit';
    public const TYPE_VISA = 'visa';
    public const TYPE_SELFIE = 'selfie';
    public const TYPE_TRAVEL_PROOF = 'travel_proof';

    public const LABELS = [
        self::TYPE_CNI => 'Carte nationale d\'identité',
        self::TYPE_PASSPORT => 'Passeport',
        self::TYPE_RESIDENCE_PERMIT => 'Titre de séjour',
        self::TYPE_VISA => 'Visa',
        self::TYPE_SELFIE => 'Selfie',
        self::TYPE_TRAVEL_PROOF => 'Justificatif de voyage',
    ];

    protected $fillable = ['user_id', 'type', 'side', 'document_id', 'diaspo_offer_id', 'status'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(DiaspoOffer::class, 'diaspo_offer_id');
    }

    public function label(): string
    {
        return (self::LABELS[$this->type] ?? $this->type) . ($this->side ? ' (' . ($this->side === 'front' ? 'recto' : 'verso') . ')' : '');
    }
}
