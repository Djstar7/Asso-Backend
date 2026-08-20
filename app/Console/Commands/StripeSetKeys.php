<?php

namespace App\Console\Commands;

use App\Models\ServiceConfiguration;
use App\Services\StripeService;
use Illuminate\Console\Command;

/**
 * Renseigne (ou met à jour) les clés API Stripe dans service_configurations,
 * puis teste la connexion. Prêt pour les clés de test comme de production.
 *
 * Exemples :
 *   php artisan stripe:keys --secret=sk_test_xxx --publishable=pk_test_xxx --webhook=whsec_xxx
 *   php artisan stripe:keys --secret=sk_live_xxx --publishable=pk_live_xxx --mode=live
 *   php artisan stripe:keys --show
 */
class StripeSetKeys extends Command
{
    protected $signature = 'stripe:keys
        {--secret= : Clé secrète (sk_test_... / sk_live_...)}
        {--publishable= : Clé publique (pk_test_... / pk_live_...)}
        {--webhook= : Secret de signature des webhooks (whsec_...)}
        {--mode= : Environnement (test|live) — déduit du préfixe de clé si omis}
        {--show : Affiche la configuration actuelle sans la modifier}
        {--test : Teste la connexion après enregistrement}';

    protected $description = 'Configure les clés API Stripe et teste la connexion';

    public function handle(): int
    {
        $config = ServiceConfiguration::getStripeConfig() ?? [];

        if ($this->option('show')) {
            $this->renderConfig($config);
            return self::SUCCESS;
        }

        $secret = $this->option('secret');
        $publishable = $this->option('publishable');

        if (!$secret && !$publishable && !$this->option('webhook') && !$this->option('mode')) {
            $publishable = $this->ask('Clé publique (pk_...)', $config['publishable_key'] ?? null);
            $secret = $this->secret('Clé secrète (sk_...)') ?: ($config['secret_key'] ?? '');
        }

        $mode = $this->option('mode');
        if (!$mode && $secret) {
            $mode = str_starts_with($secret, 'sk_live_') ? 'live' : 'test';
        }

        $new = array_merge([
            'mode' => 'test',
            'secret_key' => '',
            'publishable_key' => '',
            'webhook_secret' => '',
        ], $config);

        if ($secret !== null) $new['secret_key'] = $secret;
        if ($publishable !== null) $new['publishable_key'] = $publishable;
        if ($this->option('webhook') !== null) $new['webhook_secret'] = $this->option('webhook');
        if ($mode !== null) $new['mode'] = $mode;

        ServiceConfiguration::setConfig(ServiceConfiguration::SERVICE_STRIPE, $new, true, 'Stripe - Connect (payouts IBAN) et paiements carte');

        $this->info('✅ Configuration Stripe enregistrée.');
        $this->renderConfig($new);

        if ($this->option('test') || $this->confirm('Tester la connexion Stripe maintenant ?', true)) {
            $result = (new StripeService())->testConnection();
            if ($result['success'] ?? false) {
                $this->info('✅ ' . $result['message']);
            } else {
                $this->error('❌ ' . ($result['message'] ?? 'Échec du test'));
            }
        }

        return self::SUCCESS;
    }

    private function renderConfig(array $config): void
    {
        $mask = fn($v) => $v ? substr($v, 0, 10) . str_repeat('*', 6) : '(vide)';
        $this->table(['Paramètre', 'Valeur'], [
            ['mode', $config['mode'] ?? '—'],
            ['publishable_key', $mask($config['publishable_key'] ?? '')],
            ['secret_key', $mask($config['secret_key'] ?? '')],
            ['webhook_secret', $mask($config['webhook_secret'] ?? '')],
        ]);
    }
}
