<?php

namespace App\Services;

use App\Models\DelivererCompany;
use App\Models\DeliveryAgency;
use App\Models\DeliveryCityGrid;
use App\Models\DeliveryTerritory;
use App\Support\CountryCode;
use App\Support\WeightGrid;

/** Collecte SOLEX systématique ; itinéraire hiérarchique, aucun détour implicite. */
class TerritorialDeliveryService
{
    public function quotes(array $cart, ?float $lat, ?float $lng, ?string $city, ?string $country, ?array &$issues = null): array
    {
        $issues = [];
        if (($cart['pickup_shop_count'] ?? 1) > 1) {
            return [];
        }
        if ($cart['origin'][1] !== 'CM' || $country !== 'CM') {
            return [];
        }
        $territories = DeliveryTerritory::all()->keyBy('code');
        $result = [];
        foreach (DelivererCompany::where('is_active', true)->where('territorial_network_enabled', true)->get() as $company) {
            $companyWeightExceeded = $company->max_weight_kg && $cart['weight_kg'] > $company->max_weight_kg;
            $grids = $company->cityGrids()->whereNotNull('department_code')->where('is_active', true)->get();
            $routes = $company->deliveryRoutes()->whereNotNull('network_key')->where('is_active', true)->get();
            $agencies = DeliveryAgency::where('deliverer_company_id', $company->id)->where('is_active', true)
                ->where('address', '!=', '')->where('phone', '!=', '')->get();
            foreach ($grids as $origin) {
                foreach ($origin->circularMatches($cart['origin_lat'], $cart['origin_lng']) as $from) {
                    foreach ($grids as $destination) {
                        $matches = $destination->circularMatches($lat, $lng);
                        $department = $territories->get($destination->department_code);
                        if (! $department) {
                            continue;
                        }
                        $cityMatches = CountryCode::sameCity($destination->city, $city)
                            || $territories->where('parent_code', $department->code)->contains(fn ($a) => CountryCode::sameCity($a->name, $city));
                        if (! $matches && ! $cityMatches) {
                            continue;
                        }
                        $path = $this->path($origin->department_code, $department->code, $territories, $routes);
                        if ($path === null) {
                            continue;
                        }
                        if ($companyWeightExceeded) {
                            $issues[] = $this->issue($company, 'weight_limit', $destination->city, 'transport', (float) $company->max_weight_kg);
                            continue;
                        }
                        $transport = [];
                        foreach ($path as $route) {
                            $price = WeightGrid::price($route->pricing_data ?? [], $cart['weight_kg']);
                            if (! $price) {
                                continue 2;
                            }
                            $transport[] = ['kind' => $route->network_level, 'route_id' => $route->id,
                                'label' => $route->label(), 'price' => $price['price'], 'lead_time' => $route->lead_time] + $price;
                        }
                        $agency = $agencies->first(fn ($a) => CountryCode::sameCity($a->city, $destination->city));
                        if (!$agency) {
                            $issues[] = $this->issue($company, 'agency_unavailable', $destination->city, 'agency_pickup');
                        }
                        $sourceHub = $origin->zone($origin->agency_zone);
                        $targetHub = $destination->zone($destination->agency_zone);
                        $collection = ! empty($sourceHub['is_active'])
                            ? $origin->vehicleFor($cart['weight_kg'], $from['code'], $origin->agency_zone) : null;
                        if (!$collection && !empty($sourceHub['is_active'])) {
                            $issues[] = $this->vehicleIssue($company, $origin, $cart['weight_kg'], 'collection');
                        }
                        if ($collection && $agency && ! empty($targetHub['is_active'])) {
                            $legs = [$this->localLeg('collection', $origin, $from, $sourceHub, $collection), ...$transport];
                            $result[] = $this->offer($company, $cart, $origin, $from, $destination,
                                $targetHub, 'agency_pickup', $collection['vehicle'], $legs, $agency);
                        }
                        foreach ($matches as $to) {
                            if ($origin->id === $destination->id) {
                                $direct = $origin->vehicleFor($cart['weight_kg'], $from['code'], $to['code']);
                                if (! $direct) {
                                    $issues[] = $this->vehicleIssue($company, $origin, $cart['weight_kg'], 'home_delivery');
                                    continue;
                                }
                                $legs = [$this->localLeg('collection_delivery', $origin, $from, $to, $direct)];
                                $vehicle = $direct['vehicle'];
                            } else {
                                $last = ! empty($targetHub['is_active'])
                                    ? $destination->vehicleFor($cart['weight_kg'], $destination->agency_zone, $to['code']) : null;
                                if (!$last && !empty($targetHub['is_active'])) {
                                    $issues[] = $this->vehicleIssue($company, $destination, $cart['weight_kg'], 'home_delivery');
                                }
                                if (! $collection || ! $last) {
                                    continue;
                                }
                                $legs = [$this->localLeg('collection', $origin, $from, $sourceHub, $collection),
                                    ...$transport, $this->localLeg('distribution', $destination, $targetHub, $to, $last)];
                                $vehicle = $last['vehicle'];
                            }
                            $result[] = $this->offer($company, $cart, $origin, $from, $destination,
                                $to, 'home_delivery', $vehicle, $legs);
                        }
                    }
                }
            }
        }
        // Les cercles peuvent se chevaucher : prix le plus bas, puis priorité/distance
        // de la zone et identifiants stables ; une offre par partenaire et option.
        usort($result, fn ($a, $b) => [$a['carrier_price_ht'], $a['selection_rank'], $a['grid_id'], $a['vehicle']]
            <=> [$b['carrier_price_ht'], $b['selection_rank'], $b['grid_id'], $b['vehicle']]);

        $issues = collect($issues)->unique(fn ($issue) => json_encode($issue))->values()->all();
        return collect($result)->unique(fn ($q) => $q['company_id'].'-'.$q['delivery_option'])->values()->all();
    }

