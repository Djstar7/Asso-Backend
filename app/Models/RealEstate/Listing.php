<?php

namespace App\Models\RealEstate;

use App\Models\Concerns\HasTranslations;
use App\Models\User;
use App\Services\RealEstate\RealEstateDescriptionGenerator;
use App\Services\RealEstate\RealEstatePricingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Annonce immobilière : maison à louer (bailleur) ou terrain à vendre (cabinet).
 *
 * Tant que le visiteur n'a pas payé de réservation sur l'annonce, l'API
 * masque l'identité et le contact du bailleur, l'adresse exacte et le point
 * GPS : seuls la ville et le quartier sont publics.
 */
class Listing extends Model
{
    use HasTranslations, SoftDeletes;

    public const TYPE_RENT = 'rent';
    public const TYPE_LAND = 'land';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PENDING = 'pending_review';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_ARCHIVED = 'archived';

    public const RENT_KINDS = ['house', 'villa', 'apartment', 'studio', 'room', 'duplex', 'residence'];
    public const LAND_KINDS = ['building_land', 'farm_land', 'commercial_land'];

    protected $table = 'real_estate_listings';

    protected array $translatable = ['title', 'owner_description', 'promo_text'];

    protected $fillable = [
        'reference', 'type', 'owner_id', 'cabinet_id', 'property_kind', 'title', 'owner_description',
        'country', 'city', 'district', 'address', 'latitude', 'longitude',
        'rooms', 'bedrooms', 'bathrooms', 'surface_m2', 'furnished',
        'road_access', 'distance_to_road_m', 'road_condition', 'is_landlocked', 'water_source',
        'electricity', 'security_level', 'has_parking', 'has_fence', 'has_guard',
        'land_titled', 'land_title_status', 'serviced', 'deposit_percent',
        'min_lease_months', 'advance_months', 'available_from',
        'net_price', 'old_net_price', 'promo_text',
        'is_featured', 'views_count', 'status', 'rejection_reason', 'published_at',
    ];

    protected $casts = [
        'furnished' => 'boolean',
        'is_landlocked' => 'boolean',
        'has_parking' => 'boolean',
        'has_fence' => 'boolean',
        'has_guard' => 'boolean',
        'land_titled' => 'boolean',
        'serviced' => 'boolean',
        'is_featured' => 'boolean',
        'available_from' => 'date',
        'published_at' => 'datetime',
        'net_price' => 'integer',
        'old_net_price' => 'integer',
        'deposit_percent' => 'float',
        'latitude' => 'float',
        'longitude' => 'float',
    ];

    protected static function booted(): void
    {
        static::creating(function (Listing $listing) {
            if (! $listing->reference) {
                $listing->reference = static::newReference($listing->type);
            }
        });
    }

    public static function newReference(?string $type): string
    {
        $prefix = $type === self::TYPE_LAND ? 'TER' : 'LOC';
        do {
            $ref = $prefix.'-'.Str::upper(Str::random(6));
        } while (static::withTrashed()->where('reference', $ref)->exists());

        return $ref;
    }

    // ---------------------------------------------------------------- relations

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function cabinet(): BelongsTo
    {
        return $this->belongsTo(Cabinet::class);
    }

    public function units(): HasMany
    {
        return $this->hasMany(Unit::class)->orderBy('position')->orderBy('id');
    }

    public function tourRooms(): HasMany
    {
        return $this->hasMany(Room::class)->orderBy('position')->orderBy('id');
    }

    public function media(): HasMany
    {
        return $this->hasMany(Media::class)->orderBy('position')->orderBy('id');
    }

    public function amenities(): BelongsToMany
    {
        return $this->belongsToMany(Amenity::class, 'real_estate_amenity_listing', 'listing_id', 'amenity_id');
    }

    public function favoritedBy(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'real_estate_favorites', 'listing_id', 'user_id')->withTimestamps();
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    // ---------------------------------------------------------------- scopes

