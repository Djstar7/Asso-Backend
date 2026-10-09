<?php

namespace App\Models;

use App\Models\Concerns\HasTranslations;
use App\Support\CountryCode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Grille urbaine zone à zone d'un partenaire dans une ville (ex. SOLEX Douala).
 * Le prix dépend du véhicule et du couple zone de départ / zone d'arrivée
 * (symétrique : Zone 1 → Zone 3 = Zone 3 → Zone 1). Le poids du colis limite les
 * véhicules proposés (max_weight_kg).
 */
class DeliveryCityGrid extends Model
{
    use HasTranslations;

    /** Champs traduisibles (voir HasTranslations). */
    protected array $translatable = ['zones', 'vehicles'];

    protected $fillable = [
        'deliverer_company_id',
        'city',
        'country',
        'zones',
        'vehicles',
        'agency_zone',
        'asso_commission',
        'is_active',
    ];

    protected $casts = [
        'zones' => 'array',
        'vehicles' => 'array',
        'agency_zone' => 'integer',
        'asso_commission' => 'float',
        'is_active' => 'boolean',
    ];

    /**
     * Traduction des libellés seulement : zones {code: label}, véhicules
     * {code: {label, lead_time}}. Prix, quartiers et codes restent ceux de la grille.
     */
    protected function mergeTranslation(string $field, mixed $source, mixed $translated): mixed
    {
        if (! is_array($source) || ! is_array($translated)) {
            return $source;
        }

        return array_map(function ($item) use ($field, $translated) {
            $tr = $translated[(string) ($item['code'] ?? '')] ?? null;
            if ($field === 'zones' && is_string($tr) && $tr !== '') {
                $item['label'] = $tr;
            } elseif ($field === 'vehicles' && is_array($tr)) {
                foreach (['label', 'lead_time'] as $key) {
                    if (filled($tr[$key] ?? null)) {
                        $item[$key] = $tr[$key];
                    }
                }
            }

            return $item;
        }, $source);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(DelivererCompany::class, 'deliverer_company_id');
    }

    /**
     * Quartiers d'une zone, normalisés : [['name' => 'Akwa-Nord', 'lat' => 4.06, 'lng' => 9.71], …].
     * (Anciennes données : simple liste de noms, sans position.)
     */
    public static function quartersOf(array $zone): array
    {
        return collect($zone['quarters'] ?? [])->map(fn ($q) => is_array($q)
            ? ['name' => (string) ($q['name'] ?? ''), 'lat' => isset($q['lat']) && $q['lat'] !== '' ? (float) $q['lat'] : null, 'lng' => isset($q['lng']) && $q['lng'] !== '' ? (float) $q['lng'] : null]
            : ['name' => (string) $q, 'lat' => null, 'lng' => null])
            ->filter(fn ($q) => $q['name'] !== '')
            ->values()
            ->all();
    }

    /**
     * Quartier géolocalisé le plus proche d'un point (boutique sur sa carte, position de
     * l'acheteur), dans le rayon réglé dans l'admin. Null si aucun quartier n'est placé.
     *
     * @return array{zone: int, name: string, distance_km: float}|null
     */
    public function nearestQuarter(?float $lat, ?float $lng): ?array
    {
        if ($lat === null || $lng === null || ($lat == 0 && $lng == 0)) {
            return null;
        }

        $best = null;
        foreach ($this->zones as $zone) {
            foreach (self::quartersOf($zone) as $quarter) {
                if ($quarter['lat'] === null || $quarter['lng'] === null) {
                    continue;
                }
                $d = self::distanceKm($lat, $lng, $quarter['lat'], $quarter['lng']);
                if (!$best || $d < $best['distance_km']) {
                    $best = ['zone' => (int) $zone['code'], 'name' => $quarter['name'], 'distance_km' => round($d, 2)];
                }
            }
        }

        $radius = \App\Services\DeliveryQuoteService::radiusKm();

        return $best && $best['distance_km'] <= $radius ? $best : null;
    }

    public static function distanceKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return 6371 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /** Grille active d'un partenaire actif qui couvre une ville. */
    public static function forCity(?string $city): ?self
    {
        if (!$city) {
            return null;
        }

        return self::where('is_active', true)
            ->whereHas('company', fn ($q) => $q->where('is_active', true))
            ->get()
            ->first(fn (self $grid) => $grid->coversCity($city));
    }

    public function coversCity(?string $city): bool
    {
        return CountryCode::sameCity($this->city, $city);
    }

    /** @return array{code: int, label: string, quarters: array}|null */
    public function zone(?int $code): ?array
    {
        return collect($this->zones)->firstWhere('code', $code);
    }

