<?php

namespace Database\Seeders;

use App\Models\DeliveryRoute;
use Illuminate\Database\Seeder;

/** Independent provisional return tariffs; real administrator tariffs are never inferred. */
class InternationalReturnPricingSeeder extends Seeder
{
    public function run(): void
    {
        DeliveryRoute::whereNotNull('export_config')->whereHas('company', fn ($q) => $q->whereIn('name', ['DHL Express', 'FedEx']))
            ->orderBy('id')->each(function ($route) {
            $config = $route->export_config;
            if (!($config['provisional_rates'] ?? false) || array_key_exists('return_pricing', $config)) return;
            $config['return_pricing'] = $route->pricing_data;
            $config['return_pricing_provisional'] = true;
            $route->update(['export_config' => $config]);
        });
    }
}
