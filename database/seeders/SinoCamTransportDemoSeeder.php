<?php

namespace Database\Seeders;

use App\Models\DelivererCompany;
use App\Models\MaritimeDeparture;
use App\Models\MaritimeHub;
use App\Models\PartnerFreightRate;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/** Fixtures locales explicites, séparées du paramétrage déployable. */
class SinoCamTransportDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('Les QG et départs de démonstration sont réservés aux environnements local et testing.');
        }
        DB::transaction(function () {
            $this->call(SinoCamFreightRatesSeeder::class);
            $partner = DelivererCompany::where('name', 'SinoCam Transit & Fret')->lockForUpdate()->firstOrFail();
            $hubs = [];
            foreach ([['CN', 'Guangzhou'], ['FR', 'Paris'], ['CM', 'Douala']] as [$country, $city]) {
                $hubs[$country] = MaritimeHub::firstOrCreate([
                    'deliverer_company_id' => $partner->id,
                    'name' => "[TEST] SinoCam — {$city}",
                    'is_demo' => true,
                ], [
                    'country' => $country, 'city' => $city,
                    'address' => "ADRESSE FICTIVE DE TEST — {$city} — aucun dépôt réel",
                    'opening_hours' => 'Démonstration uniquement',
                    'is_active' => true,
                ]);
            }
            $date = today()->addDays(max(MaritimeDeparture::bookingCutoffDays(), MaritimeDeparture::dropoffDaysBefore()) + 14);
            foreach (PartnerFreightRate::where('deliverer_company_id', $partner->id)->where('origin_country', 'CN')->where('destination_country', 'CM')->where('is_active', true)->get() as $rate) {
                // Une relance conserve dates, capacité restante, tarifs et réservations existantes.
                $exists = MaritimeDeparture::where('deliverer_company_id', $partner->id)
                    ->where('partner_freight_rate_id', $rate->id)->where('is_demo', true)
                    ->whereIn('status', [MaritimeDeparture::STATUS_OPEN, MaritimeDeparture::STATUS_CLOSED])
                    ->whereDate('departure_date', '>=', today())->exists();
                if ($exists) {
                    continue;
                }
                $cbm = $rate->rate_unit === 'cbm';
                $capacity = $rate->transport_mode === 'air' ? 100 : 1000;
                MaritimeDeparture::create([
                    'deliverer_company_id' => $partner->id,
                    'origin_hub_id' => $hubs['CN']->id, 'destination_hub_id' => $hubs['CM']->id,
                    'departure_date' => $date, 'estimated_arrival_date' => $date->copy()->addDays($rate->transit_days_max),
                    'capacity_kg' => $capacity, 'remaining_kg' => $capacity,
                    'price_per_cbm' => $cbm ? $rate->rate_amount : null,
                    'kg_per_cbm' => $cbm ? MaritimeDeparture::KG_PER_CBM : null,
                    'price_per_kg' => $cbm ? round((float) $rate->rate_amount / MaritimeDeparture::KG_PER_CBM, 2) : $rate->rate_amount,
                    'currency' => $rate->currency, 'status' => MaritimeDeparture::STATUS_OPEN,
                    'partner_freight_rate_id' => $rate->id, 'freight_rate_snapshot' => $rate->snapshot(),
                    'is_demo' => true, 'notes' => '[TEST] Départ fictif réservé aux essais locaux. Aucun transport réel.',
                ]);
            }
            // Activation locale explicite pour tester les catalogues Avion et Maritime.
            $partner->update(['is_active' => true]);
        });
    }
}
