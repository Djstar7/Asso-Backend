<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Catégorie du fournisseur (CJ : 3e niveau) et sa correspondance dans ASSO. */
class SupplierCategory extends Model
{
    protected $fillable = ['supplier_id', 'external_id', 'name', 'path', 'category_id', 'subcategory_id', 'synced_at'];

    protected $casts = ['synced_at' => 'datetime'];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function subcategory(): BelongsTo
    {
        return $this->belongsTo(Subcategory::class);
    }

    public function isMapped(): bool
    {
        return $this->category_id !== null;
    }
}
