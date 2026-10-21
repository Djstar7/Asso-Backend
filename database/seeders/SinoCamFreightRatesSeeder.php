<?php

namespace Database\Seeders;

use App\Models\DelivererCompany;
use App\Models\PartnerFreightRate;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SinoCamFreightRatesSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $partner = DelivererCompany::firstOrCreate(['name' => 'SinoCam Transit & Fret'], [
                'service_type' => DelivererCompany::SERVICE_MARITIME,
                'service_mode' => DelivererCompany::MODE_AGENCY,
                'is_active' => false,
                'description' => 'Transport maritime et aérien Chine–Cameroun. Contact : Wilfrield MANGA.',
                'conditions' => 'Informations communiquées le 20/10/2026 : fret Chine → Cameroun payable à l’arrivée des colis dans l’entrepôt au Cameroun. Pour l’Europe, l’Amérique et le Canada, règlement en Chine. Le circuit de paiement et de reversement ASSO reste celui de la plateforme.',
            ]);
            if ($partner->service_type !== DelivererCompany::SERVICE_MARITIME) {
                throw new \RuntimeException('Le nom SinoCam Transit & Fret appartient déjà à un partenaire d’un autre type.');
            }
            foreach ([
                ['sea', 'general', 'cbm', 330000, 450000, 45, 55],
                ['sea', 'sensitive', 'cbm', 390000, 500000, 45, 55],
                ['air', 'general', 'kg', 12000, 20000, 10, 14],
            ] as [$mode, $category, $unit, $amount, $publicAmount, $min, $max]) {
                PartnerFreightRate::firstOrCreate([
                    'deliverer_company_id' => $partner->id,
                    'origin_country' => 'CN', 'destination_country' => 'CM',
                    'transport_mode' => $mode, 'cargo_category' => $category,
                ], [
                    'rate_unit' => $unit, 'rate_amount' => $amount, 'public_rate_amount' => $publicAmount, 'frequency_days' => null, 'currency' => 'XAF',
                    'transit_days_min' => $min, 'transit_days_max' => $max,
                    'freight_payment_timing' => 'arrival', 'is_active' => true,
                    'notes' => 'Fret partenaire payable à l’arrivée à l’entrepôt au Cameroun, selon les informations du 20/10/2026. Encaissement et reversement ASSO selon le circuit existant.',
                ]);
            }
        });
    }
}
