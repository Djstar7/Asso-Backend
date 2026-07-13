<?php

namespace App\Console\Commands;

use App\Models\ServiceConfiguration;
use App\Services\KPayService;
use Illuminate\Console\Command;

/**
 * Renseigne (ou met à jour) les clés API KPay dans service_configurations,
 * puis teste la connexion. Prêt pour les clés de test comme de production.
 *
 * Exemples :
 *   php artisan kpay:keys --api=kpay_test_xxx --secret=sk_test_xxx --webhook=whsec_xxx
 *   php artisan kpay:keys --api=kpay_live_xxx --secret=sk_live_xxx --mode=live
 *   php artisan kpay:keys --show
 */
class KpaySetKeys extends Command
{
    protected $signature = 'kpay:keys
        {--api= : Clé publique X-API-Key (kpay_test_... / kpay_live_...)}
        {--secret= : Clé secrète X-Secret-Key (sk_test_... / sk_live_...)}
        {--webhook= : Secret de signature des webhooks}
        {--mode= : Environnement (sandbox|live) — déduit du préfixe de clé si omis}
        {--base= : URL de base (défaut https://admin.kpay.site)}
        {--show : Affiche la configuration actuelle sans la modifier}
        {--test : Teste la connexion après enregistrement}';

    protected $description = 'Configure les clés API KPay et teste la connexion';

    public function handle(): int
    {
        $config = ServiceConfiguration::getKpayConfig() ?? [];

        if ($this->option('show')) {
            $this->renderConfig($config);
            return self::SUCCESS;
        }

        $api = $this->option('api');
        $secret = $this->option('secret');

        if (!$api && !$secret && !$this->option('webhook') && !$this->option('base') && !$this->option('mode')) {
            $api = $this->ask('Clé API (X-API-Key)', $config['api_key'] ?? null);
            $secret = $this->secret('Clé secrète (X-Secret-Key)') ?: ($config['secret_key'] ?? '');
        }

        $mode = $this->option('mode');
        if (!$mode && $api) {
            $mode = str_starts_with($api, 'kpay_live_') ? 'live' : 'sandbox';
        }

        $new = array_merge([
            'base_url' => 'https://admin.kpay.site',
            'mode' => 'sandbox',
            'api_key' => '',
            'secret_key' => '',
            'webhook_secret' => '',
        ], $config);

        if ($api !== null) $new['api_key'] = $api;
        if ($secret !== null) $new['secret_key'] = $secret;
        if ($this->option('webhook') !== null) $new['webhook_secret'] = $this->option('webhook');
        if ($this->option('base') !== null) $new['base_url'] = $this->option('base');
        if ($mode !== null) $new['mode'] = $mode;

        ServiceConfiguration::setConfig(ServiceConfiguration::SERVICE_KPAY, $new, true, 'KPay - Paiements et retraits Mobile Money');

        $this->info('✅ Configuration KPay enregistrée.');
        $this->renderConfig($new);

        if ($this->option('test') || $this->confirm('Tester la connexion KPay maintenant ?', true)) {
            $result = (new KPayService())->testConnection();
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
            ['base_url', $config['base_url'] ?? '—'],
            ['mode', $config['mode'] ?? '—'],
            ['api_key', $mask($config['api_key'] ?? '')],
            ['secret_key', $mask($config['secret_key'] ?? '')],
            ['webhook_secret', $mask($config['webhook_secret'] ?? '')],
        ]);
    }
}