    public function scopePublished(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_PUBLISHED);
    }

    // ---------------------------------------------------------------- helpers

    public function isLand(): bool
    {
        return $this->type === self::TYPE_LAND;
    }

    /** Le visiteur a-t-il payé une réservation sur cette annonce ? */
    public function isUnlockedFor(?User $user): bool
    {
        if (! $user) {
            return false;
        }
        if ($user->id === $this->owner_id) {
            return true;
        }

        return $this->reservations()
            ->where('tenant_id', $user->id)
            ->whereIn('status', Reservation::PAID_STATUSES)
            ->exists();
    }

    /** Prix net le plus bas (« À partir de »). */
    public function minNetPrice(): int
    {
        $units = $this->relationLoaded('units') ? $this->units : $this->units()->get();
        $min = $units->min('net_price');

        return (int) ($min ?: $this->net_price);
    }

    public function minOldNetPrice(): ?int
    {
        $units = $this->relationLoaded('units') ? $this->units : $this->units()->get();
        $cheapest = $units->sortBy('net_price')->first();
        $old = $cheapest?->old_net_price ?? $this->old_net_price;

        return $old ? (int) $old : null;
    }

    public function unitsAvailable(): int
    {
        $units = $this->relationLoaded('units') ? $this->units : $this->units()->get();

        return $units->isEmpty() ? 1 : (int) $units->sum('units_available');
    }

    public function surfaceRangeLabel(): ?string
    {
        $units = $this->relationLoaded('units') ? $this->units : $this->units()->get();
        $values = $units->flatMap(fn ($u) => [$u->surface_min, $u->surface_max])->filter()->values();
        if ($values->isEmpty()) {
            return $this->surface_m2 ? "{$this->surface_m2} m²" : null;
        }
        $min = $values->min();
        $max = $values->max();

        return $min === $max ? number_format($min, 0, ',', ' ').' m²'
            : number_format($min, 0, ',', ' ').' - '.number_format($max, 0, ',', ' ').' m²';
    }

    public function coverUrl(): ?string
    {
        $media = $this->relationLoaded('media') ? $this->media : $this->media()->get();
        $cover = $media->where('type', 'image')->firstWhere('is_cover', true)
            ?? $media->where('type', 'image')->first();

        return $cover ? media_url($cover->path) : null;
    }

    // ---------------------------------------------------------------- API

    /** Carte de liste. */
    public function toCardApi(?User $viewer = null, array $favoriteIds = []): array
    {
        $pricing = app(RealEstatePricingService::class);
        $media = $this->relationLoaded('media') ? $this->media : $this->media()->get();
        $images = $media->where('type', 'image')->sortByDesc('is_cover')->take(5)->values();
        $available = $this->unitsAvailable();
        $threshold = (int) RealEstatePricingService::setting('immo_low_stock_threshold');
        $old = $this->minOldNetPrice();

        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'type' => $this->type,
            'property_kind' => $this->property_kind,
            'title' => $this->title,
            'city' => $this->city,
            'district' => $this->district,
            'rooms' => $this->rooms,
            'bedrooms' => $this->bedrooms,
            'surface_m2' => $this->surface_m2,
            'surface_label' => $this->surfaceRangeLabel(),
            'furnished' => (bool) $this->furnished,
            'land_titled' => (bool) $this->land_titled,
            'serviced' => (bool) ($this->serviced || ($this->relationLoaded('units') && $this->units->contains('serviced', true))),
            'price' => $pricing->displayed($this->minNetPrice(), $this->type),
            'old_price' => $old ? $pricing->displayed($old, $this->type) : null,
            'price_from' => $this->relationLoaded('units') && $this->units->count() > 1,
            'currency' => 'XAF',
            'images' => $images->map(fn (Media $m) => media_url($m->path))->all(),
            'photos_count' => $media->where('type', 'image')->count(),
            'has_video' => $media->contains('type', 'video'),
            'has_room_tour' => ($this->relationLoaded('tourRooms') ? $this->tourRooms : $this->tourRooms()->get())->count() > 1,
            'is_featured' => (bool) $this->is_featured,
            'is_popular' => $this->views_count >= (int) RealEstatePricingService::setting('immo_popular_views'),
            'promo_text' => $this->promo_text,
            'available_from' => $this->available_from?->toDateString(),
            'available_now' => ! $this->available_from || ! $this->available_from->isFuture(),
            'units_available' => $available,
            'low_stock' => $available > 0 && $available <= $threshold && $this->unitsTotal() > 1,
            'amenities' => ($this->relationLoaded('amenities') ? $this->amenities : collect())
                ->where('kind', '!=', 'service')->take(3)->pluck('name')->values()->all(),
            'cabinet' => $this->cabinet ? [
                'id' => $this->cabinet->id,
                'name' => $this->cabinet->name,
                'logo_url' => media_url($this->cabinet->logo_path),
            ] : null,
            'is_favorite' => in_array($this->id, $favoriteIds, true),
            'published_at' => $this->published_at?->toIso8601String(),
        ];
    }

    public function unitsTotal(): int
    {
        $units = $this->relationLoaded('units') ? $this->units : $this->units()->get();

        return $units->isEmpty() ? 1 : (int) $units->sum('units_total');
    }

    /** Carte compacte affichée en tête d'une conversation avec ASSO. */
    public function toConversationApi(): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'type' => $this->type,
            'title' => $this->title,
            'city' => $this->city,
            'district' => $this->district,
            'price' => app(RealEstatePricingService::class)->displayed($this->minNetPrice(), $this->type),
            'currency' => 'XAF',
            'image' => $this->coverUrl(),
        ];
    }

    /** Fiche complète, avec masquage avant paiement. */
    public function toDetailApi(?User $viewer = null, array $favoriteIds = []): array
    {
        $pricing = app(RealEstatePricingService::class);
        $unlocked = $this->isUnlockedFor($viewer);
        $media = $this->relationLoaded('media') ? $this->media : $this->media()->get();
        $amenities = $this->relationLoaded('amenities') ? $this->amenities : $this->amenities()->get();

        $owner = $this->owner;
        $ownerVerified = $owner && $owner->landlord_verification_status === 'verified';

        return $this->toCardApi($viewer, $favoriteIds) + [
            'description' => app(RealEstateDescriptionGenerator::class)->generate($this),
            'owner_description' => $this->owner_description,
            'bathrooms' => $this->bathrooms,
            'access' => [
                'road_access' => $this->road_access,
                'distance_to_road_m' => $this->distance_to_road_m,
                'road_condition' => $this->road_condition,
                'is_landlocked' => (bool) $this->is_landlocked,
                'water_source' => $this->water_source,
                'electricity' => $this->electricity,
                'security_level' => $this->security_level,
                'has_parking' => (bool) $this->has_parking,
                'has_fence' => (bool) $this->has_fence,
                'has_guard' => (bool) $this->has_guard,
            ],
            'land_title_status' => $this->land_title_status,
            'deposit_percent' => $this->isLand()
                ? (float) ($this->deposit_percent ?: RealEstatePricingService::setting('immo_land_default_deposit_percent'))
                : null,
            'min_lease_months' => $this->min_lease_months,
            'advance_months' => $this->advance_months,
            'commission_rate' => $this->isLand()
                ? RealEstatePricingService::setting('immo_land_commission_rate')
                : RealEstatePricingService::setting('immo_rent_commission_rate'),
            'visit_window_days' => (int) RealEstatePricingService::setting(
                $this->isLand() ? 'immo_land_release_days' : 'immo_visit_window_days'
            ),
            'highlights' => $amenities->where('kind', 'highlight')->values()->map->toApi()->all(),
            'equipments' => $amenities->where('kind', 'equipment')->values()->map->toApi()->all(),
            'services' => $amenities->where('kind', 'service')->values()->map->toApi()->all(),
            'units' => ($this->relationLoaded('units') ? $this->units : $this->units()->get())->map(fn (Unit $u) => [
                'id' => $u->id,
                'label' => $u->label,
                'surface_min' => $u->surface_min,
                'surface_max' => $u->surface_max,
                'price' => $pricing->displayed((int) $u->net_price, $this->type),
                'old_price' => $u->old_net_price ? $pricing->displayed((int) $u->old_net_price, $this->type) : null,
                'capacity' => $u->capacity,
                'kitchen' => $u->kitchen,
                'furnished' => (bool) $u->furnished,
                'serviced' => (bool) $u->serviced,
                'units_total' => $u->units_total,
                'units_available' => $u->units_available,
                'available_from' => $u->available_from?->toDateString(),
            ])->values()->all(),
            'rooms_tour' => ($this->relationLoaded('tourRooms') ? $this->tourRooms : $this->tourRooms()->get())->map(fn (Room $r) => [
                'id' => $r->id,
                'kind' => $r->kind,
                'label' => $r->label,
                'media' => $media->where('room_id', $r->id)->values()->map->toApi()->all(),
            ])->values()->all(),
            'media' => $media->values()->map->toApi()->all(),
            'video' => optional($media->firstWhere('type', 'video'))->toApi(),
            'views_count' => $this->views_count,
            'location' => [
                'city' => $this->city,
                'district' => $this->district,
                'address' => $unlocked ? $this->address : null,
                'latitude' => $unlocked ? $this->latitude : null,
                'longitude' => $unlocked ? $this->longitude : null,
            ],
            'unlocked' => $unlocked,
            'owner' => $this->isLand() ? null : [
                'is_verified' => $ownerVerified,
                'member_since' => $owner?->created_at?->year,
                'name' => $unlocked && $owner ? trim($owner->first_name.' '.$owner->last_name) : null,
                'phone' => $unlocked ? $owner?->phone : null,
                'avatar' => $unlocked ? $owner?->avatar : null,
            ],
            'cabinet' => $this->cabinet?->toApi($viewer),
            'status' => $this->status,
        ];
    }
}