    /** « Zone 1 (Bali, Bonapriso, Bonanjo…) » */
    public function zoneLabel(int $code, int $maxQuarters = 3): string
    {
        $zone = $this->zone($code);
        if (!$zone) {
            return "Zone {$code}";
        }
        $quarters = array_column(self::quartersOf($zone), 'name');
        if ($maxQuarters <= 0) {
            return $zone['label'];
        }
        $shown = implode(', ', array_slice($quarters, 0, $maxQuarters)) . (count($quarters) > $maxQuarters ? '…' : '');

        return $zone['label'] . ($shown !== '' ? " ({$shown})" : '');
    }

    /**
     * Zone d'un quartier, d'un code (« 3 », « Zone 3 ») ou d'une adresse libre qui cite
     * un quartier (« Rue 1.234, Akwa, Douala »). Le nom le plus long l'emporte
     * (« Akwa-Nord » avant « Akwa »).
     */
    public function zoneFor(?string $text): ?int
    {
        if ($text === null || trim($text) === '') {
            return null;
        }
        if (preg_match('/^\s*(?:zone\s*)?(\d{1,2})\s*$/i', $text, $m) && $this->zone((int) $m[1])) {
            return (int) $m[1];
        }

        $haystack = ' ' . preg_replace('/[^a-z0-9]+/', ' ', self::normalize($text)) . ' ';
        $best = null;
        $bestLength = 0;
        foreach ($this->zones as $zone) {
            foreach (array_column(self::quartersOf($zone), 'name') as $quarter) {
                $needle = trim(preg_replace('/[^a-z0-9]+/', ' ', self::normalize($quarter)));
                if ($needle !== '' && str_contains($haystack, " {$needle} ") && strlen($needle) > $bestLength) {
                    $best = (int) $zone['code'];
                    $bestLength = strlen($needle);
                }
            }
        }

        return $best;
    }

    /** @return array{code: string, label: string, max_weight_kg: ?float, lead_time: ?string, prices: array}|null */
    public function vehicle(?string $code): ?array
    {
        return collect($this->vehicles)->firstWhere('code', $code);
    }

    /** Prix HT d'un véhicule entre deux zones (grille symétrique), null si non renseigné. */
    public function price(string $vehicle, int $from, int $to): ?float
    {
        $prices = $this->vehicle($vehicle)['prices'] ?? [];
        $key = min($from, $to) . '-' . max($from, $to);

        return isset($prices[$key]) && $prices[$key] !== '' ? (float) $prices[$key] : null;
    }

    /** Véhicules capables de porter ce poids, du plus petit au plus gros (sans limite en dernier). */
    public function vehiclesFor(float $weightKg): array
    {
        return collect($this->vehicles)
            ->filter(fn ($v) => empty($v['max_weight_kg']) || $weightKg <= (float) $v['max_weight_kg'])
            ->sortBy(fn ($v) => empty($v['max_weight_kg']) ? PHP_FLOAT_MAX : (float) $v['max_weight_kg'])
            ->values()
            ->all();
    }

    /**
     * Véhicule choisi pour un colis : le plus petit dont le poids max. couvre le colis et qui a
     * un prix entre ces deux zones (ex. 2 kg → moto, 45 kg → tricycle, 450 kg → camionnette).
     *
     * @return array{vehicle: array, price: float}|null
     */
    public function vehicleFor(float $weightKg, int $fromZone, int $toZone): ?array
    {
        foreach ($this->vehiclesFor($weightKg) as $vehicle) {
            $price = $this->price($vehicle['code'], $fromZone, $toZone);
            if ($price !== null) {
                return ['vehicle' => $vehicle, 'price' => $price];
            }
        }

        return null;
    }

    /** Quartiers proposés à l'acheteur, par zone. */
    public function quarterOptions(): array
    {
        return collect($this->zones)->map(fn ($z) => [
            'zone' => (int) $z['code'],
            'label' => $z['label'],
            'quarters' => array_column(self::quartersOf($z), 'name'),
        ])->values()->all();
    }

    /** Zone d'un quartier par son nom exact (liste de l'app). */
    public function zoneOfQuarter(?string $name): ?int
    {
        foreach ($this->zones as $zone) {
            foreach (self::quartersOf($zone) as $quarter) {
                if ($name !== null && self::normalize($quarter['name']) === self::normalize($name)) {
                    return (int) $zone['code'];
                }
            }
        }

        return null;
    }

    private static function normalize(string $value): string
    {
        return strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', $value) ?: $value);
    }
}
