<?php

namespace App\Console\Commands;

use App\Models\Supplier;
use App\Services\Suppliers\SupplierManager;
use Illuminate\Console\Command;

/**
 * État de la connexion CJdropshipping : teste la clé, renouvelle le jeton si
 * besoin et relève le solde. Exemple : php artisan cj:status
 */
class CjStatus extends Command
{
    protected $signature = 'cj:status';

    protected $description = 'Teste la connexion CJdropshipping et affiche le solde';

    public function handle(SupplierManager $suppliers): int
    {
        $driver = $suppliers->driver(Supplier::CJ);
        $result = $driver->testConnection();
        $cj = $driver->supplier()->refresh();

        $this->table(['Élément', 'Valeur'], [
            ['Clé API', $cj->maskedApiKey() ?? 'absente'],
            ['Mode', $cj->is_sandbox ? 'bac à sable' : 'production'],
            ['Actif', $cj->is_active ? 'oui' : 'non'],
            ['Jeton valable jusqu’au', $cj->access_token_expires_at?->format('d/m/Y H:i') ?? '-'],
            ['Solde', $cj->balance_usd !== null ? number_format((float) $cj->balance_usd, 2).' USD' : '-'],
        ]);

        if (! $result['ok']) {
            $this->error($result['message']);

            return self::FAILURE;
        }

        $this->info($result['message']);
        if ($cj->balanceIsLow()) {
            $this->warn('Solde sous le seuil d’alerte ('.$cj->setting('balance_alert_usd').' USD).');
        }

        return self::SUCCESS;
    }
}
