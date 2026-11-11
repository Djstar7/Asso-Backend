<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'reference',
        'transaction_id',
        'buyer_id',
        'seller_id',
        'product_id',
        'amount',
        'fees',
        'net_amount',
        'currency',
        'status',
        'type',
        'payment_method',
        'external_reference',
        'description',
        'metadata',
        'payer_email',
        'payer_name',
        'completed_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'fees' => 'decimal:2',
        'net_amount' => 'decimal:2',
        'metadata' => 'array',
        'completed_at' => 'datetime',
    ];

    /**
     * Relation avec l'acheteur
     */
    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    /**
     * Relation avec le vendeur
     */
    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    /**
     * Relation avec le produit
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    /**
     * Scope pour les transactions complétées
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    /**
     * Scope pour les transactions en attente
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope pour les transactions annulées
     */
    public function scopeCancelled($query)
    {
        return $query->where('status', 'cancelled');
    }

    /**
     * Scope pour une méthode de paiement spécifique
     */
    public function scopeByPaymentMethod($query, $method)
    {
        return $query->where('payment_method', $method);
    }

    /**
     * Scope pour filtrer par période
     */
    public function scopeBetweenDates($query, $startDate, $endDate)
    {
        return $query->whereBetween('created_at', [$startDate, $endDate]);
    }

    /**
     * Scope pour les achats
     */
    public function scopePurchases($query)
    {
        return $query->where('type', 'purchase');
    }

    /**
     * Scope pour les remboursements
     */
    public function scopeRefunds($query)
    {
        return $query->where('type', 'refund');
    }

    /**
     * Obtenir le libellé de la méthode de paiement
     */
    public function getPaymentMethodLabelAttribute(): string
    {
        return match($this->payment_method) {
            'paypal' => 'PayPal',
            'visa' => 'PayPal (Visa)',
            'mastercard' => 'PayPal (MasterCard)',
            'fedapay' => 'FedaPay',
            'cash' => 'Espèces',
            'card' => 'Carte',
            'mobile' => 'Mobile Money',
            default => ucfirst($this->payment_method),
        };
    }

    /**
     * Obtenir l'icône de la méthode de paiement
     */
    public function getPaymentMethodIconAttribute(): string
    {
        return match($this->payment_method) {
            'paypal' => 'fa-paypal',
            'visa' => 'fa-paypal',
            'mastercard' => 'fa-paypal',
            'fedapay' => 'fa-credit-card',
            'cash' => 'fa-money-bill',
            'card' => 'fa-credit-card',
            'mobile' => 'fa-mobile-alt',
            default => 'fa-credit-card',
        };
    }

    /**
     * Obtenir la couleur de la méthode de paiement
     */
    public function getPaymentMethodColorAttribute(): string
    {
        return match($this->payment_method) {
            'paypal' => 'blue',
            'visa' => 'blue',
            'mastercard' => 'blue',
            'fedapay' => 'green',
            'cash' => 'gray',
            'card' => 'purple',
            'mobile' => 'pink',
            default => 'gray',
        };
    }

    /**
     * Obtenir le libellé du statut
     */
    public function getStatusLabelAttribute(): string
    {
        return match($this->status) {
            'pending' => 'En attente',
            'completed' => 'Complété',
            'cancelled' => 'Annulé',
            'refunded' => 'Remboursé',
            default => ucfirst($this->status),
        };
    }

    /**
     * Obtenir le libellé du type
     */
    public function getTypeLabelAttribute(): string
    {
        return match($this->type) {
            'purchase' => 'Achat',
            'refund' => 'Remboursement',
            'exchange' => 'Échange',
            default => ucfirst($this->type),
        };
    }

    /**
     * Formater le montant en CFA
     */
    public function getFormattedAmountAttribute(): string
    {
        return number_format($this->amount, 0, ',', ' ') . ' ' . $this->currency;
    }

    /**
     * Formater le montant net en CFA
     */
    public function getFormattedNetAmountAttribute(): string
    {
        $net = $this->net_amount ?? ($this->amount - $this->fees);
        return number_format($net, 0, ',', ' ') . ' ' . $this->currency;
    }

    /**
     * Formater les frais en CFA
     */
    public function getFormattedFeesAttribute(): string
    {
        return number_format($this->fees, 0, ',', ' ') . ' ' . $this->currency;
    }
}
