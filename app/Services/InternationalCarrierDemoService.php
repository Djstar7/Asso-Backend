<?php

namespace App\Services;

use App\Models\DelivererCompany;
use App\Models\DeliveryAgency;
use App\Models\DeliveryRoute;
use App\Models\Order;
use App\Support\CountryCode;
use Illuminate\Support\Facades\DB;

/** Synthetic coverage and tariffs, never an official carrier catalogue. */
class InternationalCarrierDemoService
{
    public const DATASET = 'international-carriers-demo-v1';

    private const REGIONS = [
        'Europe' => ['AD', 'AL', 'AT', 'AX', 'BA', 'BE', 'BG', 'BY', 'CH', 'CY', 'CZ', 'DE', 'DK', 'EE', 'ES', 'FI', 'FO', 'FR', 'GB', 'GG', 'GI', 'GR', 'HR', 'HU', 'IE', 'IM', 'IS', 'IT', 'JE', 'LI', 'LT', 'LU', 'LV', 'MC', 'MD', 'ME', 'MK', 'MT', 'NL', 'NO', 'PL', 'PT', 'RO', 'RS', 'RU', 'SE', 'SI', 'SJ', 'SK', 'SM', 'UA', 'VA'],
        'Amérique du Nord et Caraïbes' => ['AG', 'AI', 'AW', 'BB', 'BL', 'BM', 'BQ', 'BS', 'BZ', 'CA', 'CR', 'CU', 'CW', 'DM', 'DO', 'GD', 'GL', 'GP', 'GT', 'HN', 'HT', 'JM', 'KN', 'KY', 'LC', 'MF', 'MQ', 'MS', 'MX', 'NI', 'PA', 'PM', 'PR', 'SV', 'SX', 'TC', 'TT', 'US', 'VC', 'VG', 'VI'],
        'Amérique du Sud' => ['AR', 'BO', 'BR', 'CL', 'CO', 'EC', 'FK', 'GF', 'GY', 'PE', 'PY', 'SR', 'UY', 'VE'],
        'Afrique' => ['AO', 'BF', 'BI', 'BJ', 'BW', 'CD', 'CF', 'CG', 'CI', 'CV', 'DJ', 'DZ', 'EG', 'EH', 'ER', 'ET', 'GA', 'GH', 'GM', 'GN', 'GQ', 'GW', 'KE', 'KM', 'LR', 'LS', 'LY', 'MA', 'MG', 'ML', 'MR', 'MU', 'MW', 'MZ', 'NA', 'NE', 'NG', 'RE', 'RW', 'SC', 'SD', 'SH', 'SL', 'SN', 'SO', 'SS', 'ST', 'SZ', 'TD', 'TG', 'TN', 'TZ', 'UG', 'YT', 'ZA', 'ZM', 'ZW'],
        'Asie et Moyen-Orient' => ['AE', 'AF', 'AM', 'AZ', 'BD', 'BH', 'BN', 'BT', 'CN', 'GE', 'HK', 'ID', 'IL', 'IN', 'IO', 'IQ', 'IR', 'JO', 'JP', 'KG', 'KH', 'KP', 'KR', 'KW', 'KZ', 'LA', 'LB', 'LK', 'MM', 'MN', 'MO', 'MV', 'MY', 'NP', 'OM', 'PH', 'PK', 'PS', 'QA', 'SA', 'SG', 'SY', 'TH', 'TJ', 'TL', 'TM', 'TR', 'TW', 'UZ', 'VN', 'YE'],
        'Océanie et autres territoires' => ['AS', 'AU', 'CC', 'CK', 'CX', 'FJ', 'FM', 'GU', 'KI', 'MH', 'MP', 'NC', 'NF', 'NR', 'NU', 'NZ', 'PF', 'PG', 'PN', 'PW', 'SB', 'TK', 'TO', 'TV', 'VU', 'WF', 'WS'],
    ];

    public static function countries(): array
    {
        return array_diff_key(CountryCode::NAMES, ['CM' => true]);
    }