    private function issue(DelivererCompany $company, string $reason, string $city, string $stage, ?float $maxWeight = null): array
    {
        return ['company_id' => $company->id, 'company_name' => $company->name,
            'reason' => $reason, 'city' => $city, 'stage' => $stage, 'max_weight_kg' => $maxWeight];
    }

    private function vehicleIssue(DelivererCompany $company, DeliveryCityGrid $grid, float $weight, string $stage): array
    {
        $unlimited = collect($grid->vehicles)->contains(fn ($vehicle) => empty($vehicle['max_weight_kg']));
        $max = $unlimited ? null : (float) collect($grid->vehicles)->max('max_weight_kg');
        return $this->issue($company, $max !== null && $weight > $max ? 'weight_limit' : 'local_price_missing', $grid->city, $stage, $max);
    }

    private function path(string $from, string $to, $territories, $routes): ?array
    {
        if ($from === $to) {
            return [];
        }
        $a = $territories->get($from);
        $b = $territories->get($to);
        if (! $a || ! $b) {
            return null;
        }
        $pairs = [];
        if ($a->parent_code === $b->parent_code) {
            $pairs[] = [$from, $to];
        } else {
            $ra = $territories->get($a->parent_code);
            $rb = $territories->get($b->parent_code);
            $ha = $ra?->metadata['hub_department_code'] ?? null;
            $hb = $rb?->metadata['hub_department_code'] ?? null;
            if (! $ha || ! $hb) {
                return null;
            }
            if ($from !== $ha) {
                $pairs[] = [$from, $ha];
            }
            $pairs[] = [$a->parent_code, $b->parent_code];
            if ($to !== $hb) {
                $pairs[] = [$hb, $to];
            }
        }
        $path = [];
        foreach ($pairs as [$start, $end]) {
            $route = $routes->first(fn ($r) => ($r->origin_territory_code === $start && $r->destination_territory_code === $end)
                || ($r->bidirectional && $r->origin_territory_code === $end && $r->destination_territory_code === $start));
            if (! $route) {
                return null;
            }
            $path[] = $route;
        }

        return $path;
    }

