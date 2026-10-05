<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Réclamation d'un client sur UN article d'une commande (litige). Seul ASSO décide ;
 * la part vendeur de l'article reste bloquée sur son Wallet jusqu'à l'issue.
 */
class Dispute extends Model
{
    public const REASONS = ['different', 'damaged', 'defective', 'incomplete', 'wrong_variant', 'other'];

    public const STATUS_NEW = 'new';
    public const STATUS_IN_REVIEW = 'in_review';
    public const STATUS_VENDOR_CONTACTED = 'vendor_contacted';
    public const STATUS_REPLACEMENT = 'replacement';
    public const STATUS_RETURN = 'return';
    public const STATUS_REFUNDED = 'refunded';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_RESOLVED = 'resolved';
    public const STATUS_CLOSED = 'closed';

    public const STATUSES = [
        self::STATUS_NEW => 'Nouveau',
        self::STATUS_IN_REVIEW => 'En analyse',
        self::STATUS_VENDOR_CONTACTED => 'Vendeur contacté',
        self::STATUS_REPLACEMENT => 'Remplacement',
        self::STATUS_RETURN => 'Retour',
        self::STATUS_REFUNDED => 'Remboursé',
        self::STATUS_REJECTED => 'Rejeté',
        self::STATUS_RESOLVED => 'Résolu',
        self::STATUS_CLOSED => 'Fermé',
    ];

    /** Litige encore en cours : la part de l'article reste bloquée. */
    public const OPEN_STATUSES = [
        self::STATUS_NEW, self::STATUS_IN_REVIEW, self::STATUS_VENDOR_CONTACTED,
        self::STATUS_REPLACEMENT, self::STATUS_RETURN,
    ];

    public const DECISION_FOUNDED = 'founded';
    public const DECISION_UNFOUNDED = 'unfounded';

    public const RESOLUTION_REPLACEMENT = 'replacement';
    public const RESOLUTION_RETURN_REFUND = 'return_refund';

    /** Un seul remplacement : une seconde non-conformité impose le retour (Cas B). */
    public const MAX_REPLACEMENTS = 1;

    protected $fillable = [
        'number', 'order_id', 'order_item_id', 'product_id', 'client_id', 'seller_id',
        'reason', 'description', 'held_amount', 'refund_amount',
        'status', 'decision', 'decision_note', 'decided_by', 'decided_at',
        'resolution', 'replacement_count', 'auto_validate_at', 'refunded_at', 'closed_at',
    ];

    protected $casts = [
        'held_amount' => 'decimal:2',
        'refund_amount' => 'decimal:2',
        'replacement_count' => 'integer',
        'decided_at' => 'datetime',
        'auto_validate_at' => 'datetime',
        'refunded_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();
        static::creating(function (Dispute $dispute) {
            if (empty($dispute->number)) {
                do {
                    $number = 'LIT' . now()->format('ymd') . str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
                } while (static::where('number', $number)->exists());
                $dispute->number = $number;
            }
        });
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    /** Remplacement livré, nouveau contrôle de 48 h en cours. */
    public function isInReplacementControl(): bool
    {
        return $this->status === self::STATUS_REPLACEMENT
            && $this->auto_validate_at !== null
            && $this->auto_validate_at->isFuture();
    }

    public function scopeOpen($query)
    {
        return $query->whereIn('status', self::OPEN_STATUSES);
    }

    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
    public function item(): BelongsTo { return $this->belongsTo(OrderItem::class, 'order_item_id'); }
    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
    public function client(): BelongsTo { return $this->belongsTo(User::class, 'client_id'); }
    public function seller(): BelongsTo { return $this->belongsTo(User::class, 'seller_id'); }
    public function decider(): BelongsTo { return $this->belongsTo(User::class, 'decided_by'); }
    public function attachments(): HasMany { return $this->hasMany(DisputeAttachment::class); }
    public function events(): HasMany { return $this->hasMany(DisputeEvent::class)->orderBy('occurred_at')->orderBy('id'); }
    public function shipments(): HasMany { return $this->hasMany(DisputeShipment::class)->orderBy('id'); }

    /** Course en cours d'un type donné (la dernière créée). */
    public function shipment(string $type): ?DisputeShipment
    {
        return $this->shipments->where('type', $type)->sortByDesc('id')->first();
    }
}
