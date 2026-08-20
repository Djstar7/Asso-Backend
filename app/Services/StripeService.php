<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Stripe\StripeClient;

/**
 * Stripe Connect (Custom Accounts) + paiements carte.
 *
 * Wrapper mince du SDK Stripe : TOUT appel `\Stripe\*` reste encapsulé ici, afin
 * que les contrôleurs restent testables en mockant ce service (les tests n'ont
 * alors pas besoin de clés Stripe réelles).
 *
 * Config lue depuis `service_configurations` (service 'stripe'), avec fallback env :
 *   { publishable_key, secret_key, webhook_secret, mode }
 * Le mode (test|live) est déduit du préfixe de la clé secrète (sk_live_ / sk_test_).
 */
class StripeService
{
    private ?string $secretKey;
    private ?string $publishableKey;
    private ?string $webhookSecret;
    private string $mode;
    private ?StripeClient $client = null;

    public function __construct()
    {
        $config = \App\Models\ServiceConfiguration::getConfig('stripe') ?? [];

        $this->secretKey = $config['secret_key'] ?? env('STRIPE_SECRET');
        $this->publishableKey = $config['publishable_key'] ?? env('STRIPE_KEY');
        $this->webhookSecret = $config['webhook_secret'] ?? env('STRIPE_WEBHOOK_SECRET');
        $this->mode = $config['mode'] ?? (str_starts_with((string) $this->secretKey, 'sk_live_') ? 'live' : 'test');

        Log::debug('[StripeService] Initialized', [
            'mode' => $this->mode,
            'secret_key' => $this->secretKey ? 'SET' : 'NULL',
            'publishable_key' => $this->publishableKey ? 'SET' : 'NULL',
        ]);
    }

    /** Whether credentials are configured. */
    public function isConfigured(): bool
    {
        return !empty($this->secretKey);
    }

    public function mode(): string
    {
        return $this->mode;
    }

    public function publishableKey(): ?string
    {
        return $this->publishableKey;
    }

    public function webhookSecret(): ?string
    {
        return $this->webhookSecret;
    }

