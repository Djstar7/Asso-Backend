<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PlatformWithdrawal;
use App\Models\Setting;
use App\Models\User;
use App\Services\WalletService;
use App\Services\ExchangeRateService;
use App\Services\StripeService;
use App\Services\FirebaseMessagingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class WalletController extends Controller
{
    protected WalletService $walletService;
    protected FirebaseMessagingService $fcmService;

    public function __construct(
        WalletService $walletService,
        FirebaseMessagingService $fcmService
    ) {
        $this->walletService = $walletService;
        $this->fcmService = $fcmService;
    }

    /**
     * Récupère le solde et les stats du wallet
     *
     * GET /api/v1/wallet
     */
    public function index(Request $request)
    {
        try {
            $user = $request->user();
            $stats = $this->walletService->getWalletStats($user);

            return response()->json([
                'success' => true,
                'data' => $stats,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des statistiques',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Récupère l'historique des transactions
     *
     * GET /api/v1/wallet/transactions
     */
    public function transactions(Request $request)
    {
        try {
            $user = $request->user();
            $perPage = $request->input('per_page', 20);
            $type = $request->input('type'); // credit, debit, etc.
            $provider = $request->input('provider'); // kpay, paypal

            $paginated = $this->walletService->getTransactionHistory($user, $perPage, $type, $provider);

            return response()->json([
                'success' => true,
                'data' => [
                    'transactions' => $paginated->items(),
                    'current_page' => $paginated->currentPage(),
                    'last_page' => $paginated->lastPage(),
                    'per_page' => $paginated->perPage(),
                    'total' => $paginated->total(),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des transactions',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Initie une recharge du wallet
     * Crée un paiement KPay (Mobile Money) ou Stripe (carte bancaire NATIVE).
     *
     * - kpay   : PayIn USSD, montant dans la devise de l'opérateur, polling.
     * - stripe : PaymentIntent carte, montant saisi en XAF (converti vers la devise
     *            Stripe configurée). Renvoie un client_secret confirmé côté mobile par
     *            la Payment Sheet ; le solde XAF est crédité quand le PaymentIntent est
     *            'succeeded' (polling /wallet/payment-status ou webhook Stripe).
     *
     * POST /api/v1/wallet/recharge
     */
    public function recharge(Request $request)
    {
        Log::info("╔════════════════════════════════════════════════════════════════════╗");
        Log::info("║ [WalletController] 💰 WALLET RECHARGE REQUEST                     ║");
        Log::info("╚════════════════════════════════════════════════════════════════════╝");

        $minDepositAmount = Setting::get('min_deposit_amount', 100);

        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:' . $minDepositAmount,
            'payment_method' => 'required|in:kpay,stripe',
            // provider = code opérateur KPay (ex. MTN_MOMO_CMR) déterminant pays et devise
            'provider' => 'required_if:payment_method,kpay|string',
            'phone_number' => 'required_if:payment_method,kpay|string',
        ]);

        if ($validator->fails()) {
            Log::warning("[WalletController] ❌ Validation failed", [
                'errors' => $validator->errors()->toArray()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Données invalides',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $user = $request->user();
            $amount = $request->amount;
            $paymentMethod = $request->payment_method;
            $phoneNumber = $request->phone_number;
            $provider = $request->provider; // code opérateur KPay (ex. MTN_MOMO_CMR)

            Log::info("[WalletController] 📝 Request details", [
                'user_id' => $user->id,
                'amount' => $amount,
                'payment_method' => $paymentMethod,
                'provider' => $provider,
                'phone' => $phoneNumber,
            ]);

            if ($paymentMethod === 'kpay') {
                // Le montant est déjà saisi dans la devise de l'opérateur (conversion faite
                // côté mobile lors du changement de devise) — pas de conversion ici.
                $targetCurrency = \App\Services\KPayCatalog::currencyForProvider($provider);
                $chargeAmount = (float) round($amount);

                // Créer d'abord la transaction wallet en status pending (devise cible)
                $currentBalance = $user->kpayBalanceFor($targetCurrency);

                DB::beginTransaction();

                $walletTransaction = \App\Models\WalletTransaction::create([
                    'user_id' => $user->id,
                    'type' => 'credit',
                    'amount' => $chargeAmount, // montant dans la devise de l'opérateur
                    'balance_before' => $currentBalance,
                    'balance_after' => $currentBalance, // Pas encore crédité
                    'description' => "Recharge wallet via KPay ($targetCurrency)",
                    'status' => 'pending',
                    'provider' => 'kpay',
                    'metadata' => [
                        'phone_number' => $phoneNumber,
                        'kpay_provider' => $provider,
                        'currency' => $targetCurrency,
                        'initiated_at' => now()->toIso8601String(),
                    ],
                ]);

                Log::info("[WalletController] ✅ Wallet transaction created in pending state", [
                    'transaction_id' => $walletTransaction->id,
                    'charge' => "$chargeAmount $targetCurrency",
                ]);

                // Appeler KPay pour initier le paiement USSD (montant en devise opérateur)
                $kpayService = app(\App\Services\KPayService::class);

                $paymentResult = $kpayService->initializePayment([
                    'amount' => $chargeAmount,
                    'provider' => $provider,
                    'phone_number' => $phoneNumber,
                    'description' => "Recharge wallet #{$walletTransaction->id}",
                    'external_reference' => "WALLET-{$walletTransaction->id}",
                ]);

                if (!$paymentResult['success']) {
                    DB::rollBack();

                    // Supprimer la transaction wallet si le paiement a échoué
                    $walletTransaction->delete();

                    Log::error("[WalletController] ❌ KPay payment initiation failed", [
                        'error' => $paymentResult['message'] ?? 'Unknown error',
                    ]);

                    return response()->json([
                        'success' => false,
                        'message' => $paymentResult['message'] ?? 'Erreur lors de l\'initiation du paiement',
                    ], 400);
                }

                // Mettre à jour la transaction avec les identifiants KPay.
                // provider_reference = id KPay (pay_xxx) utilisé pour le polling de statut.
                $walletTransaction->metadata = array_merge($walletTransaction->metadata ?? [], [
                    'provider_reference' => $paymentResult['id'] ?? null,
                    'kpay_id' => $paymentResult['id'] ?? null,
                    'kpay_reference' => $paymentResult['reference'] ?? null,
                    'kpay_status' => $paymentResult['status'] ?? 'PENDING',
                    'kpay_data' => $paymentResult['data'] ?? [],
                ]);
                $walletTransaction->save();

                DB::commit();

                Log::info("[WalletController] ✅ KPay payment initiated", [
                    'transaction_id' => $walletTransaction->id,
                    'kpay_reference' => $paymentResult['reference'],
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Paiement initié. Veuillez composer le code USSD reçu sur votre téléphone.',
                    'data' => [
                        'transaction_id' => $walletTransaction->id,
                        'amount' => $chargeAmount,
                        'currency' => $targetCurrency,
                        'payment_method' => $paymentMethod,
                        'status' => 'pending',
                        'kpay_reference' => $paymentResult['reference'] ?? null,
                    ],
                ]);
            }

            // Recharge par CARTE BANCAIRE (Stripe natif — PaymentIntent).
            if ($paymentMethod === 'stripe') {
                $stripe = app(StripeService::class);
                if (!$stripe->isConfigured() || !\App\Services\PaymentMethodService::isEnabled('stripe')) {
                    return response()->json([
                        'success' => false,
                        'message' => "La recharge par carte bancaire n'est pas disponible pour le moment.",
                    ], 503);
                }

                // Le solde wallet est crédité en XAF (devise pivot). Le montant saisi est
                // en XAF ; on encaisse dans la devise Stripe configurée (conversion au taux stocké).
                $creditXaf = (float) round($amount);
                $stripeCurrency = \App\Services\PaymentMethodService::currencyFor('stripe') ?? 'USD';
                $chargeAmount = strtoupper($stripeCurrency) === 'XAF'
                    ? $creditXaf
                    : ExchangeRateService::convertAmount('XAF', $stripeCurrency, $creditXaf);
                if ($chargeAmount === null) {
                    return response()->json([
                        'success' => false,
                        'message' => "Conversion XAF → {$stripeCurrency} indisponible pour le paiement carte. Réessayez plus tard.",
                    ], 422);
                }

                $currentBalance = $user->kpayBalanceFor('XAF');

                DB::beginTransaction();

                $walletTransaction = \App\Models\WalletTransaction::create([
                    'user_id' => $user->id,
                    'type' => 'credit',
                    'amount' => $creditXaf, // crédité en XAF au succès
                    'balance_before' => $currentBalance,
                    'balance_after' => $currentBalance, // pas encore crédité
                    'description' => 'Recharge wallet par carte bancaire (Stripe)',
                    'status' => 'pending',
                    'provider' => 'stripe',
                    'metadata' => [
                        'currency' => 'XAF',
                        'charge_currency' => strtoupper($stripeCurrency),
                        'charge_amount' => round((float) $chargeAmount, 2),
                        'initiated_at' => now()->toIso8601String(),
                    ],
                ]);

                $intent = $stripe->createPaymentIntent(
                    (float) $chargeAmount,
                    $stripeCurrency,
                    [
                        'asso_kind' => 'wallet_recharge',
                        'wallet_transaction_id' => (string) $walletTransaction->id,
                        'user_id' => (string) $user->id,
                    ]
                );

                if (empty($intent['id']) || empty($intent['client_secret'])) {
                    DB::rollBack();
                    $walletTransaction->delete();
                    return response()->json([
                        'success' => false,
                        'message' => "Échec de l'initiation du paiement carte (Stripe).",
                    ], 400);
                }

                $walletTransaction->metadata = array_merge($walletTransaction->metadata ?? [], [
                    'provider_reference' => $intent['id'],
                    'payment_intent_id' => $intent['id'],
                ]);
                $walletTransaction->save();

                DB::commit();

                return response()->json([
                    'success' => true,
                    'message' => 'Recharge initiée. Finalisez le paiement par carte.',
                    'data' => [
                        'transaction_id' => $walletTransaction->id,
                        'payment_id' => $walletTransaction->id, // alias pour le polling payment-status
                        'amount' => $creditXaf,
                        'currency' => 'XAF',
                        'payment_method' => 'stripe',
                        'status' => 'pending',
                        // Carte native : le mobile confirme via la Payment Sheet.
                        'client_secret' => $intent['client_secret'],
                        'payment_intent_id' => $intent['id'],
                        'publishable_key' => $intent['publishable_key'] ?? null,
                        'charge_amount' => round((float) $chargeAmount, 2),
                        'charge_currency' => strtoupper($stripeCurrency),
                    ],
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'Méthode de paiement non supportée',
            ], 400);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("[WalletController] ❌ WALLET RECHARGE FAILED: " . $e->getMessage());
            Log::error($e->getTraceAsString());

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l\'initiation de la recharge',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Vérifie si l'utilisateur peut payer un montant avec son wallet
     *
     * POST /api/v1/wallet/can-pay
     */
    public function canPay(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:0',
            'provider' => 'nullable|string|in:kpay',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Données invalides',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $user = $request->user();
            $provider = $request->input('provider');
            $result = $this->walletService->canPayWithWallet($user, $request->amount, $provider);

            return response()->json([
                'success' => true,
                'data' => $result,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la vérification',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Paye avec le wallet (pour commandes)
     *
     * POST /api/v1/wallet/pay
     */
    public function pay(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:0',
            'description' => 'required|string|max:255',
            'reference_type' => 'required|string|in:order',
            'reference_id' => 'required|integer',
            'payment_provider' => 'required|string|in:kpay',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Données invalides',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $user = $request->user();
            $amount = $request->amount;
            $description = $request->description;
            $referenceType = $request->reference_type;
            $referenceId = $request->reference_id;
            $paymentProvider = $request->payment_provider;

            Log::info("[WalletController] Payment with wallet requested", [
                'user_id' => $user->id,
                'amount' => $amount,
                'provider' => $paymentProvider,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
            ]);

            // Effectuer le paiement
            $transaction = $this->walletService->debit(
                $user,
                $amount,
                $description,
                $referenceType,
                $referenceId,
                ['paid_via_api' => true],
                $paymentProvider
            );

            return response()->json([
                'success' => true,
                'message' => 'Paiement effectué avec succès',
                'data' => [
                    'transaction_id' => $transaction->id,
                    'amount_paid' => $amount,
                    'new_balance' => $transaction->balance_after,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    // ============================================
    // MÉTHODES DE RETRAIT WALLET
    // ============================================

    /**
     * Récupère le solde disponible pour retrait
     *
     * GET /api/v1/wallet/withdrawal-balances
     */
    public function getWithdrawalBalances(Request $request)
    {
        try {
            $user = $request->user();

            // Soldes KPay par devise (multi-devise)
            $kpayBalances = $user->walletBalances()
                ->get()
                ->map(fn($wb) => [
                    'currency' => $wb->currency,
                    'balance' => max(0, (float) $wb->balance),
                    'locked' => max(0, (float) $wb->locked_balance),
                    'available' => max(0, (float) $wb->balance - (float) $wb->locked_balance),
                ])
                ->values();

            $xafAvailable = $user->kpayAvailableFor('XAF');

            // Éligibilité au virement bancaire (Stripe Connect) : compte IBAN validé
            // + solde disponible dans la devise du payout (ex. EUR).
            $stripeReady = $user->stripe_account_status === 'approved'
                && !empty($user->stripe_account_id);
            $stripeCurrency = $this->stripePayoutCurrency($user->stripe_bank_country);

            // Le vendeur encaisse en XAF mais son IBAN est en EUR : le montant
            // versable est la somme de ses soldes CONVERTIS, pas son seul solde EUR.
            $stripeSources = $this->stripeWithdrawalSources($user, $stripeCurrency);
            $stripeBest = $this->pickWithdrawalSource($stripeSources, null);
            $stripeAvailable = $stripeBest['payout_equivalent'] ?? 0.0;

            // État de CONFIGURATION de chaque rail (clés API présentes côté plateforme).
            // Permet au mobile de GRISER un moyen non configuré au lieu de laisser
            // l'utilisateur tenter un retrait qui échouerait par une erreur.
            $kpayConfigured = app(\App\Services\KPayService::class)->isConfigured();
            $stripeService = app(\App\Services\StripeService::class);
            $stripeConfigured = $stripeService->isConfigured();

            // La plateforme doit détenir un solde DANS la devise du virement, sinon
            // Stripe refuse le transfert : on grise le rail plutôt que de le proposer.
            $stripePayoutSupported = $stripeConfigured
                && $stripeService->platformSupportsCurrency($stripeCurrency);

            return response()->json([
                'success' => true,
                'data' => [
                    // Multi-devise : liste des soldes KPay par devise
                    'kpay_balances' => $kpayBalances,
                    // Compat rétro (XAF)
                    'kpay_wallet_balance' => max(0, $xafAvailable),
                    'total_balance' => max(0, $xafAvailable),
                    // Virement bancaire (IBAN via Stripe Connect)
                    'stripe' => [
                        'eligible' => $stripeReady,
                        'configured' => $stripeConfigured,
                        'payout_supported' => $stripePayoutSupported,
                        'status' => $user->stripe_account_status, // null|pending|approved|rejected
                        'currency' => $stripeCurrency,
                        // Montant versable sur l'IBAN, conversion comprise.
                        'available' => max(0, $stripeAvailable),
                        // Soldes du portefeuille utilisables, avec taux et équivalent.
                        'sources' => array_values($stripeSources),
                        'source' => $stripeBest,
                        'iban_last4' => $user->stripe_external_last4,
                    ],
                    // Vue unifiée par méthode (configuration + solde retirable).
                    'methods' => [
                        'kpay' => [
                            'configured' => $kpayConfigured,
                            'available' => max(0, $xafAvailable),
                            'currency' => 'XAF',
                        ],
                        'stripe' => [
                            'configured' => $stripeConfigured && $stripePayoutSupported,
                            'eligible' => $stripeReady,
                            'payout_supported' => $stripePayoutSupported,
                            'status' => $user->stripe_account_status,
                            'available' => max(0, $stripeAvailable),
                            'currency' => $stripeCurrency,
                            'source' => $stripeBest,
                            'iban_last4' => $user->stripe_external_last4,
                        ],
                    ],
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('[WalletController] Error getting withdrawal balances: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des soldes',
            ], 500);
        }
    }

    /**
     * Initie un retrait KPay depuis le wallet
     *
     * POST /api/v1/wallet/withdraw/kpay
     */
    public function initiateKpayWithdrawal(Request $request)
    {
        Log::info("[WalletController] ╔════════════════════════════════════════════════════════════════════╗");
        Log::info("[WalletController] ║ [KPay Withdrawal] DEMANDE DE RETRAIT                         ║");
        Log::info("[WalletController] ╚════════════════════════════════════════════════════════════════════╝");

        $user = $request->user();

        $minWithdrawalAmount = Setting::get('min_withdrawal_amount', 100);

        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:' . $minWithdrawalAmount,
            // provider = code opérateur KPay bénéficiaire (ex. MTN_MOMO_CMR)
            'provider' => 'required|string',
            'phone' => 'required|string',
            'notes' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            Log::warning("[WalletController] ❌ Validation failed", $validator->errors()->toArray());
            return response()->json([
                'success' => false,
                'message' => 'Données invalides',
                'errors' => $validator->errors(),
            ], 422);
        }

        $baseAmount = (float) $request->input('amount'); // montant saisi (devise de base)
        $provider = $request->input('provider'); // code opérateur KPay
        $paymentMethod = $provider;              // stocké tel quel dans platform_withdrawals
        $phone = $request->input('phone');
        $notes = $request->input('notes');

        // Devise déduite de l'opérateur (le retrait reste dans le pays de l'opérateur).
        // Le montant est déjà saisi dans cette devise (conversion faite côté mobile).
        $currency = \App\Services\KPayCatalog::currencyForProvider($provider);
        $amount = (float) round($baseAmount);

        // Pré-contrôle rapide (non autoritatif) pour répondre vite en cas de solde manifestement insuffisant.
        // La vérification AUTORITATIVE se fait sous verrou de ligne dans la transaction ci-dessous.
        $availableBalance = $user->kpayAvailableFor($currency);

        if ($amount > $availableBalance) {
            Log::warning("[WalletController] ❌ Insufficient KPay wallet balance", [
                'currency' => $currency,
                'available' => $availableBalance,
                'requested_amount' => $amount,
            ]);

            return response()->json([
                'success' => false,
                'message' => "Solde $currency insuffisant. Disponible: " . number_format($availableBalance, 0, ',', ' ') . " $currency",
            ], 400);
        }

        try {
            DB::beginTransaction();

            // Verrou de ligne sur le solde + re-vérification À L'INTÉRIEUR de la transaction.
            // Empêche le double-retrait / solde négatif : deux requêtes concurrentes ne peuvent
            // plus passer le contrôle avant que l'une ne débite.
            $walletBalance = \App\Models\WalletBalance::where('user_id', $user->id)
                ->where('currency', $currency)
                ->lockForUpdate()
                ->first();

            $lockedAvailable = $walletBalance
                ? ((float) $walletBalance->balance - (float) $walletBalance->locked_balance)
                : 0.0;

            if ($amount > $lockedAvailable) {
                DB::rollBack();

                Log::warning("[WalletController] ❌ Insufficient balance (locked re-check)", [
                    'currency' => $currency,
                    'available' => $lockedAvailable,
                    'requested_amount' => $amount,
                ]);

                return response()->json([
                    'success' => false,
                    'message' => "Solde $currency insuffisant. Disponible: " . number_format($lockedAvailable, 0, ',', ' ') . " $currency",
                ], 400);
            }

            // Récupérer le solde actuel dans la devise (ligne verrouillée ci-dessus)
            $currentBalance = $walletBalance ? (float) $walletBalance->balance : 0.0;

            // Créer la transaction wallet (débit immédiat pour bloquer les fonds)
            $walletTransaction = \App\Models\WalletTransaction::create([
                'user_id' => $user->id,
                'type' => 'debit',
                'amount' => $amount,
                'balance_before' => $currentBalance,
                'balance_after' => $currentBalance - $amount, // Débit immédiat
                'description' => "Retrait {$paymentMethod} vers {$phone}",
                'status' => 'pending',
                'provider' => 'kpay',
                'reference_type' => 'platform_withdrawal',
                'reference_id' => null, // Sera mis à jour après création du withdrawal
                'metadata' => [
                    'phone' => $phone,
                    'payment_method' => $paymentMethod,
                    'currency' => $currency,
                    'initiated_at' => now()->toIso8601String(),
                ],
            ]);

            // Débiter le solde immédiatement dans la bonne devise (fonds bloqués)
            $user->debitKpay($currency, $amount);

            Log::info("[WalletController] ✅ Wallet transaction created (debit)", [
                'wallet_transaction_id' => $walletTransaction->id,
                'amount' => $amount,
                'balance_after' => $currentBalance - $amount,
            ]);

            // Créer l'enregistrement de retrait
            $withdrawal = PlatformWithdrawal::create([
                'user_id' => $user->id,
                'admin_id' => null,
                'amount_requested' => $amount,
                'commission_rate' => 0,
                'commission_amount' => 0,
                'amount_sent' => $amount,
                'currency' => $currency,
                'provider' => 'kpay',
                'payment_method' => $paymentMethod,
                'payment_account' => $phone,
                'payment_account_name' => $user->name,
                'status' => 'pending',
                'transaction_reference' => $this->generateTransactionReference(),
                'admin_notes' => $notes,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            // Lier la transaction wallet au withdrawal
            $walletTransaction->reference_id = $withdrawal->id;
            $walletTransaction->save();

            Log::info("[WalletController] ✅ KPay withdrawal record created", [
                'withdrawal_id' => $withdrawal->id,
                'wallet_transaction_id' => $walletTransaction->id,
                'user_id' => $user->id,
                'amount' => $amount,
            ]);

            // Appeler KPay pour initier le retrait (payout USSD)
            $kpayService = app(\App\Services\KPayService::class);

            $disbursementResult = $kpayService->initiateDisbursement([
                'amount' => $amount,
                'provider' => $provider,
                'phone_number' => $phone,
                'description' => "Retrait wallet #{$withdrawal->id}",
                'external_reference' => "WITHDRAW-{$withdrawal->id}",
            ]);

            if (!$disbursementResult['success']) {
                DB::rollBack();

                Log::error("[WalletController] ❌ KPay disbursement initiation failed", [
                    'withdrawal_id' => $withdrawal->id,
                    'error' => $disbursementResult['message'] ?? 'Unknown error',
                ]);

                return response()->json([
                    'success' => false,
                    'message' => $disbursementResult['message'] ?? 'Erreur lors de l\'initiation du retrait',
                ], 400);
            }

            // Stocker l'id KPay (wdr_xxx) — utilisé pour le polling du statut de retrait.
            $withdrawal->kpay_reference = $disbursementResult['id'] ?? null;
            $withdrawal->kpay_response = $disbursementResult['data'] ?? [];
            $withdrawal->markAsProcessing();
            $withdrawal->save();

            Log::info("[WalletController] ✅ KPay disbursement initiated", [
                'withdrawal_id' => $withdrawal->id,
                'kpay_reference' => $disbursementResult['reference'],
            ]);

            DB::commit();

            // Récupérer le nouveau solde après débit
            $user->refresh();

            // Envoyer notification FCM
            try {
                $this->fcmService->sendToUser(
                    $user,
                    'Retrait KPay en cours',
                    "Votre demande de retrait de {$amount} {$currency} vers {$phone} est en cours de traitement.",
                    [
                        'type' => 'wallet_withdrawal_processing',
                        'provider' => 'kpay',
                        'currency' => $currency,
                        'amount' => $amount,
                        'withdrawal_id' => $withdrawal->id,
                        'transaction_reference' => $withdrawal->transaction_reference,
                        'phone' => $phone,
                        'payment_method' => $paymentMethod,
                        'new_balance' => $user->kpayBalanceFor($currency),
                    ]
                );
                Log::info("[WalletController] 📬 FCM notification sent for KPay withdrawal");
            } catch (\Exception $e) {
                Log::error("[WalletController] ❌ Failed to send FCM notification: " . $e->getMessage());
            }

            return response()->json([
                'success' => true,
                'message' => 'Retrait en cours de traitement.',
                'data' => [
                    'withdrawal_id' => $withdrawal->id,
                    'wallet_transaction_id' => $walletTransaction->id,
                    'transaction_reference' => $withdrawal->transaction_reference,
                    'amount' => $withdrawal->amount_requested,
                    'currency' => $currency,
                    'status' => 'processing',
                    'new_balance' => $user->kpayBalanceFor($currency), // Nouveau solde après débit
                    'balance_before' => $currentBalance, // Solde avant retrait
                ],
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("[WalletController] ❌ KPay withdrawal error: " . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Initie un retrait par virement bancaire (Stripe Connect) vers l'IBAN validé
     * du vendeur. Débite le solde wallet dans la devise du payout (ex. EUR), puis
     * transfère les fonds au compte Connect du vendeur et déclenche le versement IBAN.
     *
     * Pré-requis : le vendeur a un compte Stripe Connect au statut `approved`.
     * Miroir de initiateKpayWithdrawal (verrou de ligne, débit atomique, rollback).
     *
     * POST /api/v1/wallet/withdraw/stripe
     */
    /**
     * Devis d'un virement IBAN : combien le vendeur recevra sur son compte bancaire
     * pour un montant pris sur son portefeuille.
     *
     * POST /api/v1/wallet/withdraw/stripe/quote  { amount, currency? }
     *
     * Les vendeurs encaissent en XAF alors que leur IBAN est libellé en EUR : la
     * conversion doit donc être faite ET MONTRÉE avant de valider le virement.
     */
    public function getStripeWithdrawalQuote(Request $request)
    {
        $user = $request->user();
        $payoutCurrency = $this->stripePayoutCurrency($user->stripe_bank_country);

        $validator = Validator::make($request->all(), [
            'amount' => 'nullable|numeric|min:0',
            'currency' => 'nullable|string|size:3',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Données invalides',
                'errors' => $validator->errors(),
            ], 422);
        }

        $sources = $this->stripeWithdrawalSources($user, $payoutCurrency);
        $requested = $request->filled('currency') ? strtoupper($request->input('currency')) : null;
        $source = $this->pickWithdrawalSource($sources, $requested);

        if (!$source) {
            return response()->json([
                'success' => true,
                'data' => [
                    'payout_currency' => $payoutCurrency,
                    'sources' => array_values($sources),
                    'source' => null,
                    'can_withdraw' => false,
                    'message' => 'Aucun solde convertible vers la devise de votre compte bancaire.',
                ],
            ]);
        }

        // Montant demandé, exprimé dans la devise du portefeuille (défaut : tout).
        $sourceAmount = $request->filled('amount')
            ? round((float) $request->input('amount'), 2)
            : $source['available'];

        $payoutAmount = round($sourceAmount * $source['rate'], 2);
        $minPayout = (float) Setting::get('min_stripe_withdrawal_amount', 5);
        $minSource = $source['rate'] > 0 ? ceil($minPayout / $source['rate']) : null;

        $reasons = [];
        if ($sourceAmount > $source['available']) {
            $reasons[] = 'Solde insuffisant.';
        }
        if ($payoutAmount < $minPayout) {
            $reasons[] = 'Le minimum est de ' . number_format($minPayout, 2, ',', ' ') . " {$payoutCurrency}"
                . ($minSource ? ' (' . number_format($minSource, 0, ',', ' ') . " {$source['currency']})" : '') . '.';
        }

        return response()->json([
            'success' => true,
            'data' => [
                'payout_currency' => $payoutCurrency,
                'source' => $source,
                'sources' => array_values($sources),
                'amount' => $sourceAmount,
                'currency' => $source['currency'],
                'payout_amount' => $payoutAmount,
                'rate' => $source['rate'],
                'minimum' => [
                    'payout_amount' => $minPayout,
                    'source_amount' => $minSource,
                ],
                'can_withdraw' => empty($reasons) && $user->stripe_account_status === 'approved',
                'message' => empty($reasons) ? null : implode(' ', $reasons),
            ],
        ]);
    }

    /**
     * Initie un virement IBAN (Stripe Connect) depuis le portefeuille du vendeur.
     *
     * POST /api/v1/wallet/withdraw/stripe  { amount, currency?, notes? }
     *
     * `amount` est exprimé dans la devise du PORTEFEUILLE (`currency`, ex. XAF) ;
     * la conversion vers la devise du compte bancaire (ex. EUR) est faite ici, au
     * taux du moment, et conservée sur le retrait pour l'audit.
     */
    public function initiateStripeWithdrawal(Request $request)
    {
        Log::info("[WalletController] ╔════════════════════════════════════════════════════════════════════╗");
        Log::info("[WalletController] ║ [Stripe Withdrawal] DEMANDE DE RETRAIT (IBAN)                       ║");
        Log::info("[WalletController] ╚════════════════════════════════════════════════════════════════════╝");

        $user = $request->user();
        $stripe = app(\App\Services\StripeService::class);

        // 1) Stripe doit être configuré.
        if (!$stripe->isConfigured()) {
            return response()->json([
                'success' => false,
                'message' => "Le paiement par virement (Stripe) n'est pas encore disponible.",
            ], 503);
        }

        // 2) Le vendeur doit avoir un compte de virement VALIDÉ.
        if ($user->stripe_account_status !== 'approved' || empty($user->stripe_account_id)) {
            return response()->json([
                'success' => false,
                'message' => "Votre compte de virement (IBAN) n'est pas encore validé. Enregistrez et faites valider votre IBAN avant de retirer.",
            ], 422);
        }

        // Devise versée sur l'IBAN, déduite du pays de la banque du vendeur (ex. FR → EUR).
        $payoutCurrency = $this->stripePayoutCurrency($user->stripe_bank_country);

        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:0.01',
            'currency' => 'nullable|string|size:3',
            'notes' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            Log::warning("[WalletController] ❌ Validation failed", $validator->errors()->toArray());
            return response()->json([
                'success' => false,
                'message' => 'Données invalides',
                'errors' => $validator->errors(),
            ], 422);
        }

        // 3) Choix du solde débité + taux de conversion vers la devise du virement.
        $sources = $this->stripeWithdrawalSources($user, $payoutCurrency);
        $requestedCurrency = $request->filled('currency') ? strtoupper($request->input('currency')) : null;
        $source = $this->pickWithdrawalSource($sources, $requestedCurrency);

        if (!$source) {
            return response()->json([
                'success' => false,
                'message' => $requestedCurrency
                    ? "Aucun solde disponible en {$requestedCurrency} convertible vers {$payoutCurrency}."
                    : 'Aucun solde convertible vers la devise de votre compte bancaire.',
            ], 400);
        }

        $sourceCurrency = $source['currency'];
        $rate = $source['rate'];
        $amount = round((float) $request->input('amount'), 2);       // débité au vendeur
        $payoutAmount = round($amount * $rate, 2);                    // versé sur l'IBAN
        $notes = $request->input('notes');

        // 4) Minimum, exprimé dans la devise du virement.
        $minWithdrawalAmount = (float) Setting::get('min_stripe_withdrawal_amount', 5);
        if ($payoutAmount < $minWithdrawalAmount) {
            $minSource = $rate > 0 ? ceil($minWithdrawalAmount / $rate) : null;
            return response()->json([
                'success' => false,
                'message' => 'Montant trop faible : le minimum est de '
                    . number_format($minWithdrawalAmount, 2, ',', ' ') . " {$payoutCurrency}"
                    . ($minSource ? ' (' . number_format($minSource, 0, ',', ' ') . " {$sourceCurrency})" : '') . '.',
            ], 422);
        }

        // Pré-contrôle rapide non autoritatif (la vérif autoritative est sous verrou).
        if ($amount > $source['available']) {
            Log::warning("[WalletController] ❌ Insufficient balance for Stripe withdrawal", [
                'currency' => $sourceCurrency,
                'available' => $source['available'],
                'requested_amount' => $amount,
            ]);
            return response()->json([
                'success' => false,
                'message' => "Solde $sourceCurrency insuffisant. Disponible: " . number_format($source['available'], 2, ',', ' ') . " $sourceCurrency",
            ], 400);
        }

        // Pré-contrôle Stripe AVANT toute écriture : compte vendeur réellement activé
        // et solde plateforme disponible DANS CETTE DEVISE. Sans lui, on débitait le
        // wallet pour ensuite tout annuler par rollback sur une erreur Stripe opaque.
        try {
            $stripe->assertPayoutPossible($user->stripe_account_id, $payoutAmount, $payoutCurrency);
        } catch (\App\Exceptions\StripePayoutUnavailableException $e) {
            Log::error('[WalletController] ❌ Virement IBAN impossible (pré-contrôle)', [
                'user_id' => $user->id,
                'reason' => $e->reason,
                'detail' => $e->getMessage(),
                'context' => $e->context,
            ]);

            return response()->json([
                'success' => false,
                'message' => $this->stripeUnavailableMessage($e->reason),
            ], $e->reason === 'account_not_ready' ? 422 : 503);
        }

        try {
            DB::beginTransaction();

            // Verrou de ligne + re-vérification À L'INTÉRIEUR de la transaction (anti double-retrait).
            $walletBalance = \App\Models\WalletBalance::where('user_id', $user->id)
                ->where('currency', $sourceCurrency)
                ->lockForUpdate()
                ->first();

            $lockedAvailable = $walletBalance
                ? ((float) $walletBalance->balance - (float) $walletBalance->locked_balance)
                : 0.0;

            if ($amount > $lockedAvailable) {
                DB::rollBack();
                Log::warning("[WalletController] ❌ Insufficient balance (locked re-check)", [
                    'currency' => $sourceCurrency,
                    'available' => $lockedAvailable,
                    'requested_amount' => $amount,
                ]);
                return response()->json([
                    'success' => false,
                    'message' => "Solde $sourceCurrency insuffisant. Disponible: " . number_format($lockedAvailable, 2, ',', ' ') . " $sourceCurrency",
                ], 400);
            }

            $currentBalance = $walletBalance ? (float) $walletBalance->balance : 0.0;
            $ibanLast4 = $user->stripe_external_last4 ?? '****';
            $holder = $user->stripe_account_holder_name ?? $user->name;

            // Transaction wallet (débit immédiat pour bloquer les fonds), dans la
            // devise du portefeuille — c'est ce que le vendeur voit dans son historique.
            $walletTransaction = \App\Models\WalletTransaction::create([
                'user_id' => $user->id,
                'type' => 'debit',
                'amount' => $amount,
                'balance_before' => $currentBalance,
                'balance_after' => $currentBalance - $amount,
                'description' => "Virement IBAN ****{$ibanLast4}"
                    . ($sourceCurrency !== $payoutCurrency
                        ? ' (' . number_format($payoutAmount, 2, ',', ' ') . " {$payoutCurrency})"
                        : ''),
                'status' => 'pending',
                'provider' => 'stripe',
                'reference_type' => 'platform_withdrawal',
                'reference_id' => null,
                'metadata' => [
                    'iban_last4' => $ibanLast4,
                    'currency' => $sourceCurrency,
                    'payout_currency' => $payoutCurrency,
                    'payout_amount' => $payoutAmount,
                    'exchange_rate' => $rate,
                    'stripe_account_id' => $user->stripe_account_id,
                    'initiated_at' => now()->toIso8601String(),
                ],
            ]);

            // Débit immédiat (fonds bloqués).
            $user->debitKpay($sourceCurrency, $amount);

            // Enregistrement de retrait.
            $withdrawal = PlatformWithdrawal::create([
                'user_id' => $user->id,
                'admin_id' => null,
                'amount_requested' => $amount,
                'commission_rate' => 0,
                'commission_amount' => 0,
                'amount_sent' => $amount,
                'currency' => $sourceCurrency,
                'payout_amount' => $payoutAmount,
                'payout_currency' => $payoutCurrency,
                'exchange_rate' => $rate,
                'provider' => 'stripe',
                'payment_method' => 'stripe_connect',
                'payment_account' => "IBAN ****{$ibanLast4}",
                'payment_account_name' => $holder,
                'status' => 'pending',
                'transaction_reference' => $this->generateTransactionReference(),
                'admin_notes' => $notes,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            $walletTransaction->reference_id = $withdrawal->id;
            $walletTransaction->save();

            Log::info("[WalletController] ✅ Stripe withdrawal record created", [
                'withdrawal_id' => $withdrawal->id,
                'user_id' => $user->id,
                'amount' => $amount,
                'currency' => $sourceCurrency,
                'payout_amount' => $payoutAmount,
                'payout_currency' => $payoutCurrency,
                'rate' => $rate,
            ]);

            // Appel Stripe : transfer (autoritatif) + payout IBAN, dans la devise du compte.
            $result = $stripe->payoutToVendor($user->stripe_account_id, $payoutAmount, $payoutCurrency);

            // Le virement peut être financé depuis une autre devise (ex. solde CAD
            // pour un IBAN en EUR) : c'est le montant RÉELLEMENT versé qui fait foi.
            $paidAmount = (float) ($result['payout_amount'] ?? $payoutAmount);
            if (abs($paidAmount - $payoutAmount) > 0.001) {
                Log::info('[WalletController] Montant versé ajusté après conversion', [
                    'withdrawal_id' => $withdrawal->id,
                    'attendu' => $payoutAmount,
                    'versé' => $paidAmount,
                    'financement' => $result['funding_currency'] ?? null,
                ]);
                $withdrawal->payout_amount = $paidAmount;
            }
            $payoutAmount = $paidAmount;

            $withdrawal->stripe_transfer_id = $result['transfer_id'] ?? null;
            $withdrawal->stripe_payout_id = $result['payout_id'] ?? null;
            $withdrawal->stripe_response = $result;
            $withdrawal->markAsProcessing();
            $withdrawal->save();

            DB::commit();
            $user->refresh();

            Log::info("[WalletController] ✅ Stripe payout initiated", [
                'withdrawal_id' => $withdrawal->id,
                'transfer_id' => $result['transfer_id'] ?? null,
                'payout_id' => $result['payout_id'] ?? null,
            ]);

            // Notification FCM.
            try {
                $this->fcmService->sendToUser(
                    $user,
                    'Virement en cours',
                    "Votre demande de virement de " . number_format($payoutAmount, 2, ',', ' ') . " {$payoutCurrency}"
                        . " vers votre IBAN ****{$ibanLast4} est en cours de traitement.",
                    [
                        'type' => 'wallet_withdrawal_processing',
                        'provider' => 'stripe',
                        'currency' => $sourceCurrency,
                        'amount' => $amount,
                        'payout_currency' => $payoutCurrency,
                        'payout_amount' => $payoutAmount,
                        'withdrawal_id' => $withdrawal->id,
                        'transaction_reference' => $withdrawal->transaction_reference,
                        'new_balance' => $user->kpayBalanceFor($sourceCurrency),
                    ]
                );
            } catch (\Exception $e) {
                Log::error("[WalletController] ❌ Failed to send FCM notification: " . $e->getMessage());
            }

            return response()->json([
                'success' => true,
                'message' => 'Virement de ' . number_format($payoutAmount, 2, ',', ' ') . " {$payoutCurrency}"
                    . ' en cours de traitement. Les fonds arriveront sur votre compte sous 1 à 3 jours ouvrés.',
                'data' => [
                    'withdrawal_id' => $withdrawal->id,
                    'wallet_transaction_id' => $walletTransaction->id,
                    'transaction_reference' => $withdrawal->transaction_reference,
                    'amount' => $withdrawal->amount_requested,
                    'currency' => $sourceCurrency,
                    'payout_amount' => $payoutAmount,
                    'payout_currency' => $payoutCurrency,
                    'exchange_rate' => $rate,
                    'status' => 'processing',
                    'iban_last4' => $ibanLast4,
                    'new_balance' => $user->kpayBalanceFor($sourceCurrency),
                    'balance_before' => $currentBalance,
                ],
            ]);
        } catch (\App\Exceptions\StripePayoutUnavailableException $e) {
            // Le virement a été refusé sans mouvement d'argent net (transfer contre-passé
            // le cas échéant) : on annule l'écriture du débit et on explique pourquoi.
            DB::rollBack();
            Log::error('[WalletController] ❌ Virement IBAN refusé par Stripe', [
                'user_id' => $user->id,
                'reason' => $e->reason,
                'detail' => $e->getMessage(),
                'context' => $e->context,
            ]);

            return response()->json([
                'success' => false,
                'message' => $this->stripeUnavailableMessage($e->reason),
            ], $e->reason === 'account_not_ready' ? 422 : 503);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error("[WalletController] ❌ Stripe withdrawal error: " . $e->getMessage());

            // Ne pas exposer le détail technique Stripe (reste tracé ci-dessus).
            return response()->json([
                'success' => false,
                'message' => "Le virement n'a pas pu être initié pour le moment. Votre solde n'a pas été débité. Réessayez plus tard.",
            ], 500);
        }
    }

    /**
     * Soldes du vendeur convertibles vers la devise de son compte bancaire.
     *
     * Chaque entrée porte le solde disponible, le taux appliqué et l'équivalent
     * versable — c'est ce que l'application affiche au vendeur (« 12 000 FCFA
     * ≈ 18,29 EUR »). Une devise sans taux connu est ignorée plutôt que devinée.
     *
     * @return array<string, array{currency:string, available:float, rate:float, payout_equivalent:float}>
     */
    private function stripeWithdrawalSources(User $user, string $payoutCurrency): array
    {
        $sources = [];

        foreach ($user->walletBalances()->get() as $balance) {
            $available = max(0, (float) $balance->balance - (float) $balance->locked_balance);
            if ($available <= 0) {
                continue;
            }

            $currency = strtoupper($balance->currency);

            if ($currency === $payoutCurrency) {
                $rate = 1.0;
            } else {
                $conversion = ExchangeRateService::convert($currency, $payoutCurrency, 1);
                if (!($conversion['success'] ?? false)) {
                    continue; // taux inconnu : on ne propose pas cette devise.
                }
                $rate = (float) $conversion['rate'];
            }

            $sources[$currency] = [
                'currency' => $currency,
                'available' => round($available, 2),
                'rate' => $rate,
                'payout_equivalent' => round($available * $rate, 2),
            ];
        }

        return $sources;
    }

    /**
     * Solde à débiter : celui demandé s'il existe, sinon celui qui permet le plus
     * gros virement (le vendeur n'a pas à choisir dans le cas courant).
     */
    private function pickWithdrawalSource(array $sources, ?string $requested): ?array
    {
        if ($requested !== null) {
            return $sources[$requested] ?? null;
        }

        if (empty($sources)) {
            return null;
        }

        usort($sources, fn ($a, $b) => $b['payout_equivalent'] <=> $a['payout_equivalent']);

        return $sources[0];
    }

    /**
     * Message vendeur pour un virement IBAN impossible. Le détail technique (devise
     * du solde plateforme, requirements Stripe) reste dans les logs : il concerne
     * l'exploitation de la plateforme, pas le vendeur.
     */
    private function stripeUnavailableMessage(string $reason): string
    {
        return match ($reason) {
            'account_not_ready' => "Votre compte de virement n'est pas encore activé par notre partenaire bancaire. "
                . 'Vérifiez vos informations dans « Compte de virement » ou réessayez sous peu.',
            'payout_refused', 'transfer_refused' => "Le virement a été refusé par notre partenaire bancaire. "
                . "Votre solde n'a pas été débité. Vérifiez votre IBAN puis réessayez.",
            'platform_funds' => "Le virement bancaire est momentanément indisponible (fonds en cours de "
                . "réapprovisionnement). Votre solde n'a pas été débité : réessayez plus tard ou "
                . 'choisissez un autre moyen de retrait.',
            default => "Le virement bancaire est momentanément indisponible. "
                . "Votre solde n'a pas été débité. Réessayez plus tard ou choisissez un autre moyen de retrait.",
        };
    }

    /** Devise de payout Stripe selon le pays de la banque du vendeur (défaut EUR). */
    private function stripePayoutCurrency(?string $bankCountry): string
    {
        $country = strtoupper((string) $bankCountry);
        $eur = ['FR', 'DE', 'ES', 'IT', 'BE', 'NL', 'PT', 'IE', 'FI', 'AT', 'LU', 'GR'];
        return match (true) {
            in_array($country, $eur, true) => 'EUR',
            $country === 'GB' => 'GBP',
            $country === 'US' => 'USD',
            default => 'EUR',
        };
    }

    /**
     * Vérifie le statut d'un retrait
     *
     * GET /api/v1/wallet/withdrawal-status/{withdrawalId}
     */
    public function checkWithdrawalStatus(Request $request, $withdrawalId)
    {
        try {
            $user = $request->user();

            $withdrawal = PlatformWithdrawal::where('id', $withdrawalId)
                ->where('user_id', $user->id)
                ->first();

            if (!$withdrawal) {
                return response()->json([
                    'success' => false,
                    'message' => 'Retrait non trouvé',
                ], 404);
            }

            // Finalisation à la demande tant que c'est en cours.
            if (in_array($withdrawal->status, ['pending', 'processing'])) {
                if ($withdrawal->provider === 'kpay') {
                    // KPay : re-vérification autoritative via le job dédié.
                    \App\Jobs\Wallet\ProcessWithdrawalStatusJob::dispatchSync($withdrawal->id);
                    $withdrawal->refresh();
                } elseif ($withdrawal->provider === 'stripe' && !empty($withdrawal->stripe_payout_id)) {
                    // Stripe : réconcilier l'état réel du payout vers l'IBAN.
                    $this->reconcileStripeWithdrawal($withdrawal);
                    $withdrawal->refresh();
                }
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'withdrawal_id' => $withdrawal->id,
                    'transaction_reference' => $withdrawal->transaction_reference,
                    'amount' => $withdrawal->amount_requested,
                    'provider' => $withdrawal->provider,
                    'payment_method' => $withdrawal->payment_method,
                    'status' => $withdrawal->status,
                    'created_at' => $withdrawal->created_at->toIso8601String(),
                    'completed_at' => $withdrawal->completed_at?->toIso8601String(),
                    'failure_reason' => $withdrawal->failure_reason,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('[WalletController] Error checking withdrawal status: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la vérification du statut',
            ], 500);
        }
    }

    /**
     * Réconcilie un retrait Stripe (virement IBAN) avec l'état réel du payout.
     * 'completed' uniquement si Stripe confirme 'paid'. En échec terminal
     * (failed/canceled), recrédite le solde dans la devise du payout.
     */
    private function reconcileStripeWithdrawal(PlatformWithdrawal $withdrawal): void
    {
        $user = $withdrawal->user;
        if (!$user || empty($user->stripe_account_id)) {
            return;
        }

        $stripe = app(\App\Services\StripeService::class);
        $result = $stripe->getPayoutStatus($user->stripe_account_id, $withdrawal->stripe_payout_id);
        if (!($result['success'] ?? false)) {
            return; // Indisponible : on retentera au prochain poll.
        }

        $status = strtolower((string) ($result['status'] ?? ''));

        $debitTx = \App\Models\WalletTransaction::where('reference_type', 'platform_withdrawal')
            ->where('reference_id', $withdrawal->id)
            ->where('provider', 'stripe')
            ->where('type', 'debit')
            ->first();

        if ($status === 'paid') {
            $withdrawal->markAsCompleted($withdrawal->stripe_payout_id, $result['data'] ?? []);
            $debitTx?->update(['status' => 'completed']);
            Log::info('[WalletController] ✅ Virement IBAN réglé (paid)', [
                'withdrawal_id' => $withdrawal->id,
            ]);
            return;
        }

        if (in_array($status, ['failed', 'canceled'])) {
            $failureCode = strtolower((string) ($result['failure_code'] ?? ''));

            if ($debitTx && $debitTx->status !== 'failed') {
                // Le Transfer (autoritatif) avait déplacé les fonds vers le solde Connect
                // du vendeur ; on le CONTRE-PASSE d'abord (retour côté plateforme) pour ne
                // pas créditer deux fois, PUIS on recrédite le wallet.
                if (!empty($withdrawal->stripe_transfer_id)) {
                    $reversal = $stripe->reverseTransfer(
                        $withdrawal->stripe_transfer_id,
                        (int) round(((float) $withdrawal->amount_requested) * 100)
                    );
                    if (!($reversal['success'] ?? false)) {
                        // Reversal impossible (ex. fonds Connect déjà repartis) : on NE
                        // recrédite pas à l'aveugle pour éviter un double-crédit. On
                        // laisse en 'processing' pour retenter / traitement manuel.
                        Log::error('[WalletController] Reversal transfer Stripe échoué — pas de remboursement auto', [
                            'withdrawal_id' => $withdrawal->id,
                            'transfer_id' => $withdrawal->stripe_transfer_id,
                        ]);
                        return;
                    }
                }

                $user->creditKpay((string) $withdrawal->currency, (float) $withdrawal->amount_requested);
                \App\Models\WalletTransaction::create([
                    'user_id' => $user->id,
                    'type' => 'credit',
                    'amount' => (float) $withdrawal->amount_requested,
                    'description' => "Remboursement — virement IBAN non abouti (réf. {$withdrawal->transaction_reference})",
                    'status' => 'completed',
                    'provider' => 'stripe',
                    'reference_type' => 'platform_withdrawal',
                    'reference_id' => $withdrawal->id,
                    'metadata' => ['refund' => true, 'currency' => $withdrawal->currency, 'failure_code' => $failureCode],
                ]);
                $debitTx->update(['status' => 'failed']);
            }

            $withdrawal->markAsFailed(
                'stripe_payout_' . ($failureCode ?: $status),
                $this->stripePayoutFailureMessage($failureCode, $status)
            );
            Log::warning('[WalletController] ❌ Virement IBAN échoué, solde recrédité', [
                'withdrawal_id' => $withdrawal->id,
                'status' => $status,
                'failure_code' => $failureCode,
            ]);
        }
        // Sinon (pending / in_transit) : on laisse en 'processing'.
    }

    /**
     * Message clair pour l'utilisateur selon le failure_code d'un payout Stripe
     * (cf. codes de la doc Connect : no_account, account_closed, insufficient_funds,
     * debit_not_authorized, invalid_currency, could_not_process...).
     */
    private function stripePayoutFailureMessage(string $failureCode, string $status): string
    {
        return match ($failureCode) {
            'no_account' => "Le virement a échoué : compte bancaire (IBAN) introuvable. Vérifiez votre IBAN.",
            'account_closed' => "Le virement a échoué : le compte bancaire est clôturé. Enregistrez un autre IBAN.",
            'insufficient_funds' => "Le virement n'a pas pu être traité pour le moment. Votre solde a été recrédité, réessayez plus tard.",
            'debit_not_authorized' => "Le virement a été refusé par la banque (débit non autorisé).",
            'invalid_currency' => "Le virement a échoué : la devise n'est pas prise en charge par ce compte bancaire.",
            'could_not_process' => "Le virement n'a pas pu être traité. Votre solde a été recrédité.",
            default => $status === 'canceled'
                ? "Le virement a été annulé. Votre solde a été recrédité."
                : "Le virement vers votre IBAN n'a pas abouti. Votre solde a été recrédité.",
        };
    }

    /**
     * Récupère l'historique des retraits
     *
     * GET /api/v1/wallet/withdrawals
     */
    public function getWithdrawalHistory(Request $request)
    {
        try {
            $user = $request->user();
            $page = $request->input('page', 1);
            $perPage = $request->input('per_page', 20);
            $provider = $request->input('provider');
            $status = $request->input('status');

            $query = PlatformWithdrawal::where('user_id', $user->id)
                ->orderBy('created_at', 'desc');

            if ($provider) {
                $query->where('provider', $provider);
            }

            if ($status) {
                $query->where('status', $status);
            }

            $withdrawals = $query->paginate($perPage, ['*'], 'page', $page);

            return response()->json([
                'success' => true,
                'data' => [
                    'data' => $withdrawals->items(),
                    'current_page' => $withdrawals->currentPage(),
                    'last_page' => $withdrawals->lastPage(),
                    'total' => $withdrawals->total(),
                    'per_page' => $withdrawals->perPage(),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('[WalletController] Error getting withdrawal history: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération de l\'historique',
            ], 500);
        }
    }

    /**
     * Vérifie le statut d'un paiement (pour polling)
     *
     * GET /api/v1/wallet/payment-status/{paymentId}
     */
    public function checkPaymentStatus(Request $request, $paymentId)
    {
        try {
            $user = $request->user();

            $walletTransaction = \App\Models\WalletTransaction::where('id', $paymentId)
                ->where('user_id', $user->id)
                ->first();

            if (!$walletTransaction) {
                return response()->json([
                    'success' => false,
                    'message' => 'Paiement non trouvé',
                ], 404);
            }

            // Re-vérifier le statut directement chez KPay tant que c'est en attente
            // (finalisation à la demande — fonctionne sans worker de queue).
            if ($walletTransaction->status === 'pending' && $walletTransaction->provider === 'kpay') {
                \App\Jobs\Wallet\ProcessDepositStatusJob::dispatchSync($walletTransaction->id);
                $walletTransaction->refresh();
            }

            // Recharge carte (Stripe natif) : relire le PaymentIntent et créditer si succeeded.
            if ($walletTransaction->status === 'pending' && $walletTransaction->provider === 'stripe') {
                $ref = $walletTransaction->metadata['payment_intent_id']
                    ?? ($walletTransaction->metadata['provider_reference'] ?? null);
                if ($ref) {
                    try {
                        $intent = app(StripeService::class)->retrievePaymentIntent($ref);
                        $status = strtolower($intent['status'] ?? '');
                        if ($status === 'succeeded') {
                            $this->confirmStripeRecharge($walletTransaction);
                        } elseif ($status === 'canceled') {
                            $this->failStripeRecharge($walletTransaction, 'Paiement carte annulé.');
                        }
                    } catch (\Throwable $e) {
                        Log::warning('[WalletController] Stripe recharge retrieve PI: ' . $e->getMessage());
                    }
                    $walletTransaction->refresh();
                }
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'payment_id' => $walletTransaction->id,
                    'status' => $walletTransaction->status,
                    'amount' => $walletTransaction->amount,
                    'payment_method' => $walletTransaction->provider,
                    'created_at' => $walletTransaction->created_at->toIso8601String(),
                    'paid_at' => $walletTransaction->status === 'completed'
                        ? ($walletTransaction->updated_at->toIso8601String())
                        : null,
                    'failure_reason' => $walletTransaction->status === 'failed'
                        ? ($walletTransaction->metadata['capture_error'] ?? 'Unknown error')
                        : null,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('[WalletController] Error checking payment status: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la vérification du statut',
            ], 500);
        }
    }

    /**
     * Confirme une recharge wallet par carte (Stripe natif) — idempotent.
     *
     * Crédite le solde XAF du wallet une seule fois (transition pending → completed).
     * Appelé par le polling (checkPaymentStatus) ET le webhook payment_intent.succeeded
     * (StripeWebhookController → asso_kind=wallet_recharge). Le crédit dépend du statut
     * réel du PaymentIntent, jamais d'un corps de webhook falsifiable.
     */
    public function confirmStripeRecharge(\App\Models\WalletTransaction $tx): void
    {
        DB::transaction(function () use ($tx) {
            $locked = \App\Models\WalletTransaction::whereKey($tx->id)->lockForUpdate()->first();
            if (!$locked || $locked->status !== 'pending' || $locked->provider !== 'stripe') {
                return; // déjà traité / non applicable
            }

            $user = User::find($locked->user_id);
            if (!$user) {
                return;
            }

            // Le montant à créditer est en XAF (le champ `amount` de la transaction).
            $creditXaf = (float) $locked->amount;
            $user->creditKpay('XAF', $creditXaf);

            $locked->update([
                'status' => 'completed',
                'balance_after' => $user->kpayBalanceFor('XAF'),
                'metadata' => array_merge($locked->metadata ?? [], [
                    'completed_at' => now()->toIso8601String(),
                ]),
            ]);

            Log::info('[WalletController] ✅ Recharge carte (Stripe) créditée', [
                'transaction_id' => $locked->id,
                'amount_xaf' => $creditXaf,
            ]);
        });

        // Notification FCM (best-effort, hors transaction).
        try {
            $tx->refresh();
            $user = User::find($tx->user_id);
            if ($user) {
                $this->fcmService->sendToUser(
                    $user,
                    'Recharge par carte réussie',
                    'Votre wallet a été crédité de ' . number_format((float) $tx->amount, 0, ',', ' ')
                        . ' FCFA par carte bancaire.',
                    [
                        'type' => 'wallet_deposit_success',
                        'provider' => 'stripe',
                        'amount' => $tx->amount,
                        'payment_id' => $tx->id,
                    ]
                );
            }
        } catch (\Throwable $e) {
            Log::warning('[WalletController] FCM recharge stripe: ' . $e->getMessage());
        }
    }

    /**
     * Marque une recharge carte (Stripe) échouée — idempotent. Aucun crédit n'a été fait.
     */
    public function failStripeRecharge(\App\Models\WalletTransaction $tx, ?string $reason = null): void
    {
        $locked = \App\Models\WalletTransaction::whereKey($tx->id)->lockForUpdate()->first();
        if (!$locked || $locked->status !== 'pending' || $locked->provider !== 'stripe') {
            return;
        }
        $locked->update([
            'status' => 'failed',
            'metadata' => array_merge($locked->metadata ?? [], [
                'capture_error' => $reason ?? 'Paiement carte non abouti.',
                'failed_at' => now()->toIso8601String(),
            ]),
        ]);
        Log::info('[WalletController] Recharge carte (Stripe) échouée', ['transaction_id' => $locked->id]);
    }

    /**
     * Generate transaction reference
     */
    protected function generateTransactionReference(): string
    {
        $timestamp = now()->format('YmdHis');
        $random = strtoupper(\Illuminate\Support\Str::random(4));
        return "WTH-{$timestamp}-{$random}";
    }
}
