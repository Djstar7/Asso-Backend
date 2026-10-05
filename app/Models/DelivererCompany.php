<?php

namespace App\Models;

use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DelivererCompany extends Model
{
    use HasTranslations;

    /** Champs traduisibles (voir HasTranslations). */
    protected array $translatable = ['description', 'conditions'];

    protected $fillable = [
        'user_id',
        'name',
        'phone',
        'email',
        'description',
        'logo',
        'is_active',
        'service_type',
        'service_mode',
        'prices_exclude_vat',
        'conditions',
        'max_weight_kg',
        'tracking_url_template',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'prices_exclude_vat' => 'boolean',
        'max_weight_kg' => 'float',
    ];

    public const SERVICE_LOCAL = 'local';
    public const SERVICE_INTERCITY = 'intercity';
    public const SERVICE_INTERNATIONAL = 'international';

    public const SERVICE_TYPES = [
        self::SERVICE_LOCAL => 'Urbain (même ville)',
        self::SERVICE_INTERCITY => 'Interurbain (Cameroun)',
        self::SERVICE_INTERNATIONAL => 'Livraison internationale',
    ];

    public const MODE_DOOR = 'door_to_door';
    public const MODE_AGENCY = 'agency_to_agency';

    public const SERVICE_MODES = [
        self::MODE_DOOR => 'Livraison à domicile',
        self::MODE_AGENCY => "D'agence en agence (dépôt et retrait en agence)",
    ];

    public function isCarrier(): bool
    {
        return $this->service_type !== self::SERVICE_LOCAL;
    }

    /**
     * Services réellement configurés, pour l'admin : [['label' => 'Urbain (Douala)', 'mode' => 'À domicile'], …].
     * Un même partenaire peut en avoir plusieurs (ex. SOLEX : urbain à Douala + interurbain).
     * Sans aucun service, retombe sur la catégorie choisie à la création.
     */
    public function configuredServices(): array
    {
        $services = [];
        $cities = $this->cityGrids->pluck('city')->filter()->unique()->values();
        if ($cities->isNotEmpty() || $this->deliveryZones->isNotEmpty()) {
            $services[] = [
                'label' => 'Urbain' . ($cities->isNotEmpty() ? ' (' . $cities->implode(', ') . ')' : ''),
                'mode' => 'À domicile',
            ];
        }
        $mode = $this->service_mode === self::MODE_AGENCY ? 'Agence → agence' : 'À domicile';
        if ($this->deliveryRoutes->contains(fn ($r) => $r->origin_country === $r->destination_country)) {
            $services[] = ['label' => self::SERVICE_TYPES[self::SERVICE_INTERCITY], 'mode' => $mode];
        }
        if ($this->deliveryRoutes->contains(fn ($r) => $r->origin_country !== $r->destination_country)) {
            $services[] = ['label' => self::SERVICE_TYPES[self::SERVICE_INTERNATIONAL], 'mode' => $mode];
        }

        return $services ?: [[
            'label' => self::SERVICE_TYPES[$this->service_type] ?? $this->service_type,
            'mode' => $this->service_type === self::SERVICE_LOCAL ? 'À domicile' : $mode,
        ]];
    }

    public function trackingUrl(?string $number): ?string
    {
        if (!$number || !$this->tracking_url_template) {
            return null;
        }

        return str_replace('{number}', rawurlencode($number), $this->tracking_url_template);
    }

    public function deliveryRoutes(): HasMany
    {
        return $this->hasMany(DeliveryRoute::class);
    }

    /** Grilles urbaines zone à zone (ex. SOLEX Douala : moto, tricycle, 600 kg, 1 t). */
    public function cityGrids(): HasMany
    {
        return $this->hasMany(DeliveryCityGrid::class);
    }

    /**
     * Get the user/deliverer that owns this company
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get all delivery zones for this company
     */
    public function deliveryZones(): HasMany
    {
        return $this->hasMany(DeliveryZone::class);
    }

    /**
     * Get active delivery zones
     */
    public function activeDeliveryZones(): HasMany
    {
        return $this->hasMany(DeliveryZone::class)->where('is_active', true);
    }

    /**
     * Get all sync codes for this company
     */
    public function syncCodes(): HasMany
    {
        return $this->hasMany(DelivererSyncCode::class, 'company_id');
    }

    /**
     * Get all code syncs (users who synced) for this company
     */
    public function codeSyncs(): HasMany
    {
        return $this->hasMany(DelivererCodeSync::class, 'company_id');
    }

    /**
     * Get active code syncs for this company
     */
    public function activeCodeSyncs(): HasMany
    {
        return $this->hasMany(DelivererCodeSync::class, 'company_id')
            ->where('is_active', true)
            ->where('is_banned', false);
    }
}
