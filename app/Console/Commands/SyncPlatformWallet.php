<?php

namespace App\Console\Commands;

use App\Services\PlatformWalletSync;
use Illuminate\Console\Command;

/**
 * Reporte sur le Wallet ASSO les lignes des Revenus qui n'y passent pas d'elles-mêmes
 * (forfaits, frais Mobile Money, commissions versées, courses payées par ASSO).
 */
class SyncPlatformWallet extends Command
{
    protected $signature = 'asso:sync-platform-wallet {--dry-run : Affiche les écritures sans les inscrire}';

    protected $description = 'Aligne le solde retirable ASSO sur les Revenus ASSO';

    public function handle(PlatformWalletSync $sync): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $entries = $sync->sync($dryRun);

        if (!$entries) {
            $this->info('Wallet ASSO déjà aligné sur les Revenus.');

            return self::SUCCESS;
        }

        $this->table(
            ['Source', 'Ligne', 'Référence', 'Sens', 'Montant (XAF)'],
            array_map(fn ($e) => [$e['source'], $e['source_id'], $e['reference'], $e['type'], number_format($e['amount'], 2, ',', ' ')], $entries)
        );
        $this->info(($dryRun ? 'À inscrire : ' : 'Inscrites : ') . count($entries) . ' écriture(s).');

        return self::SUCCESS;
    }
}
