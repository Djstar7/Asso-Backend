<?php

namespace App\Models;

use App\Models\Concerns\HasTranslations;
use App\Support\CountryCode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Trajet d'un transporteur (ex. SOLEX Douala ↔ Yaoundé, DHL Chine → Cameroun)
 * avec sa grille au poids (cf. App\Support\WeightGrid).
 */
class DeliveryRoute extends Model
{
    use HasTranslations;

    /** Champs traduisibles (voir HasTranslations). */
    protected array $translatable = ['lead_time'];

    protected $fillable = [
        'deliverer_company_id',
        'origin_country',
        'origin_city',
        'destination_country',
        'destination_city',
        'bidirectional',
        'pricing_data',
        'asso_commission',
        'lead_time',
        'is_active',
    ];

    protected $casts = [
        'pricing_data' => 'array',
        'asso_commission' => 'float',
        'bidirectional' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(DelivererCompany::class, 'deliverer_company_id');
    }

    /**
     * Le trajet dessert-il ce couple origine → destination ?
     * Une ville vide sur le trajet couvre toutes les villes du pays.
     */
    public function serves(?string $fromCountry, ?string $fromCity, ?string $toCountry, ?string $toCity): bool
    {
        $direct = $this->endpointMatches($this->origin_country, $this->origin_city, $fromCountry, $fromCity)
            && $this->endpointMatches($this->destination_country, $this->destination_city, $toCountry, $toCity);

        if ($direct || !$this->bidirectional) {
            return $direct;
        }

        return $this->endpointMatches($this->origin_country, $this->origin_city, $toCountry, $toCity)
            && $this->endpointMatches($this->destination_country, $this->destination_city, $fromCountry, $fromCity);
    }

    public function label(): string
    {
        $origin = $this->origin_city ?: CountryCode::name($this->origin_country);
        $destination = $this->destination_city ?: CountryCode::name($this->destination_country);

        return $origin . ($this->bidirectional ? ' ↔ ' : ' → ') . $destination;
    }

    private function endpointMatches(string $routeCountry, ?string $routeCity, ?string $country, ?string $city): bool
    {
        if ($country === null || strtoupper($routeCountry) !== strtoupper($country)) {
            return false;
        }

        return $routeCity === null || $routeCity === '' || CountryCode::sameCity($routeCity, $city);
    }
}
