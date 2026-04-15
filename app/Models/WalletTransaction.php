<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Carbon\Carbon;

/**
 * Transaction du wallet utilisateur
 *
 * Enregistre tous les mouvements d'argent dans le wallet:
 * - Recharges (credit)
 * - Paiements (debit)
 * - Remboursements (refund)
 * - Bonus (bonus)
 * - Ajustements admin (adjustment)
 *
 * @property int $id
 * @property int $user_id
 * @property string $type (credit|debit|refund|bonus|adjustment|lock|unlock|escrow_release)
 * @property float $amount
 * @property float $balance_before
 * @property float $balance_after
 * @property string $description
 * @property string|null $reference_type
 * @property int|null $reference_id
 * @property int|null $payment_id
 * @property array|null $metadata
 * @property string $status
 * @property string $provider (freemopay|paypal)
 * @property int|null $admin_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon|null $deleted_at
 */
class WalletTransaction extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'type',
        'amount',
        'balance_before',
        'balance_after',
        'description',
        'reference_type',
        'reference_id',
        'payment_id',
        'metadata',
        'status',
        'admin_id',
        'provider', // freemopay, paypal
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'balance_before' => 'decimal:2',
            'balance_after' => 'decimal:2',
            'metadata' => 'array',
        ];
    }

    /**
     * Utilisateur propriétaire du wallet
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Transaction source (pour les recharges)
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'payment_id');
    }

    /**
     * Admin ayant effectué l'ajustement
     */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    /**
     * Vérifie si c'est un crédit (ajout d'argent)
     */
    public function isCredit(): bool
    {
        return in_array($this->type, ['credit', 'refund', 'bonus', 'adjustment']) && $this->amount > 0;
    }

    /**
     * Vérifie si c'est un débit (retrait d'argent)
     */
    public function isDebit(): bool
    {
        return in_array($this->type, ['debit', 'escrow_release']) || ($this->type === 'adjustment' && $this->amount < 0);
    }

    /**
     * Vérifie si c'est une opération escrow
     */
    public function isEscrow(): bool
    {
        return in_array($this->type, ['lock', 'unlock', 'escrow_release']);
    }

    /**
     * Retourne le montant formaté
     */
    public function getFormattedAmountAttribute(): string
    {
        return number_format(abs($this->amount), 0, ',', ' ') . ' FCFA';
    }

    /**
     * Retourne le signe pour l'affichage
     */
    public function getAmountSignAttribute(): string
    {
        return $this->isCredit() ? '+' : '-';
    }

    /**
     * Retourne la couleur selon le type
     */
    public function getTypeColorAttribute(): string
    {
        return match($this->type) {
            'credit' => 'success',
            'debit' => 'danger',
            'refund' => 'info',
            'bonus' => 'warning',
            'adjustment' => 'secondary',
            'lock' => 'warning',
            'unlock' => 'info',
            'escrow_release' => 'danger',
            default => 'dark',
        };
    }

    /**
     * Retourne le label du type
     */
    public function getTypeLabelAttribute(): string
    {
        return match($this->type) {
            'credit' => 'Recharge',
            'debit' => 'Paiement',
            'refund' => 'Remboursement',
            'bonus' => 'Bonus',
            'adjustment' => 'Ajustement',
            'lock' => 'Blocage escrow',
            'unlock' => 'Déblocage escrow',
            'escrow_release' => 'Libération escrow',
            default => ucfirst($this->type),
        };
    }

    /**
     * Retourne l'icône du type
     */
    public function getTypeIconAttribute(): string
    {
        return match($this->type) {
            'credit' => 'fas fa-plus-circle',
            'debit' => 'fas fa-minus-circle',
            'refund' => 'fas fa-undo',
            'bonus' => 'fas fa-gift',
            'adjustment' => 'fas fa-wrench',
            'lock' => 'fas fa-lock',
            'unlock' => 'fas fa-unlock',
            'escrow_release' => 'fas fa-hand-holding-usd',
            default => 'fas fa-exchange-alt',
        };
    }

    /**
     * Scope: Transactions d'un user
     */
    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope: Crédits uniquement
     */
    public function scopeCredits($query)
    {
        return $query->whereIn('type', ['credit', 'refund', 'bonus'])
            ->orWhere(function ($q) {
                $q->where('type', 'adjustment')->where('amount', '>', 0);
            });
    }

    /**
     * Scope: Débits uniquement
     */
    public function scopeDebits($query)
    {
        return $query->where('type', 'debit')
            ->orWhere(function ($q) {
                $q->where('type', 'adjustment')->where('amount', '<', 0);
            });
    }

    /**
     * Scope: Transactions complétées
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    /**
     * Scope: Récent en premier
     */
    public function scopeRecent($query)
    {
        return $query->orderBy('created_at', 'desc');
    }

    /**
     * Scope: Par type
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }

    /**
     * Scope: Par provider
     */
    public function scopeByProvider($query, string $provider)
    {
        return $query->where('provider', $provider);
    }
}
