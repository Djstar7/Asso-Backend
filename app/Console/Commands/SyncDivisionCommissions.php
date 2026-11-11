<?php

namespace App\Console\Commands;

use App\Services\DivisionCommissionService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Filet de sécurité des commissions des chefs de rubrique : crée celles d'une
 * vente réglée qui n'en a pas, verse celles dont la vente est acquise, annule ou
 * reprend celles d'une vente remboursée. Idempotent.
 */
class SyncDivisionCommissions extends Command
{
    protected $signature = 'division:sync-commissions {--days= : Ventes réglées des N derniers jours seulement (défaut : depuis la première prise de poste)}';

    protected $description = 'Crée, verse, annule ou reprend les commissions des chefs de rubrique';

    public function handle(DivisionCommissionService $service): int
    {
        $since = $this->option('days') !== null ? Carbon::now()->subDays((int) $this->option('days')) : null;
        $result = $service->sync($since);

        $this->info("Commissions créées : {$result['recorded']} ; commandes mises à jour : {$result['reconciled']}.");

        return self::SUCCESS;
    }
}
