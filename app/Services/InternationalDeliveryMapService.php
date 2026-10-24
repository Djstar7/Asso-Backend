<?php

namespace App\Services;

use App\Models\DeliveryAgency;
use App\Models\DeliveryRoute;
use App\Support\CountryCode;
use App\Support\DeliveryCountryBoundaries;
use App\Support\ExportCoverage;

class InternationalDeliveryMapService
{
    public function coverage(float $lat, float $lng, ?string $country, ?string $city): array
    {
        $countries = DeliveryCountryBoundaries::all();
        $routes = DeliveryRoute::where('is_active', true)->where('origin_country', 'CM')->where('destination_country', '!=', 'CM')
            ->whereNotNull('export_config')->whereHas('company', fn ($q) => $q->where('is_active', true))->with('company')->get();
        $agencies = DeliveryAgency::with('company')->whereIn('id', $routes->map(fn ($r) => $r->export_config['solex_agency_id'] ?? null)->filter()->unique())->get()->keyBy('id');
        $references = DeliveryAgency::where('is_active', true)->where('is_international_reference', true)
            ->whereNotNull('latitude')->whereNotNull('longitude')->orderBy('is_provisional')->orderBy('id')->get()
            ->groupBy(fn ($a) => $a->country.':'.$a->deliverer_company_id);
        $areas = [];
        $servedBy = [];
        foreach ($routes as $route) {
            $config = $route->export_config;
            $agency = $agencies->get($config['solex_agency_id'] ?? null);
            if (! $agency?->is_active || ! $agency->company?->is_active || ! preg_match('/solex/i', $agency->company->name)) {
                continue;
            }
            $polygon = $config['polygon'] ?? [];
            $mode = $config['coverage_mode'] ?? null;
            $boundary = $countries[$route->destination_country] ?? null;
            if ($mode === 'country' && $boundary) {
                $key = $route->destination_country.':'.($route->destination_city ?? '');
                $center = $boundary['center'];
                $polygons = $route->destination_city ? [] : $boundary['polygons'];
            } elseif ($mode === 'polygon' && count($polygon) >= 3) {
                $key = 'route:'.$route->id;
                $center = [array_sum(array_column($polygon, 0)) / count($polygon), array_sum(array_column($polygon, 1)) / count($polygon)];
                $polygons = [[$polygon]];
            } else {
                continue;
            }
            $areas[$key] ??= [
                'id' => $key, 'country' => $route->destination_country,
                'label' => CountryCode::name($route->destination_country).($route->destination_city ? ' · '.$route->destination_city : ''),
                'latitude' => $center[0], 'longitude' => $center[1], 'polygons' => $polygons,
                'companies' => [], 'reference_agencies' => [], 'coverage_scope' => $mode, 'provisional_rates' => false,
            ];
            $areas[$key]['companies'][$route->company->id] = $route->company->name;
            $points = $references->get($route->destination_country.':'.$route->company->id, collect());
            $verified = $points->where('is_provisional', false);
            foreach (($verified->isNotEmpty() ? $verified : $points->take(1)) as $point) {
                $areas[$key]['reference_agencies'][$point->id] = [
                    'id' => $point->id, 'name' => $point->name, 'city' => $point->city, 'country' => $point->country,
                    'company_name' => $route->company->name, 'address' => $point->address,
                    'latitude' => $point->latitude, 'longitude' => $point->longitude,
                    'is_provisional' => $point->is_provisional, 'coordinate_precision' => $point->coordinate_precision, 'source_url' => $point->source_url,
                ];
            }
            $areas[$key]['provisional_rates'] = $areas[$key]['provisional_rates'] || ($config['provisional_rates'] ?? false);
            if ($route->destination_country === $country && (! $route->destination_city || CountryCode::sameCity($city, $route->destination_city))
                && ($mode === 'country' || ExportCoverage::contains($polygon, $lat, $lng))) {
                $servedBy[$route->company->id] = ['company_id' => $route->company->id, 'company_name' => $route->company->name,
                    'type' => 'international', 'coverage_scope' => $mode, 'city' => $city, 'zone_label' => $areas[$key]['label'], 'vehicles' => []];
            }
        }
        $areas = array_values($areas);
        foreach ($areas as &$area) {
            $area['companies'] = array_values($area['companies']);
            $area['reference_agencies'] = array_values($area['reference_agencies']);
            usort($area['reference_agencies'], fn ($a, $b) => $a['is_provisional'] <=> $b['is_provisional']);
            $area['reference_point'] = $area['reference_agencies'][0] ?? [
                'latitude' => $area['latitude'], 'longitude' => $area['longitude'], 'is_provisional' => true,
            ];
        }
        unset($area);
        usort($areas, fn ($a, $b) => strcmp($a['label'], $b['label']));

        return ['areas' => $areas, 'served_by' => array_values($servedBy)];
    }
}
