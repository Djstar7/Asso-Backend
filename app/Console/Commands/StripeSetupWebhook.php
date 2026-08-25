<?php

namespace App\Console\Commands;

use App\Models\ServiceConfiguration;
use App\Services\StripeService;
use Illuminate\Console\Command;

/**
 * Déclare les endpoints webhook Stripe et enregistre leurs secrets de signature.
 *
 * Deux endpoints sont nécessaires sur la MÊME url applicative :
 *  - **plateforme** : encaissements carte (payment_intent.*, checkout.session.completed) ;
 *  - **Connect**    : virements IBAN vendeurs (payout.paid/failed) et suivi KYC
 *    (account.updated) — ces événements sont émis sur les comptes CONNECTÉS, ils
 *    n'arrivent JAMAIS sur un endpoint de compte plateforme.
 *
 * Sans l'endpoint Connect, un retrait IBAN reste indéfiniment en `processing` : rien
 * ne vient jamais le marquer `completed`.
 *
 * Exemples :
 *   php artisan stripe:webhook --show
 *   php artisan stripe:webhook --url=https://api.asso.example/api/v1/stripe/webhook
 */
class StripeSetupWebhook extends Command
{
    protected $signature = 'stripe:webhook
        {--url= : URL publique de l\'endpoint (défaut: APP_URL + /api/v1/stripe/webhook)}
        {--show : Liste les endpoints existants sans rien créer}';

    protected $description = 'Déclare les webhooks Stripe (plateforme + Connect) et enregistre les secrets';

    /** Événements émis sur le compte plateforme (encaissements). */
    private const PLATFORM_EVENTS = [
        'payment_intent.succeeded',
        'payment_intent.payment_failed',
        'payment_intent.canceled',
        'checkout.session.completed',
    ];

    /** Événements émis sur les comptes connectés (virements vendeurs + KYC). */
    private const CONNECT_EVENTS = [
        'payout.paid',
        'payout.failed',
        'account.updated',
    ];

    public function handle(StripeService $stripe): int
    {
        if (!$stripe->isConfigured()) {
            $this->error('❌ Clés API Stripe manquantes. Lancez d\'abord : php artisan stripe:keys');
            return self::FAILURE;
        }

        $this->line("Mode Stripe : <info>{$stripe->mode()}</info>");

        $existing = $stripe->listWebhookEndpoints();
        $this->renderEndpoints($existing);

        if ($this->option('show')) {
            return self::SUCCESS;
        }

        $url = $this->option('url') ?: rtrim((string) config('app.url'), '/') . '/api/v1/stripe/webhook';

        if (!filter_var($url, FILTER_VALIDATE_URL) || str_starts_with($url, 'http://localhost')) {
            $this->error("❌ URL inutilisable par Stripe : {$url}");
            $this->line('   Stripe doit pouvoir l\'appeler depuis Internet (https public).');
            $this->line('   En local, utilisez : stripe listen --forward-to localhost:8000/api/v1/stripe/webhook');
            return self::FAILURE;
        }

        $this->line("Endpoint visé : <info>{$url}</info>");

        $config = ServiceConfiguration::getStripeConfig() ?? [];
        $changed = false;

        foreach ([
            ['plateforme', self::PLATFORM_EVENTS, false, 'webhook_secret'],
            ['Connect', self::CONNECT_EVENTS, true, 'webhook_secret_connect'],
        ] as [$label, $events, $connect, $key]) {
            $result = $stripe->ensureWebhookEndpoint($url, $events, $connect);

            if (!$result['created']) {
                $this->line("• Endpoint {$label} déjà déclaré ({$result['id']}) — secret inchangé.");
                continue;
            }

            $this->info("✅ Endpoint {$label} créé : {$result['id']}");

            if (!empty($result['secret'])) {
                $config[$key] = $result['secret'];
                $changed = true;
            }
        }

        if ($changed) {
            ServiceConfiguration::setConfig(
                ServiceConfiguration::SERVICE_STRIPE,
                $config,
                true,
                'Stripe - Connect (payouts IBAN) et paiements carte',
            );
            $this->info('✅ Secrets de signature enregistrés dans service_configurations.');
        }

        // Rappel : sans secret Connect, les payout.* seront rejetés en signature invalide.
        if (empty($config['webhook_secret_connect'])) {
            $this->warn('⚠️  Aucun secret Connect enregistré : si l\'endpoint existait déjà, '
                . 'récupérez son secret dans le dashboard Stripe puis lancez '
                . 'php artisan stripe:keys --webhook-connect=whsec_xxx');
        }

        return self::SUCCESS;
    }

    private function renderEndpoints(array $endpoints): void
    {
        if (empty($endpoints)) {
            $this->warn('⚠️  Aucun endpoint webhook déclaré sur ce compte Stripe.');
            return;
        }

        $this->table(
            ['id', 'url', 'statut', 'portée', 'événements'],
            array_map(fn ($e) => [
                $e['id'],
                $e['url'],
                $e['status'],
                $e['connect'] ? 'Connect' : 'plateforme',
                implode(', ', array_slice($e['events'], 0, 4)) . (count($e['events']) > 4 ? ', …' : ''),
            ], $endpoints),
        );
    }
}
