<?php

namespace App\Console\Commands;

use App\Models\DelivererCompany;
use App\Models\PartnerFreightRate;
use Database\Seeders\SinoCamFreightRatesSeeder;
use Database\Seeders\SinoCamTransportDemoSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class ConfigureInternationalTransport extends Command
{
    protected $signature = 'transport:configure {--demo : Ajoute les QG et départs fictifs en local/testing uniquement}';

    protected $description = 'Initialise les tarifs internationaux sans écraser les modifications administrateur';

    public function handle(): int
    {
        if ($this->option('demo') && ! app()->environment(['local', 'testing'])) {
            $this->error('L’option --demo est interdite hors local/testing. Aucune donnée ajoutée.');

            return self::FAILURE;
        }
        if (! Schema::hasTable('partner_freight_rates') || ! Schema::hasColumn('maritime_departures', 'freight_rate_snapshot') || ! Schema::hasColumn('maritime_hubs', 'is_demo') || ! Schema::hasColumn('maritime_departures', 'is_demo')) {
            $this->error('Appliquez les migrations de transport avant cette commande (voir README).');

            return self::FAILURE;
        }
        $result = $this->call('db:seed', ['--class' => $this->option('demo') ? SinoCamTransportDemoSeeder::class : SinoCamFreightRatesSeeder::class, '--force' => true]);
        if ($result !== self::SUCCESS) {
            return self::FAILURE;
        }
        $partner = DelivererCompany::where('name', 'SinoCam Transit & Fret')->firstOrFail();
        $this->info("Partenaire : {$partner->name} (#{$partner->id}) — ".($partner->is_active ? 'actif' : 'inactif'));
        $this->table(['Mode', 'Colis', 'Coût partenaire', 'Prix client', 'Devise', 'Délai'],
            PartnerFreightRate::where('deliverer_company_id', $partner->id)->get()->map(fn ($r) => [
                $r->transport_mode, $r->cargo_category, "{$r->rate_amount}/{$r->rate_unit}",
                "{$r->public_rate_amount}/{$r->rate_unit}", $r->currency, "{$r->transit_days_min}–{$r->transit_days_max} jours",
            ])->all());
        $this->line("Administration : /admin/maritime/partners/{$partner->id}");
        $this->line($this->option('demo') ? 'QG et départs [TEST] prêts pour les essais locaux.' : 'Les adresses réelles, dates et capacités se renseignent dans l’administration avant activation.');

        return self::SUCCESS;
    }
}
