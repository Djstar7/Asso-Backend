<?php

namespace Database\Seeders;

use App\Models\DelivererCompany;
use App\Models\MaritimeDeparture;
use App\Models\MaritimeHub;
use App\Models\PartnerFreightRate;
use App\Services\PartnerAccountService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/** Tarifs réels uniquement ; relançable sans réécrire les prix ni les envois. */
class InternationalTransportProductionSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            if (! \App\Services\SupportService::user()) {
                $support = \App\Models\User::firstOrCreate(['email' => \App\Services\SupportService::EMAIL], [
                    'first_name' => 'Support', 'last_name' => 'ASSO', 'role' => 'client',
                    'password' => \Illuminate\Support\Facades\Hash::make(\Illuminate\Support\Str::random(48)),
                    'email_verified_at' => now(),
                ]);
                \App\Models\Setting::set('support_user_id', $support->id, 'integer', 'general', 'Compte support ASSO (messagerie client)');
            }
            $this->call(SinoCamFreightRatesSeeder::class);
            $partner = DelivererCompany::where('name', 'SinoCam Transit & Fret')->lockForUpdate()->firstOrFail();
            PartnerAccountService::accountFor($partner);
            foreach ([['air', 'kg', 12000, 20000, 'XAF'], ['sea', 'cbm', 900, null, 'EUR']] as [$mode, $unit, $cost, $price, $currency]) {
                PartnerFreightRate::firstOrCreate([
                    'deliverer_company_id' => $partner->id,
                    'origin_country' => 'FR', 'destination_country' => 'CM',
                    'transport_mode' => $mode, 'cargo_category' => 'general',
                ], [
                    'rate_unit' => $unit, 'rate_amount' => $cost, 'public_rate_amount' => $price,
                    'frequency_days' => null, 'currency' => $currency,
                    'transit_days_min' => null, 'transit_days_max' => null,
                    'freight_payment_timing' => 'departure', 'is_active' => true,
                    'notes' => 'Tarif confirmé par ASSO. Délais, QG, dates et capacités à renseigner avant ouverture des départs.',
                ]);
            }
            // Les seules lignes nettoyées portent explicitement le marqueur de démonstration.
            // Aucun envoi, paiement, portefeuille, QG ou départ historique n’est supprimé.
            MaritimeHub::where('deliverer_company_id', $partner->id)->where('is_demo', true)->update(['is_active' => false]);
            MaritimeDeparture::where('deliverer_company_id', $partner->id)->where('is_demo', true)
                ->where('status', MaritimeDeparture::STATUS_OPEN)->update(['status' => MaritimeDeparture::STATUS_CLOSED]);
        });
        $this->command?->info('5 tarifs initialisés et compte de règlement prêt. Complétez les QG, délais France et départs réels dans /admin/maritime. Les activations existantes sont conservées.');
    }
}
