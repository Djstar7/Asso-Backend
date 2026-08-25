<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlatformWithdrawal extends Model
{
    protected $fillable = [
        'user_id',
        'admin_id',
        'amount_requested',
        'commission_rate',
        'commission_amount',
        'amount_sent',
        'currency',
        'provider',
        'payment_method',
        'payment_account',
        'payment_account_name',
        'status',
        'transaction_reference',
        'kpay_reference',
        'kpay_response',
        'paypal_batch_id',
        'paypal_payout_item_id',
        'paypal_response',
        'stripe_transfer_id',
        'stripe_payout_id',
        'stripe_response',
        // Conversion : le vendeur est débité en `currency`, l'IBAN reçoit
        // `payout_amount` en `payout_currency` au taux `exchange_rate`.
        'payout_amount',
        'payout_currency',
        'exchange_rate',
        'failure_code',
        'failure_reason',
        'admin_notes',
        'completed_at',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'amount_requested' => 'decimal:2',
        'commission_rate' => 'decimal:2',
        'commission_amount' => 'decimal:2',
        'amount_sent' => 'decimal:2',
        'kpay_response' => 'array',
        'paypal_response' => 'array',
        'stripe_response' => 'array',
        'payout_amount' => 'decimal:2',
        'completed_at' => 'datetime',
    ];

    /**
     * Get the user who initiated the withdrawal (for user withdrawals)
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Get the admin who initiated the withdrawal (for admin/platform withdrawals)
     */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    /**
     * Check if withdrawal is pending
     */
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * Check if withdrawal is processing
     */
    public function isProcessing(): bool
    {
        return $this->status === 'processing';
    }

    /**
     * Check if withdrawal is completed
     */
    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    /**
     * Check if withdrawal is failed
     */
    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    /**
     * Mark withdrawal as processing
     */
    public function markAsProcessing(): void
    {
        $this->update(['status' => 'processing']);
    }

    /**
     * Mark withdrawal as completed
     */
    public function markAsCompleted(string $reference, array $response): void
    {
        $updateData = [
            'status' => 'completed',
            'completed_at' => now(),
        ];

        // Store reference and response based on provider
        if ($this->provider === 'paypal') {
            $updateData['paypal_batch_id'] = $reference;
            $updateData['paypal_response'] = $response;
        } elseif ($this->provider === 'stripe') {
            $updateData['stripe_payout_id'] = $reference;
            $updateData['stripe_response'] = $response;
        } else {
            $updateData['kpay_reference'] = $reference;
            $updateData['kpay_response'] = $response;
        }

        $this->update($updateData);
    }

    /**
     * Check if withdrawal is using Stripe Connect (virement IBAN)
     */
    public function isStripe(): bool
    {
        return $this->provider === 'stripe';
    }

    /**
     * Check if withdrawal is using PayPal
     */
    public function isPayPal(): bool
    {
        return $this->provider === 'paypal';
    }

    /**
     * Check if withdrawal is using KPay
     */
    public function isKpay(): bool
    {
        return $this->provider === 'kpay';
    }

    /**
     * Mark withdrawal as failed
     */
    public function markAsFailed(string $code, string $reason): void
    {
        $this->update([
            'status' => 'failed',
            'failure_code' => $code,
            'failure_reason' => $reason,
        ]);
    }

    /**
     * Scope for pending withdrawals
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope for processing withdrawals
     */
    public function scopeProcessing($query)
    {
        return $query->where('status', 'processing');
    }

    /**
     * Scope for completed withdrawals
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    /**
     * Scope for failed withdrawals
     */
    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    /**
     * Scope by provider
     */
    public function scopeByProvider($query, string $provider)
    {
        return $query->where('provider', $provider);
    }
}
