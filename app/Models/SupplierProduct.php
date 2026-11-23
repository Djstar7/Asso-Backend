<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Produit d'un fournisseur importé dans ASSO, relié à sa fiche ASSO.
 *
 * Statuts : imported (fiche ASSO prête), no_shipping (aucune ligne vers le
 * Cameroun), unmapped (catégorie non reliée), error, delisted (retiré par le
 * fournisseur).
 */
class SupplierProduct extends Model
{
    public const IMPORTED = 'imported';

    public const PENDING = 'pending';

    public const NO_SHIPPING = 'no_shipping';

    public const UNMAPPED = 'unmapped';

    public const ERROR = 'error';

    public const DELISTED = 'delisted';

    public const STATUS_LABELS = [
        self::PENDING => 'En attente',
        self::IMPORTED => 'Importé',
        self::NO_SHIPPING => 'Pas de livraison au Cameroun',
        self::UNMAPPED => 'Catégorie à relier',
        self::ERROR => 'Erreur',
        self::DELISTED => 'Retiré par le fournisseur',
    ];

    protected $fillable = [
        'supplier_id', 'external_id', 'external_sku', 'product_id', 'external_category_id',
        'name', 'status', 'cost_usd', 'freight_usd', 'logistic_name', 'logistic_aging',
        'weight_g', 'main_image_url', 'payload_hash', 'payload', 'last_error', 'last_synced_at',
    ];

    protected $casts = [
        'cost_usd' => 'decimal:2',
        'freight_usd' => 'decimal:2',
        'payload' => 'array',
        'last_synced_at' => 'datetime',
    ];

    protected $hidden = ['payload'];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withoutGlobalScopes();
    }

    public function variants(): HasMany
    {
        return $this->hasMany(SupplierVariant::class);
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }
}
