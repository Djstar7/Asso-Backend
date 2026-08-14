<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Inventory extends Model
{
    protected $table = 'inventory';

    protected $fillable = [
        'product_id',
        'user_id',
        'type',
        'quantity',
        'stock_after',
        'order_id',
        'notes',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'stock_after' => 'integer',
    ];

    /**
     * Relation avec le produit
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Relation avec le vendeur
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Relation avec la commande (si applicable)
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Scope pour filtrer les entrées
     */
    public function scopeEntries($query)
    {
        return $query->where('type', 'entry');
    }

    /**
     * Scope pour filtrer les sorties
     */
    public function scopeExits($query)
    {
        return $query->where('type', 'exit');
    }

    /**
     * Scope pour un produit spécifique
     */
    public function scopeForProduct($query, $productId)
    {
        return $query->where('product_id', $productId);
    }

    /**
     * Scope pour un vendeur spécifique
     */
    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }
}