    private function localLeg(string $kind, DeliveryCityGrid $grid, array $from, array $to, array $choice): array
    {
        return ['kind' => $kind, 'label' => __('delivery.network.'.$kind), 'grid_id' => $grid->id,
            'vehicle' => $choice['vehicle']['code'], 'vehicle_label' => $choice['vehicle']['label'],
            'from' => $this->zoneSnapshot($grid, $from), 'to' => $this->zoneSnapshot($grid, $to),
            'price' => $choice['price'], 'lead_time' => $choice['vehicle']['lead_time'] ?? null]
            + array_intersect_key($choice, array_flip(['billing', 'included_weight_kg', 'range_price', 'extra_kg', 'extra_per_kg', 'extra_price']));
    }

    private function zoneSnapshot(DeliveryCityGrid $grid, array $zone): array
    {
        return ['department_code' => $grid->department_code, 'territory_code' => $zone['territory_code'] ?? null,
            'zone' => $zone['code'], 'label' => $zone['label'], 'latitude' => $zone['center_latitude'],
            'longitude' => $zone['center_longitude'], 'radius_km' => $zone['radius_km']];
    }

    private function offer(DelivererCompany $company, array $cart, DeliveryCityGrid $origin, array $from,
        DeliveryCityGrid $destination, array $to, string $option, array $vehicle, array $legs, ?DeliveryAgency $agency = null): array
    {
        $level = collect($legs)->contains('kind', 'interregional') ? 'interregional'
            : (collect($legs)->contains('kind', 'intraregional') ? 'intraregional' : 'local');

        return ['company' => $company, 'company_id' => $company->id, 'carrier_price_ht' => array_sum(array_column($legs, 'price')),
            'selection_rank' => [-(int) ($from['priority'] ?? 0), $from['distance_km'] ?? 0, -(int) ($to['priority'] ?? 0), $to['distance_km'] ?? 0],
            'delivery_mode' => ($option === 'agency_pickup' || count($legs) > 1) ? 'carrier' : 'local', 'service_type' => $origin->id === $destination->id ? 'local' : 'intercity',
            'network_level' => $level, 'service_type_label' => __('delivery.network.'.$level),
            'company_description' => __('delivery.network.description'),
            'conditions' => __('delivery.network.conditions'),
            'service_mode_label' => __('delivery.network.'.($option === 'agency_pickup' ? 'agency_mode' : 'home_mode')),
            'service_mode' => $option === 'agency_pickup' ? DelivererCompany::MODE_AGENCY : DelivererCompany::MODE_DOOR,
            'delivery_option' => $option, 'zone_id' => null,
            'zone_name' => $to['label'], 'route_id' => collect($legs)->firstWhere('route_id', '!=', null)['route_id'] ?? null,
            'route_label' => $from['label'].' → '.$to['label'], 'city' => $destination->city,
            'grid_id' => $destination->id, 'vehicle' => $option.':'.$vehicle['code'], 'vehicle_label' => $vehicle['label'],
            'pricing_type' => 'territorial_network', 'price_grid' => [], 'distance_km' => null,
            'origin_zone' => $from['code'], 'destination_zone' => $to['code'], 'legs' => $legs,
            'origin_coverage' => $this->zoneSnapshot($origin, $from), 'destination_coverage' => $this->zoneSnapshot($destination, $to),
            'pickup_agency' => $agency?->toPickupArray(), 'lead_time' => collect($legs)->pluck('lead_time')->filter()->implode(' + ') ?: null,
            'sort_group' => $option === 'agency_pickup' ? 1 : 0, 'deliverer' => null];
    }
}
