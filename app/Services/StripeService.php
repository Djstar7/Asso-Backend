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
    private ?string $webhookSecretConnect;
    private string $mode;
    private ?StripeClient $client = null;

    public function __construct()
    {
        $config = \App\Models\ServiceConfiguration::getConfig('stripe') ?? [];

        $this->secretKey = $config['secret_key'] ?? env('STRIPE_SECRET');
        $this->publishableKey = $config['publishable_key'] ?? env('STRIPE_KEY');
        $this->webhookSecret = $config['webhook_secret'] ?? env('STRIPE_WEBHOOK_SECRET');
        // Les événements Connect (payout.*, account.updated, émis sur les comptes
        // vendeurs) proviennent d'un endpoint Stripe DISTINCT, donc d'un secret de
        // signature distinct : les deux sont acceptés à la vérification.
        $this->webhookSecretConnect = $config['webhook_secret_connect'] ?? env('STRIPE_WEBHOOK_SECRET_CONNECT');
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

    public function webhookSecretConnect(): ?string
    {
        return $this->webhookSecretConnect;
    }

    /** Secrets de signature acceptés (compte plateforme + Connect), sans doublon. */
    public function webhookSecrets(): array
    {
        return array_values(array_unique(array_filter([
            $this->webhookSecret,
            $this->webhookSecretConnect,
        ])));
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
     * Exécute un appel du SDK Stripe en neutralisant les « stripe-notice »
     * (avertissements E_USER_WARNING émis par le SDK, ex. la recommandation
     * « Accounts v2 » renvoyée dans un en-tête de réponse v1). Sans cela,
     * Laravel convertit cet avertissement en ErrorException qui court-circuite
     * et MASQUE la véritable ApiErrorException (ex. IBAN de test invalide),
     * affichant un message trompeur à l'utilisateur.
     */
    private function withoutStripeNotices(callable $fn)
    {
        set_error_handler(static fn (): bool => true, \E_USER_WARNING);
        try {
            return $fn();
        } finally {
            restore_error_handler();
        }
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
     * ⚠️ Le KYC est OBLIGATOIRE : sans `individual.dob`, `individual.address`,
     * `individual.phone` et `business_profile{mcc,url}`, Stripe crée bien le compte
     * mais le laisse en `requirements.past_due` avec `capabilities.transfers=inactive`
     * — TOUT virement échoue alors (« destination account needs the transfers
     * capability »). Ces champs sont donc collectés à l'onboarding et envoyés ici.
     *
     * Le versement est forcé en **manuel** (`settings.payouts.schedule.interval`) :
     * ainsi c'est TOUJOURS nous qui créons le Payout, donc nous connaissons son id et
     * le webhook `payout.paid` peut le rapprocher du retrait. En automatique, Stripe
     * créerait des payouts inconnus de notre base et le retrait resterait `processing`.
     *
     * @param array $data country(2), email, first_name, last_name, iban, account_holder_name,
     *                    birth_date(Y-m-d), phone, address_line1, address_city,
     *                    address_postal_code, currency?, user_id?, tos_ip?, tos_date?
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
            // Stripe exige `card_payments` conjointement à `transfers` dans plusieurs
            // pays (FR et zone EU notamment) : demander `transfers` seul y échoue.
            'capabilities' => [
                'card_payments' => ['requested' => true],
                'transfers' => ['requested' => true],
            ],
            'individual' => $this->individualParams($data, $country),
            'business_profile' => $this->businessProfileParams(),
            // Versement manuel : nous seuls créons les Payouts (voir docblock).
            'settings' => [
                'payouts' => ['schedule' => ['interval' => 'manual']],
            ],
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

        $account = $this->withoutStripeNotices(fn () => $this->client()->accounts->create($params));

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

        $bank = $this->withoutStripeNotices(fn () => $this->client()->accounts->createExternalAccount($accountId, [
            'external_account' => [
                'object' => 'bank_account',
                'country' => $country,
                'currency' => $currency,
                'account_holder_name' => $data['account_holder_name'],
                'account_holder_type' => 'individual',
                'account_number' => $data['iban'],
            ],
            'default_for_currency' => true,
        ]));

        return [
            'external_last4' => $bank->last4 ?? substr(preg_replace('/\s+/', '', $data['iban']), -4),
            'bank_country' => $bank->country ?? $country,
        ];
    }

    /**
     * Met à jour les informations KYC d'un compte Connect existant (resoumission).
     *
     * Sans ces champs le compte reste `requirements.past_due` : c'est la cause n°1
     * d'un virement refusé par Stripe. On les renvoie donc à chaque resoumission,
     * en même temps que le nouvel IBAN.
     */
    public function updateAccountKyc(string $accountId, array $data): void
    {
        $country = strtoupper($data['country'] ?? '');
        $individual = $this->individualParams($data, $country);

        if (empty($individual)) {
            return;
        }

        $this->withoutStripeNotices(fn () => $this->client()->accounts->update($accountId, [
            'individual' => $individual,
            'business_profile' => $this->businessProfileParams(),
            'settings' => [
                'payouts' => ['schedule' => ['interval' => 'manual']],
            ],
        ]));
    }

    /**
     * État RÉEL du compte Connect côté Stripe (source d'autorité pour savoir si un
     * virement est possible), utilisé par la validation admin et le retrait.
     *
     * @return array {
     *   exists: bool, transfers: string (active|pending|inactive|unknown),
     *   payouts_enabled: bool, charges_enabled: bool,
     *   requirements_due: string[], disabled_reason: ?string, ready: bool, error: ?string
     * }
     */
    public function accountState(string $accountId): array
    {
        $unknown = [
            'exists' => false,
            'transfers' => 'unknown',
            'payouts_enabled' => false,
            'charges_enabled' => false,
            'requirements_due' => [],
            'disabled_reason' => null,
            'ready' => false,
            'error' => null,
        ];

        try {
            $account = $this->retrieveAccount($accountId);
        } catch (\Throwable $e) {
            Log::warning('[StripeService] accountState échoué', [
                'account_id' => $accountId,
                'error' => $e->getMessage(),
            ]);
            return array_merge($unknown, ['error' => $e->getMessage()]);
        }

        $transfers = (string) ($account->capabilities->transfers ?? 'unknown');
        $due = array_values(array_unique(array_merge(
            (array) ($account->requirements->currently_due ?? []),
            (array) ($account->requirements->past_due ?? []),
        )));

        return [
            'exists' => true,
            'transfers' => $transfers,
            'payouts_enabled' => (bool) ($account->payouts_enabled ?? false),
            'charges_enabled' => (bool) ($account->charges_enabled ?? false),
            'requirements_due' => $due,
            'disabled_reason' => $account->requirements->disabled_reason ?? null,
            // `ready` = Stripe accepterait un Transfer + un Payout vers l'IBAN.
            'ready' => $transfers === 'active' && (bool) ($account->payouts_enabled ?? false),
            'error' => null,
        ];
    }

    /**
     * Solde DISPONIBLE de la plateforme dans une devise donnée (unité principale).
     *
     * Le solde Stripe est par devise : un compte plateforme canadien qui encaisse en
     * EUR voit ses fonds convertis en CAD, et un Transfer en EUR échoue alors même
     * que le solde global est positif. On vérifie donc la devise AVANT de débiter le
     * wallet du vendeur.
     */
    public function platformAvailableBalance(string $currency): float
    {
        $currency = strtolower($currency);

        try {
            $balance = $this->withoutStripeNotices(fn () => $this->client()->balance->retrieve());
        } catch (\Throwable $e) {
            Log::warning('[StripeService] Lecture du solde plateforme échouée', [
                'error' => $e->getMessage(),
            ]);
            return 0.0;
        }

        foreach (($balance->available ?? []) as $entry) {
            if (strtolower($entry->currency ?? '') === $currency) {
                return ((int) ($entry->amount ?? 0)) / 100;
            }
        }

        return 0.0;
    }

    /**
     * La plateforme peut-elle payer dans cette devise ? (mise en cache 5 min)
     *
     * Sert à GRISER le rail « virement bancaire » côté application : un compte
     * plateforme qui ne détient aucun solde dans la devise du payout refusera tous
     * les virements, autant ne pas les proposer. Le cache évite un appel Stripe à
     * chaque ouverture du portefeuille.
     */
    public function platformSupportsCurrency(string $currency): bool
    {
        $currency = strtoupper($currency);

        $currencies = \Illuminate\Support\Facades\Cache::remember(
            'stripe:platform_balance_currencies',
            300,
            fn () => array_keys($this->platformBalanceCurrencies()),
        );

        return in_array($currency, $currencies, true);
    }

    /** Devises dans lesquelles la plateforme détient un solde (pour les diagnostics). */
    public function platformBalanceCurrencies(): array
    {
        try {
            $balance = $this->withoutStripeNotices(fn () => $this->client()->balance->retrieve());
        } catch (\Throwable $e) {
            return [];
        }

        $out = [];
        foreach (($balance->available ?? []) as $entry) {
            $out[strtoupper($entry->currency ?? '')] = ((int) ($entry->amount ?? 0)) / 100;
        }

        return $out;
    }

    public function retrieveAccount(string $accountId): \Stripe\Account
    {
        return $this->withoutStripeNotices(fn () => $this->client()->accounts->retrieve($accountId));
    }

    /**
     * Statut d'un payout sur le compte connecté (réconciliation du virement IBAN).
     *
     * @return array { success: bool, status?: string, failure_code?: string, data?: array, message?: string }
     *   status Stripe : paid | pending | in_transit | canceled | failed
     *   failure_code (si failed) : no_account | account_closed | insufficient_funds |
     *                              debit_not_authorized | invalid_currency | could_not_process | ...
     */
    public function getPayoutStatus(string $accountId, string $payoutId): array
    {
        try {
            $payout = $this->withoutStripeNotices(fn () => $this->client()->payouts->retrieve(
                $payoutId,
                [],
                ['stripe_account' => $accountId]
            ));

            return [
                'success' => true,
                'status' => $payout->status ?? null,
                'failure_code' => $payout->failure_code ?? null,
                'data' => $payout->toArray(),
            ];
        } catch (\Throwable $e) {
            Log::warning('[StripeService] getPayoutStatus échoué', [
                'account_id' => $accountId,
                'payout_id' => $payoutId,
                'error' => $e->getMessage(),
            ]);
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Contre-passe (annule) un Transfer plateforme → compte Connect.
     *
     * Utilisé quand le Payout vers l'IBAN échoue : le Transfer (autoritatif) avait
     * déjà déplacé les fonds vers le solde Connect du vendeur ; sans reversal, recréditer
     * le wallet créditerait le vendeur deux fois. Le reversal ramène les fonds côté
     * plateforme. Nécessite un solde Connect suffisant (les fonds d'un payout échoué y
     * sont retournés).
     *
     * @param int|null $amountMinor Montant à contre-passer (plus petite unité) ; null = total.
     * @return array { success: bool, reversal_id?: string, message?: string }
     */
    public function reverseTransfer(string $transferId, ?int $amountMinor = null): array
    {
        try {
            $params = [];
            if ($amountMinor !== null && $amountMinor > 0) {
                $params['amount'] = $amountMinor;
            }
            $reversal = $this->withoutStripeNotices(
                fn () => $this->client()->transfers->createReversal($transferId, $params)
            );
            return ['success' => true, 'reversal_id' => $reversal->id];
        } catch (\Throwable $e) {
            Log::error('[StripeService] Reversal du transfer échoué', [
                'transfer_id' => $transferId,
                'error' => $e->getMessage(),
            ]);
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Vérifie la signature d'un webhook Stripe et retourne l'événement typé.
     * Lève une exception si la signature est invalide ou le secret manquant.
     */
    public function constructWebhookEvent(string $payload, string $sigHeader): \Stripe\Event
    {
        $secrets = $this->webhookSecrets();

        if (empty($secrets)) {
            throw new \RuntimeException('Webhook secret Stripe non configuré.');
        }

        // Un événement n'est signé que par UN des endpoints : on essaie chaque secret
        // et on ne relaie l'échec que si aucun ne valide la signature.
        $last = null;
        foreach ($secrets as $secret) {
            try {
                return \Stripe\Webhook::constructEvent($payload, $sigHeader, $secret);
            } catch (\Throwable $e) {
                $last = $e;
            }
        }

        throw $last;
    }

    /**
     * Verse un montant au vendeur sur son IBAN (compte Connect déjà validé).
     *
     * PRÉ-CONTRÔLES (avant tout mouvement d'argent) — voir assertPayoutPossible() :
     *  a) la capability `transfers` du compte vendeur doit être `active` ;
     *  b) la plateforme doit détenir le montant DANS LA DEVISE du versement.
     * En cas d'échec, une StripePayoutUnavailableException est levée : rien n'a bougé.
     *
     * Puis deux étapes :
     *  1) **Transfer** plateforme → compte Connect du vendeur (mouvement autoritatif) ;
     *  2) **Payout** compte Connect → IBAN. Le compte étant en versement MANUEL, ce
     *     payout est le nôtre (id connu) : le webhook `payout.paid` pourra rapprocher
     *     le retrait. S'il échoue, on **contre-passe le Transfer** pour ne pas laisser
     *     les fonds bloqués sur le compte Connect, puis on lève l'exception.
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

        $this->assertPayoutPossible($accountId, $amount, $currency);

        // 1) Transfer plateforme → compte Connect (autoritatif).
        //
        // Le pré-contrôle a déjà écarté les cas connus, mais le solde peut avoir
        // bougé entre-temps (ou la conversion automatique être annoncée à tort) :
        // on traduit alors le refus Stripe en refus métier explicite plutôt qu'en
        // erreur 500 opaque.
        try {
            $transfer = $this->withoutStripeNotices(fn () => $this->client()->transfers->create([
                'amount' => $minor,
                'currency' => $currency,
                'destination' => $accountId,
                'metadata' => ['asso_kind' => 'vendor_withdrawal'],
            ]));
        } catch (\Throwable $e) {
            $raw = strtolower($e->getMessage());
            $reason = str_contains($raw, 'insufficient') || str_contains($raw, 'balance')
                ? 'platform_funds'
                : 'transfer_refused';

            Log::error('[StripeService] Transfer plateforme → vendeur refusé', [
                'account' => $accountId,
                'currency' => $currency,
                'error' => $e->getMessage(),
            ]);

            throw new \App\Exceptions\StripePayoutUnavailableException(
                $reason,
                'Le transfert vers le compte du vendeur a été refusé : ' . $e->getMessage(),
                ['account_id' => $accountId, 'currency' => strtoupper($currency)],
            );
        }

        // 2) Payout compte Connect → IBAN (versement manuel : c'est à nous de le créer).
        try {
            $payout = $this->withoutStripeNotices(fn () => $this->client()->payouts->create([
                'amount' => $minor,
                'currency' => $currency,
                'metadata' => [
                    'asso_kind' => 'vendor_withdrawal',
                    'transfer_id' => $transfer->id,
                ],
            ], ['stripe_account' => $accountId]));
        } catch (\Throwable $e) {
            // Sans payout, les fonds resteraient sur le compte Connect sans jamais
            // partir (plus de versement automatique) : on les ramène côté plateforme.
            Log::error('[StripeService] Payout refusé — contre-passation du transfer', [
                'account' => $accountId,
                'transfer_id' => $transfer->id,
                'error' => $e->getMessage(),
            ]);
            $this->reverseTransfer($transfer->id, $minor);

            throw new \App\Exceptions\StripePayoutUnavailableException(
                'payout_refused',
                "Le versement vers l'IBAN a été refusé par Stripe : " . $e->getMessage(),
                ['account_id' => $accountId, 'transfer_id' => $transfer->id],
            );
        }

        return [
            'id' => $payout->id,
            'transfer_id' => $transfer->id,
            'payout_id' => $payout->id,
            'amount_minor' => $minor,
            'currency' => $currency,
        ];
    }

    /**
     * Vérifie que le virement est possible AVANT de toucher à l'argent.
     *
     * @throws \App\Exceptions\StripePayoutUnavailableException
     */
    public function assertPayoutPossible(string $accountId, float $amount, string $currency): void
    {
        $currency = strtolower($currency);

        // a) Le compte vendeur doit être réellement activé côté Stripe.
        $state = $this->accountState($accountId);
        if (!$state['ready']) {
            throw new \App\Exceptions\StripePayoutUnavailableException(
                'account_not_ready',
                "Le compte de virement du vendeur n'est pas encore activé par Stripe.",
                [
                    'account_id' => $accountId,
                    'transfers' => $state['transfers'],
                    'requirements_due' => $state['requirements_due'],
                    'disabled_reason' => $state['disabled_reason'],
                ],
            );
        }

        // b) La plateforme doit détenir les fonds DANS CETTE DEVISE.
        //
        // Sauf si la CONVERSION AUTOMATIQUE est activée sur le compte Stripe
        // (dashboard → Balances → conversion de devises) : Stripe puise alors dans
        // la devise de règlement et le vendeur reçoit le montant exact demandé.
        // Le réglage `stripe_allow_currency_conversion` déclare cette activation ;
        // s'il est faux, on refuse ici plutôt que de laisser Stripe échouer après
        // avoir débité le wallet.
        if (\App\Models\Setting::get('stripe_allow_currency_conversion', false)) {
            return;
        }

        $available = $this->platformAvailableBalance($currency);
        if ($available + 0.0001 < $amount) {
            $balances = $this->platformBalanceCurrencies();
            throw new \App\Exceptions\StripePayoutUnavailableException(
                empty($balances[strtoupper($currency)]) ? 'currency_unavailable' : 'platform_funds',
                sprintf(
                    'Solde plateforme Stripe insuffisant en %s (disponible %.2f, requis %.2f).',
                    strtoupper($currency),
                    $available,
                    $amount,
                ),
                ['currency' => strtoupper($currency), 'available' => $available, 'balances' => $balances],
            );
        }
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

    /**
     * Bloc `individual` (identité + KYC) envoyé à Stripe.
     *
     * `array_filter` retire les clés absentes : une resoumission qui ne fournirait
     * qu'une partie des informations ne les écrase pas par des valeurs vides.
     */
    private function individualParams(array $data, string $country = ''): array
    {
        $params = array_filter([
            'first_name' => $data['first_name'] ?? null,
            'last_name' => $data['last_name'] ?? null,
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
        ]);

        // Date de naissance : Stripe attend jour/mois/année séparés.
        if (!empty($data['birth_date'])) {
            try {
                $dob = new \DateTimeImmutable((string) $data['birth_date']);
                $params['dob'] = [
                    'day' => (int) $dob->format('j'),
                    'month' => (int) $dob->format('n'),
                    'year' => (int) $dob->format('Y'),
                ];
            } catch (\Throwable $e) {
                Log::warning('[StripeService] Date de naissance illisible', [
                    'birth_date' => $data['birth_date'],
                ]);
            }
        }

        $address = array_filter([
            'line1' => $data['address_line1'] ?? null,
            'line2' => $data['address_line2'] ?? null,
            'city' => $data['address_city'] ?? null,
            'postal_code' => $data['address_postal_code'] ?? null,
            'state' => $data['address_state'] ?? null,
            'country' => $data['address_country'] ?? ($country ?: null),
        ]);

        if (!empty($address['line1'])) {
            $params['address'] = $address;
        }

        return $params;
    }

    /**
     * Bloc `business_profile` : exigé par Stripe pour activer les capabilities.
     * Ce sont des informations de la PLATEFORME, pas du vendeur — configurables via
     * les settings `stripe_business_mcc` / `stripe_business_url`.
     */
    private function businessProfileParams(): array
    {
        $url = (string) \App\Models\Setting::get('stripe_business_url', config('app.url'));
        $mcc = \App\Models\Setting::get('stripe_business_mcc', '5399');

        $params = ['mcc' => $mcc ?: null];

        // Stripe rejette (« Not a valid URL ») toute adresse non publique : en local
        // ou tant que le site n'est pas en ligne, on décrit l'activité à la place —
        // Stripe accepte `product_description` en substitut de `url`.
        if ($this->isPubliclyReachableUrl($url)) {
            $params['url'] = $url;
        } else {
            $params['product_description'] = (string) \App\Models\Setting::get(
                'stripe_business_description',
                'Place de marché en ligne : vente de produits et services entre membres.',
            );
        }

        return array_filter($params);
    }

    /**
     * Une URL réellement joignable par Stripe : domaine public résolvable depuis
     * Internet. Rejette les IP (privées comme publiques : Stripe exige un domaine
     * pour les webhooks) et les noms locaux — une adresse `192.168.x.x` est acceptée
     * à la création par Stripe mais aucune livraison n'aboutira jamais.
     */
    public function isPubliclyReachableUrl(string $url): bool
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return false;
        }

        $host = parse_url($url, PHP_URL_HOST) ?: '';

        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP)) {
            return false;
        }

        foreach (['localhost', '.local', '.test', '.internal', '.example'] as $needle) {
            if ($host === trim($needle, '.') || str_ends_with($host, $needle)) {
                return false;
            }
        }

        // Un domaine public a au moins un point (« exemple.com »).
        return str_contains($host, '.');
    }

    /**
     * Endpoints webhook déclarés sur le compte Stripe (diagnostic + idempotence de
     * la commande `stripe:webhook`). Le `secret` n'est JAMAIS relu ici : Stripe ne le
     * renvoie qu'à la création de l'endpoint.
     *
     * @return array<int, array{id:string,url:string,status:string,connect:bool,events:array}>
     */
    public function listWebhookEndpoints(): array
    {
        $endpoints = $this->withoutStripeNotices(
            fn () => $this->client()->webhookEndpoints->all(['limit' => 100])
        );

        $out = [];
        foreach ($endpoints->data as $e) {
            $out[] = [
                'id' => $e->id,
                'url' => $e->url,
                'status' => $e->status ?? 'unknown',
                // Un endpoint Connect porte l'id de l'application Connect dans
                // `application` ; nos créations portent aussi la metadata.
                'connect' => !empty($e->application) || ($e->metadata->asso_connect ?? null) === '1',
                'events' => (array) ($e->enabled_events ?? []),
            ];
        }

        return $out;
    }

    /** Supprime un endpoint webhook (URL devenue injoignable, doublon…). */
    public function deleteWebhookEndpoint(string $endpointId): bool
    {
        try {
            $this->withoutStripeNotices(fn () => $this->client()->webhookEndpoints->delete($endpointId, []));
            return true;
        } catch (\Throwable $e) {
            Log::warning('[StripeService] Suppression endpoint webhook échouée', [
                'endpoint' => $endpointId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Crée l'endpoint webhook s'il n'existe pas déjà pour cette URL/portée.
     *
     * @return array{id:string,secret:?string,created:bool}
     */
    public function ensureWebhookEndpoint(string $url, array $events, bool $connect): array
    {
        foreach ($this->listWebhookEndpoints() as $existing) {
            if ($existing['url'] === $url && $existing['connect'] === $connect) {
                return ['id' => $existing['id'], 'secret' => null, 'created' => false];
            }
        }

        $params = [
            'url' => $url,
            'enabled_events' => $events,
            'description' => $connect
                ? 'ASSO — événements Connect (virements IBAN vendeurs)'
                : 'ASSO — événements plateforme (paiements carte)',
            'metadata' => ['asso_connect' => $connect ? '1' : '0'],
        ];

        if ($connect) {
            $params['connect'] = true;
        }

        $endpoint = $this->withoutStripeNotices(
            fn () => $this->client()->webhookEndpoints->create($params)
        );

        return ['id' => $endpoint->id, 'secret' => $endpoint->secret ?? null, 'created' => true];
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
