<?php

namespace Database\Seeders;

use App\Models\DeliveryAgency;
use App\Models\DeliveryRoute;
use Illuminate\Database\Seeder;

/** Adopt an existing unambiguous collection rate without resetting edited agency prices. */
class SolexCollectionPricingSeeder extends Seeder
{
    public function run(): void
    {
        $routes = DeliveryRoute::whereNotNull('export_config')->get()->groupBy(fn ($route) => $route->export_config['solex_agency_id'] ?? 0);
        foreach ($routes as $agencyId => $group) {
            $agency = DeliveryAgency::with('company')->find($agencyId);
            if (!$agency || $agency->collection_pricing !== null || !preg_match('/solex/i', $agency->company?->name ?? '')) continue;
            $prices = $group->map(fn ($route) => $route->export_config['collection_pricing'] ?? null)
                ->filter()->unique(fn ($pricing) => json_encode($pricing))->values();
            if ($prices->count() === 1) {
                DeliveryAgency::whereKey($agencyId)->whereNull('collection_pricing')->update(['collection_pricing' => $prices->first()]);
            }
        }
    }
}
