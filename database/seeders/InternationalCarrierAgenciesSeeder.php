<?php

namespace Database\Seeders;

use App\Models\DelivererCompany;
use App\Models\DeliveryAgency;
use App\Support\CountryCode;
use App\Support\DeliveryCountryBoundaries;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/** Agency references never reduce the country-wide international delivery coverage. */
class InternationalCarrierAgenciesSeeder extends Seeder
{
    public function run(): void
    {
        $locations = json_decode(file_get_contents(database_path('data/international-carrier-agencies.json')), true, flags: JSON_THROW_ON_ERROR)['locations'];
        $boundaries = DeliveryCountryBoundaries::all();
        DB::transaction(function () use ($locations, $boundaries) {
            foreach (['DHL Express' => 'DHL', 'FedEx' => 'FDX'] as $name => $code) {
                $company = DelivererCompany::where('name', $name)->first();
                if (! $company) {
                    continue;
                }
                foreach (CountryCode::NAMES as $country => $label) {
                    if ($country === 'CM' || ! isset($boundaries[$country])) {
                        continue;
                    }
                    [$lat, $lng] = $boundaries[$country]['center'];
                    DeliveryAgency::firstOrCreate(['code' => "INT-REF-{$code}-{$country}"], [
                        'deliverer_company_id' => $company->id, 'country' => $country,
                        'name' => mb_substr("{$name} — repère provisoire {$label}", 0, 150),
                        'city' => mb_substr($label, 0, 120),
                        'address' => 'Point de navigation uniquement — adresse d’agence à confirmer',
                        'phone' => 'À confirmer', 'latitude' => $lat, 'longitude' => $lng,
                        'is_active' => true, 'is_international_reference' => true, 'is_provisional' => true,
                        'source_dataset' => 'international-reference-points-v1',
                    ]);
                }
                foreach ($locations as $location) {
                    if ($location['carrier'] !== $name || ! isset(CountryCode::NAMES[$location['country']])) {
                        continue;
                    }
                    $key = "INT-SRC-{$code}-".strtoupper(substr(sha1($location['source_url']), 0, 18));
                    DeliveryAgency::firstOrCreate(['code' => $key], [
                        'deliverer_company_id' => $company->id, 'country' => $location['country'],
                        'name' => mb_substr($location['name'], 0, 150), 'city' => mb_substr($location['city'], 0, 120),
                        'address' => mb_substr($location['address'], 0, 255), 'phone' => $location['phone'] ?? 'À confirmer',
                        'latitude' => $location['latitude'], 'longitude' => $location['longitude'],
                        'is_active' => true, 'is_international_reference' => true, 'is_provisional' => false,
                        'source_url' => $location['source_url'], 'coordinate_precision' => $location['coordinate_precision'] ?? 'published', 'verified_at' => $location['verified_on'],
                        'source_dataset' => 'official-carrier-directories-v1',
                    ]);
                }
            }
        });
    }
}