    public function load(bool $dryRun = false, bool $allowInactiveAgency = false, bool $preserveExistingActivation = false): array
    {
        $agency = DeliveryAgency::where('is_active', true)
            ->whereHas('company', fn ($q) => $q->where('is_active', true)->where('name', 'like', '%SOLEX%'))
            ->orderByRaw("CASE WHEN city = 'Douala' THEN 0 ELSE 1 END")->orderBy('id')->first();
        if (!$agency && $allowInactiveAgency) {
            $agency = DeliveryAgency::where('is_international_reference', false)
                ->whereHas('company', fn ($q) => $q->where('name', 'like', '%SOLEX%'))
                ->orderByRaw("CASE WHEN city = 'Douala' THEN 0 ELSE 1 END")->orderBy('id')->first();
        }
        if (! $agency) {
            throw new \RuntimeException('Créez une agence Solex active avant de charger les tarifs internationaux.');
        }

        return DB::transaction(function () use ($dryRun, $agency, $preserveExistingActivation) {
            $counts = ['destinations' => count(self::countries()), 'created' => 0, 'converted' => 0, 'preserved' => 0];
            foreach ([
                ['DHL Express', 'https://www.dhl.com/cm-fr/home/tracking.html?tracking-id={number}', 0],
                ['FedEx', 'https://www.fedex.com/fedextrack/?trknbr={number}', 750],
            ] as [$name, $tracking, $offset]) {
                $company = DelivererCompany::where('name', $name)->first();
                if (! $company && ! $dryRun) {
                    $company = DelivererCompany::create(['name' => $name, 'service_type' => 'international',
                        'service_mode' => 'door_to_door', 'tracking_url_template' => $tracking,
                        'is_active' => true, 'prices_exclude_vat' => true]);
                }
                $hasExports = $company?->deliveryRoutes()->whereNotNull('export_config')->exists();
                if ($company && ! $hasExports && ! $dryRun && !$preserveExistingActivation) {
                    $company->update(['is_active' => true]);
                }
                foreach (self::countries() as $country => $countryName) {
                    $identity = ['origin_country' => 'CM', 'origin_city' => null, 'destination_country' => $country, 'destination_city' => null];
                    $route = $company?->deliveryRoutes()->where($identity)->first();
                    if ($route && ! $this->isOriginalSample($route, $agency, $name)) {
                        $counts['preserved']++;

                        continue;
                    }
                    $counts[$route ? 'converted' : 'created']++;
                    if ($dryRun) {
                        continue;
                    }
                    $region = $this->region($country);
                    $base = match ($region) {
                        'Afrique' => 4500, 'Europe' => 7500,
                        'Amérique du Nord et Caraïbes' => 9000, 'Amérique du Sud' => 10500,
                        'Asie et Moyen-Orient' => 8500, default => 11500,
                    };
                    // Stable, varied example values, preserved on every subsequent import.
                    $rate = $base + $offset + ((ord($country[0]) + ord($country[1])) % 5) * 250;
                    $data = [
                        'bidirectional' => false, 'is_active' => true,
                        'lead_time' => '5 à 12 jours ouvrables (indicatif)',
                        'pricing_data' => ['mode' => 'per_kg', 'price_per_kg' => $rate, 'max_weight_kg' => 1000],
                        'export_config' => [
                            'demo_dataset' => self::DATASET, 'provisional_rates' => true,
                            'solex_agency_id' => $agency->id, 'zone_label' => $region,
                            'coverage_mode' => 'country', 'polygon' => [],
                            'collection_pricing' => ['ranges' => [['min' => 0, 'max' => 5, 'price' => 2500]], 'extra_per_kg' => 250],
                        ],
                    ];
                    if ($route) {
                        $route->update($data);
                    } else {
                        $company->deliveryRoutes()->create($identity + $data);
                    }
                }
            }

            return $counts;
        });
    }

    /** Only unedited samples from the previous three-country seed are converted. */
    private function isOriginalSample(DeliveryRoute $route, DeliveryAgency $agency, string $name): bool
    {
        $sample = ['FR' => ['Europe', 18000], 'DE' => ['Europe', 19000], 'US' => ['Amérique', 24000]][$route->destination_country] ?? null;
        if (! $sample || $route->lead_time !== '5 à 10 jours ouvrables (indicatif)' || ! $route->is_active || $route->bidirectional) {
            return false;
        }
        [$zone, $price] = $sample;
        $offset = $name === 'FedEx' ? 2000 : 0;

        return $route->pricing_data == ['ranges' => [
            ['min' => 0, 'max' => 2, 'price' => $price + $offset],
            ['min' => 2, 'max' => 5, 'price' => $price + $offset + 12000],
            ['min' => 5, 'max' => 10, 'price' => $price + $offset + 30000],
        ], 'extra_per_kg' => 6000 + $offset]
            && $route->export_config == [
                'solex_agency_id' => $agency->id, 'zone_label' => $zone, 'provisional_rates' => true,
                'coverage_mode' => 'country', 'polygon' => [],
                'collection_pricing' => ['ranges' => [['min' => 0, 'max' => 5, 'price' => 2500]], 'extra_per_kg' => 250],
            ];
    }

    private function region(string $country): string
    {
        foreach (self::REGIONS as $region => $codes) {
            if (in_array($country, $codes, true)) {
                return $region;
            }
        }

        return 'Océanie et autres territoires';
    }

    /** Referenced routes are deactivated; order history and snapshots remain intact. */
    public function remove(bool $dryRun = false): array
    {
        return DB::transaction(function () use ($dryRun) {
            $routes = DeliveryRoute::where('export_config->demo_dataset', self::DATASET)
                ->where('export_config->provisional_rates', true)->lockForUpdate()->get();
            $used = Order::whereIn('delivery_route_id', $routes->pluck('id'))->distinct()->pluck('delivery_route_id')->map(fn ($id) => (int) $id)->all();
            $counts = ['deleted' => 0, 'deactivated' => 0];
            foreach ($routes as $route) {
                if (in_array($route->id, $used, true)) {
                    $counts['deactivated']++;
                    if (! $dryRun) {
                        $route->update(['is_active' => false]);
                    }
                } else {
                    $counts['deleted']++;
                    if (! $dryRun) {
                        $route->delete();
                    }
                }
            }

            return $counts;
        });
    }
}
