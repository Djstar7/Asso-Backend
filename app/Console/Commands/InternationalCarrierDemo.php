<?php

namespace App\Console\Commands;

use App\Services\InternationalCarrierDemoService;
use Illuminate\Console\Command;

class InternationalCarrierDemo extends Command
{
    protected $signature = 'delivery:demo-international
        {--dry-run : Aperçu sans écriture}
        {--remove : Supprimer les trajets de démonstration inutilisés et désactiver ceux liés à une commande}';

    protected $description = 'Charge ou retire les tarifs de démonstration DHL/FedEx au kilo, sans écraser les réglages existants.';

    public function handle(InternationalCarrierDemoService $service): int
    {
        try {
            $counts = $this->option('remove')
                ? $service->remove((bool) $this->option('dry-run'))
                : $service->load((bool) $this->option('dry-run'));
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->info($this->option('dry-run') ? 'Aperçu : aucune donnée modifiée.' : 'Opération terminée.');
        $this->table(array_keys($counts), [array_values($counts)]);
        if (! $this->option('remove')) {
            $this->line('Tarifs et couvertures de démonstration, modifiables dans Partenaires logistiques → Trajets et international.');
        }

        return self::SUCCESS;
    }
}