    /** Lazily build the Stripe SDK client. */
    private function client(): StripeClient
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException('Clés API Stripe manquantes.');
        }
        return $this->client ??= new StripeClient($this->secretKey);
    }

    /**
     * Test the API credentials (used by the admin config panel).
     * Retrieves the platform account — echoes back the account id / country.
     */
    public function testConnection(): array
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'message' => 'Clés API Stripe manquantes.'];
        }
        try {
            $account = $this->client()->accounts->retrieve();
            return [
                'success' => true,
                'message' => "Connexion Stripe réussie (mode {$this->mode}, compte {$account->id}).",
                'data' => ['account_id' => $account->id, 'country' => $account->country ?? null],
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Erreur Stripe: ' . $e->getMessage()];
        }
    }

    /**
     * Crée un compte Stripe Connect « Custom-equivalent » pour un vendeur, avec
     * son IBAN comme compte externe (payout).
     *
     * On n'utilise PAS le `type: 'custom'` (déprécié pour les nouvelles
     * intégrations) mais les `controller` properties recommandées, qui décrivent
     * exactement le même comportement : pas de dashboard Stripe côté vendeur, la
     * plateforme (ASSO) collecte les informations (KYC) et porte la responsabilité.
     * Réf. https://docs.stripe.com/connect/migrate-to-controller-properties
     *
     * Les données bancaires brutes (IBAN) sont autorisées côté serveur pour ces
     * comptes contrôlés par la plateforme (hors France/PSD2 où un token est requis).
     *
     * @param array $data country(2), email, first_name, last_name, iban, account_holder_name, currency?, user_id?, tos_ip?, tos_date?
     * @return array { id, external_last4, bank_country, account_holder_name }
     */
    public function createCustomAccountWithBank(array $data): array
    {
        $country = strtoupper($data['country']);
        $currency = strtolower($data['currency'] ?? $this->defaultCurrencyForCountry($country));
        $holder = $data['account_holder_name'];

        $params = [
            // Controller properties = équivalent moderne d'un compte "Custom".
            'controller' => [
                'stripe_dashboard' => ['type' => 'none'],
                'requirement_collection' => 'application',
                'losses' => ['payments' => 'application'],
                'fees' => ['payer' => 'application'],
            ],
            'country' => $country,
            'email' => $data['email'] ?? null,
            'business_type' => 'individual',
            'capabilities' => [
                'transfers' => ['requested' => true],
            ],
            'individual' => array_filter([
                'first_name' => $data['first_name'] ?? null,
                'last_name' => $data['last_name'] ?? null,
                'email' => $data['email'] ?? null,
            ]),
            'external_account' => [
                'object' => 'bank_account',
                'country' => $country,
                'currency' => $currency,
                'account_holder_name' => $holder,
                'account_holder_type' => 'individual',
                'account_number' => $data['iban'],
            ],
            'metadata' => array_filter([
                'asso_user_id' => (string) ($data['user_id'] ?? ''),
            ]),
        ];

        // requirement_collection=application → la plateforme atteste l'acceptation
        // du contrat de service Stripe par le vendeur (date + IP de la requête).
        if (!empty($data['tos_ip'])) {
            $params['tos_acceptance'] = array_filter([
                'date' => $data['tos_date'] ?? time(),
                'ip' => $data['tos_ip'],
            ]);
        }

        $account = $this->client()->accounts->create($params);

        $external = $account->external_accounts->data[0] ?? null;

        return [
            'id' => $account->id,
            'external_last4' => $external->last4 ?? substr(preg_replace('/\s+/', '', $data['iban']), -4),
            'bank_country' => $external->country ?? $country,
            'account_holder_name' => $holder,
        ];
    }

    /**
     * Remplace le compte externe (IBAN) d'un compte Connect existant.
     *
     * @return array { external_last4, bank_country }
     */
    public function replaceExternalAccount(string $accountId, array $data): array
    {
        $country = strtoupper($data['country']);
        $currency = strtolower($data['currency'] ?? $this->defaultCurrencyForCountry($country));

        $bank = $this->client()->accounts->createExternalAccount($accountId, [
            'external_account' => [
                'object' => 'bank_account',
                'country' => $country,
                'currency' => $currency,
                'account_holder_name' => $data['account_holder_name'],
                'account_holder_type' => 'individual',
                'account_number' => $data['iban'],
            ],
            'default_for_currency' => true,
        ]);

        return [
            'external_last4' => $bank->last4 ?? substr(preg_replace('/\s+/', '', $data['iban']), -4),
            'bank_country' => $bank->country ?? $country,
        ];
    }

    public function retrieveAccount(string $accountId): \Stripe\Account
    {
        return $this->client()->accounts->retrieve($accountId);
    }

    /**
     * Vérifie la signature d'un webhook Stripe et retourne l'événement typé.
     * Lève une exception si la signature est invalide ou le secret manquant.
     */
    public function constructWebhookEvent(string $payload, string $sigHeader): \Stripe\Event
    {
        if (empty($this->webhookSecret)) {
            throw new \RuntimeException('Webhook secret Stripe non configuré.');
        }

        return \Stripe\Webhook::constructEvent($payload, $sigHeader, $this->webhookSecret);
    }

    /**
     * Verse un montant au vendeur sur son IBAN (compte Connect déjà validé).
     *
     * Deux étapes :
     *  1) **Transfer** plateforme → compte Connect du vendeur. C'est le mouvement
     *     d'argent AUTORITATIF : s'il échoue (ex. solde plateforme Stripe insuffisant),
     *     on lève une exception et le retrait est annulé (le wallet est recrédité par
     *     le rollback côté contrôleur).
     *  2) **Payout** compte Connect → IBAN. Best-effort : si le compte est configuré
     *     en versement automatique (ou si les fonds ne sont pas encore « available »),
     *     Stripe versera de lui-même vers l'IBAN ; on n'échoue donc PAS le retrait,
     *     on renvoie simplement `payout_id = null`.
     *
     * ⚠️ Le montant est exprimé dans l'unité principale de la devise (ex. euros) et
     * converti ici en plus petite unité (centimes). Valable pour EUR/GBP/USD (2
     * décimales) — les seules devises de payout supportées ici.
     *
     * @return array { id, transfer_id, payout_id, amount_minor, currency }
     */
    public function payoutToVendor(string $accountId, float $amount, string $currency): array
    {
        $currency = strtolower($currency);
        $minor = (int) round($amount * 100);

        if ($minor <= 0) {
            throw new \InvalidArgumentException('Montant de payout invalide.');
        }

        // 1) Transfer plateforme → compte Connect (autoritatif).
        $transfer = $this->client()->transfers->create([
            'amount' => $minor,
            'currency' => $currency,
            'destination' => $accountId,
            'metadata' => ['asso_kind' => 'vendor_withdrawal'],
        ]);

        // 2) Payout compte Connect → IBAN (best-effort).
        $payoutId = null;
        try {
            $payout = $this->client()->payouts->create([
                'amount' => $minor,
                'currency' => $currency,
                'metadata' => [
                    'asso_kind' => 'vendor_withdrawal',
                    'transfer_id' => $transfer->id,
                ],
            ], ['stripe_account' => $accountId]);
            $payoutId = $payout->id;
        } catch (\Throwable $e) {
            Log::warning('[StripeService] Payout manuel non créé (versement automatique probable)', [
                'account' => $accountId,
                'error' => $e->getMessage(),
            ]);
        }

        return [
            'id' => $payoutId ?? $transfer->id,
            'transfer_id' => $transfer->id,
            'payout_id' => $payoutId,
            'amount_minor' => $minor,
            'currency' => $currency,
        ];
    }

    /**
     * Crée un PaymentIntent pour ENCAISSER un paiement carte (réservation / achat).
     *
     * Montant exprimé dans l'unité principale de la devise (ex. dollars) et converti
     * ici en plus petite unité (centimes) — valable pour USD/EUR/GBP (2 décimales).
     * `automatic_payment_methods` est activé : la carte est confirmée côté client avec
     * le `client_secret` (SDK Stripe mobile). La confirmation du paiement côté serveur
     * se fait ensuite via retrievePaymentIntent() (polling) et/ou le webhook Stripe
     * (payment_intent.succeeded).
     *
     * @return array { id, client_secret, amount_minor, currency, publishable_key }
     */
    public function createPaymentIntent(float $amount, string $currency, array $metadata = []): array
    {
        $currency = strtolower($currency);
        $minor = (int) round($amount * 100);

        if ($minor <= 0) {
            throw new \InvalidArgumentException('Montant de paiement invalide.');
        }

        $intent = $this->client()->paymentIntents->create([
            'amount' => $minor,
            'currency' => $currency,
            'metadata' => $metadata,
            'automatic_payment_methods' => ['enabled' => true],
        ]);

        return [
            'id' => $intent->id,
            'client_secret' => $intent->client_secret,
            'amount_minor' => $minor,
            'currency' => $currency,
            'publishable_key' => $this->publishableKey,
        ];
    }

    /**
     * Statut d'un PaymentIntent : requires_payment_method | requires_confirmation |
     * processing | succeeded | canceled | requires_action.
     *
     * @return array { id, status, amount_minor, currency }
     */
    public function retrievePaymentIntent(string $id): array
    {
        $intent = $this->client()->paymentIntents->retrieve($id, []);

        return [
            'id' => $intent->id,
            'status' => $intent->status,
            'amount_minor' => $intent->amount,
            'currency' => $intent->currency,
        ];
    }

    /**
     * Crée une Checkout Session hébergée pour ENCAISSER un paiement carte.
     *
     * Adapté au mobile SANS SDK carte : renvoie une URL (`url`) à ouvrir en WebView.
     * Le client paie sur la page Stripe, puis est redirigé vers success/cancel_url.
     * La confirmation d'autorité se fait via le webhook `checkout.session.completed`
     * (et le polling retrieveCheckoutSession). Les metadata sont posées sur la session
     * ET sur le PaymentIntent sous-jacent (pour le routage webhook).
     *
     * @return array { id, url, amount_minor, currency }
     */
    public function createCheckoutSession(
        float $amount,
        string $currency,
        array $metadata,
        string $successUrl,
        string $cancelUrl,
        string $description = 'Paiement'
    ): array {
        $currency = strtolower($currency);
        $minor = (int) round($amount * 100);

        if ($minor <= 0) {
            throw new \InvalidArgumentException('Montant de paiement invalide.');
        }

        $session = $this->client()->checkout->sessions->create([
            'mode' => 'payment',
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => $currency,
                    'unit_amount' => $minor,
                    'product_data' => ['name' => $description],
                ],
            ]],
            'metadata' => $metadata,
            'payment_intent_data' => ['metadata' => $metadata],
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
        ]);

        return [
            'id' => $session->id,
            'url' => $session->url,
            'amount_minor' => $minor,
            'currency' => $currency,
        ];
    }

    /**
     * Statut d'une Checkout Session.
     *   status         : open | complete | expired
     *   payment_status : paid | unpaid | no_payment_required
     *
     * @return array { id, status, payment_status, payment_intent }
     */
    public function retrieveCheckoutSession(string $id): array
    {
        $s = $this->client()->checkout->sessions->retrieve($id, []);

        return [
            'id' => $s->id,
            'status' => $s->status,
            'payment_status' => $s->payment_status,
            'payment_intent' => $s->payment_intent,
        ];
    }

    /** Devise de payout par défaut selon le pays du compte bancaire. */
    private function defaultCurrencyForCountry(string $country): string
    {
        $eur = ['FR', 'DE', 'ES', 'IT', 'BE', 'NL', 'PT', 'IE', 'FI', 'AT', 'LU', 'GR'];
        return match (true) {
            in_array($country, $eur, true) => 'eur',
            $country === 'GB' => 'gbp',
            $country === 'US' => 'usd',
            default => 'eur',
        };
    }
}
