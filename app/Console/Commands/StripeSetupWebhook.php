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
        {--show : Liste les endpoints existants sans rien créer}
        {--prune : Supprime les endpoints dont l\'URL n\'est pas joignable par Stripe}';

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

        if ($this->option('prune')) {
            return $this->prune($stripe, $existing);
        }

        $url = $this->option('url') ?: rtrim((string) config('app.url'), '/') . '/api/v1/stripe/webhook';

        // Stripe ACCEPTE de créer un endpoint vers une adresse locale (localhost,
        // 192.168.x.x, ngrok expiré…) mais ne pourra jamais l'appeler : les retraits
        // resteraient bloqués en « processing » sans le moindre message d'erreur.
        if (!$stripe->isPubliclyReachableUrl($url)) {
            $this->error("❌ URL injoignable depuis Internet : {$url}");
            $this->line('   Stripe n\'accepte qu\'un domaine public (une IP locale ou publique ne suffit pas).');
            $this->line('   • Production  : php artisan stripe:webhook --url=https://api.mondomaine.com/api/v1/stripe/webhook');
            $this->line('   • Développement : stripe listen --forward-to localhost:8000/api/v1/stripe/webhook');
            $this->line('     (la CLI affiche un secret whsec_… → php artisan stripe:keys --webhook=whsec_… --webhook-connect=whsec_…)');
            return self::FAILURE;
        }

        if (str_starts_with($url, 'http://')) {
            $this->warn('⚠️  URL en http:// : les événements circuleront en clair. Préférez https.');
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

    /**
     * Supprime les endpoints dont l'URL n'est pas joignable par Stripe : ils ne
     * reçoivent rien et leurs secrets, eux, sont bien enregistrés — de quoi croire
     * la chaîne en place alors qu'aucun événement n'arrivera.
     */
    private function prune(StripeService $stripe, array $endpoints): int
    {
        $dead = array_filter($endpoints, fn ($e) => !$stripe->isPubliclyReachableUrl($e['url']));

        if (empty($dead)) {
            $this->info('✅ Aucun endpoint injoignable à supprimer.');
            return self::SUCCESS;
        }

        $this->warn(count($dead) . ' endpoint(s) injoignable(s) :');
        foreach ($dead as $e) {
            $this->line("   • {$e['id']} → {$e['url']}");
        }

        if (!$this->confirm('Les supprimer ?', true)) {
            return self::SUCCESS;
        }

        foreach ($dead as $e) {
            $ok = $stripe->deleteWebhookEndpoint($e['id']);
            $this->line($ok ? "   supprimé : {$e['id']}" : "   ÉCHEC : {$e['id']}");
        }

        $this->warn('⚠️  Les secrets enregistrés correspondaient à ces endpoints : '
            . 'relancez la commande avec l\'URL publique pour en obtenir de nouveaux.');

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
