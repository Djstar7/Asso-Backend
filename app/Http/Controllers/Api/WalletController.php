<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PlatformWithdrawal;
use App\Models\Setting;
use App\Services\WalletService;
use App\Services\ExchangeRateService;
use App\Services\PayPalService;
use App\Services\FirebaseMessagingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class WalletController extends Controller
{
    protected WalletService $walletService;
    protected FirebaseMessagingService $fcmService;
    protected PayPalService $paypalService;

    public function __construct(
        WalletService $walletService,
        FirebaseMessagingService $fcmService,
        PayPalService $paypalService
    ) {
        $this->walletService = $walletService;
        $this->fcmService = $fcmService;
        $this->paypalService = $paypalService;
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
     * Crée un paiement KPay ou PayPal
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
            'payment_method' => 'required|in:kpay,paypal',
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

            // PayPal (à implémenter plus tard)
            if ($paymentMethod === 'paypal') {
                return response()->json([
                    'success' => false,
                    'message' => 'PayPal n\'est pas encore implémenté pour les recharges',
                ], 501);
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
            'provider' => 'nullable|string|in:kpay,paypal',
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
            'payment_provider' => 'required|string|in:kpay,paypal',
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

            // Solde PayPal DISPONIBLE (solde - bloqué), même base que le contrôle du retrait.
            $paypalBalance = ($user->paypal_wallet_balance ?? 0) - ($user->locked_paypal_balance ?? 0);
            $xafAvailable = $user->kpayAvailableFor('XAF');

            // Éligibilité au virement bancaire (Stripe Connect) : compte IBAN validé
            // + solde disponible dans la devise du payout (ex. EUR).
            $stripeReady = $user->stripe_account_status === 'approved'
                && !empty($user->stripe_account_id);
            $stripeCurrency = $this->stripePayoutCurrency($user->stripe_bank_country);
            $stripeAvailable = $stripeReady ? $user->kpayAvailableFor($stripeCurrency) : 0.0;

            // État de CONFIGURATION de chaque rail (clés API présentes côté plateforme).
            // Permet au mobile de GRISER un moyen non configuré au lieu de laisser
            // l'utilisateur tenter un retrait qui échouerait par une erreur.
            $kpayConfigured = app(\App\Services\KPayService::class)->isConfigured();
            $paypalConfigured = $this->paypalService->isConfigured();
            $stripeConfigured = app(\App\Services\StripeService::class)->isConfigured();

            return response()->json([
                'success' => true,
                'data' => [
                    // Multi-devise : liste des soldes KPay par devise
                    'kpay_balances' => $kpayBalances,
                    // Compat rétro (XAF + PayPal)
                    'kpay_wallet_balance' => max(0, $xafAvailable),
                    'paypal_balance' => max(0, $paypalBalance),
                    'total_balance' => max(0, $xafAvailable + $paypalBalance),
                    // Virement bancaire (IBAN via Stripe Connect)
                    'stripe' => [
                        'eligible' => $stripeReady,
                        'configured' => $stripeConfigured,
                        'status' => $user->stripe_account_status, // null|pending|approved|rejected
                        'currency' => $stripeCurrency,
                        'available' => max(0, $stripeAvailable),
                        'iban_last4' => $user->stripe_external_last4,
                    ],
                    // Vue unifiée par méthode (configuration + solde retirable).
                    'methods' => [
                        'kpay' => [
                            'configured' => $kpayConfigured,
                            'available' => max(0, $xafAvailable),
                            'currency' => 'XAF',
                        ],
                        'paypal' => [
                            'configured' => $paypalConfigured,
                            'available' => max(0, $paypalBalance),
                            'currency' => 'XAF',
                        ],
                        'stripe' => [
                            'configured' => $stripeConfigured,
                            'eligible' => $stripeReady,
                            'status' => $user->stripe_account_status,
                            'available' => max(0, $stripeAvailable),
                            'currency' => $stripeCurrency,
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
                    '💸 Retrait KPay en cours',
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

        // Devise du payout, déduite du pays de la banque du vendeur (ex. FR → EUR).
        $currency = $this->stripePayoutCurrency($user->stripe_bank_country);

        $minWithdrawalAmount = (float) Setting::get('min_stripe_withdrawal_amount', 5);

        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:' . $minWithdrawalAmount,
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

        // Montant dans la devise du payout (2 décimales, ex. euros).
        $amount = round((float) $request->input('amount'), 2);
        $notes = $request->input('notes');

        // Pré-contrôle rapide non autoritatif (la vérif autoritative est sous verrou).
        $availableBalance = $user->kpayAvailableFor($currency);
        if ($amount > $availableBalance) {
            Log::warning("[WalletController] ❌ Insufficient balance for Stripe withdrawal", [
                'currency' => $currency,
                'available' => $availableBalance,
                'requested_amount' => $amount,
            ]);
            return response()->json([
                'success' => false,
                'message' => "Solde $currency insuffisant. Disponible: " . number_format($availableBalance, 2, ',', ' ') . " $currency",
            ], 400);
        }

        try {
            DB::beginTransaction();

            // Verrou de ligne + re-vérification À L'INTÉRIEUR de la transaction (anti double-retrait).
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
                    'message' => "Solde $currency insuffisant. Disponible: " . number_format($lockedAvailable, 2, ',', ' ') . " $currency",
                ], 400);
            }

            $currentBalance = $walletBalance ? (float) $walletBalance->balance : 0.0;
            $ibanLast4 = $user->stripe_external_last4 ?? '****';
            $holder = $user->stripe_account_holder_name ?? $user->name;

            // Transaction wallet (débit immédiat pour bloquer les fonds).
            $walletTransaction = \App\Models\WalletTransaction::create([
                'user_id' => $user->id,
                'type' => 'debit',
                'amount' => $amount,
                'balance_before' => $currentBalance,
                'balance_after' => $currentBalance - $amount,
                'description' => "Virement IBAN ****{$ibanLast4}",
                'status' => 'pending',
                'provider' => 'stripe',
                'reference_type' => 'platform_withdrawal',
                'reference_id' => null,
                'metadata' => [
                    'iban_last4' => $ibanLast4,
                    'currency' => $currency,
                    'stripe_account_id' => $user->stripe_account_id,
                    'initiated_at' => now()->toIso8601String(),
                ],
            ]);

            // Débit immédiat (fonds bloqués).
            $user->debitKpay($currency, $amount);

            // Enregistrement de retrait.
            $withdrawal = PlatformWithdrawal::create([
                'user_id' => $user->id,
                'admin_id' => null,
                'amount_requested' => $amount,
                'commission_rate' => 0,
                'commission_amount' => 0,
                'amount_sent' => $amount,
                'currency' => $currency,
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
                'currency' => $currency,
            ]);

            // Appel Stripe : transfer (autoritatif) + payout IBAN (best-effort).
            $result = $stripe->payoutToVendor($user->stripe_account_id, $amount, $currency);

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
                    '🏦 Virement en cours',
                    "Votre demande de virement de {$amount} {$currency} vers votre IBAN ****{$ibanLast4} est en cours de traitement.",
                    [
                        'type' => 'wallet_withdrawal_processing',
                        'provider' => 'stripe',
                        'currency' => $currency,
                        'amount' => $amount,
                        'withdrawal_id' => $withdrawal->id,
                        'transaction_reference' => $withdrawal->transaction_reference,
                        'new_balance' => $user->kpayBalanceFor($currency),
                    ]
                );
            } catch (\Exception $e) {
                Log::error("[WalletController] ❌ Failed to send FCM notification: " . $e->getMessage());
            }

            return response()->json([
                'success' => true,
                'message' => 'Virement en cours de traitement. Les fonds arriveront sur votre compte sous 1 à 3 jours ouvrés.',
                'data' => [
                    'withdrawal_id' => $withdrawal->id,
                    'wallet_transaction_id' => $walletTransaction->id,
                    'transaction_reference' => $withdrawal->transaction_reference,
                    'amount' => $withdrawal->amount_requested,
                    'currency' => $currency,
                    'status' => 'processing',
                    'iban_last4' => $ibanLast4,
                    'new_balance' => $user->kpayBalanceFor($currency),
                    'balance_before' => $currentBalance,
                ],
            ]);
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
     * Initie un retrait PayPal Payout depuis le wallet
     *
     * POST /api/v1/wallet/withdraw/paypal
     */
    public function initiatePayPalWithdrawal(Request $request)
    {
        Log::info("[WalletController] ╔════════════════════════════════════════════════════════════════════╗");
        Log::info("[WalletController] ║ [PayPal Withdrawal] DEMANDE DE RETRAIT                            ║");
        Log::info("[WalletController] ╚════════════════════════════════════════════════════════════════════╝");

        $user = $request->user();

        // PayPal doit être configuré (client id/secret) avant tout débit du solde.
        if (!$this->paypalService->isConfigured()) {
            return response()->json([
                'success' => false,
                'message' => "Le retrait par PayPal n'est pas encore disponible.",
            ], 503);
        }

        // Le montant est saisi ET traité dans la devise de l'utilisateur (FCFA/XAF),
        // comme le solde affiché. Plus de conversion USD codée en dur (×600) sur le
        // montant demandé, qui provoquait l'erreur « Solde insuffisant » sur un solde
        // pourtant suffisant.
        $minWithdrawalAmount = (float) Setting::get('min_withdrawal_amount', 100);

        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:' . $minWithdrawalAmount,
            'paypal_email' => 'required|email',
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

        $amountXaf = (float) $request->input('amount');
        $paypalEmail = $request->input('paypal_email');
        $notes = $request->input('notes');

        // Solde PayPal disponible (FCFA) = solde - bloqué. Même unité que le montant saisi.
        $availableBalance = ($user->paypal_wallet_balance ?? 0) - ($user->locked_paypal_balance ?? 0);

        if ($amountXaf > $availableBalance) {
            Log::warning("[WalletController] ❌ Insufficient PayPal wallet balance", [
                'available_xaf' => $availableBalance,
                'requested_xaf' => $amountXaf,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Solde PayPal insuffisant.',
            ], 400);
        }

        // Équivalent USD (PayPal verse en USD) via les taux stockés en base, jamais
        // un taux fixe. Sert au versement réel et à l'affichage informatif.
        $amountUsd = ExchangeRateService::convertAmount('XAF', 'USD', $amountXaf);
        if ($amountUsd === null) {
            return response()->json([
                'success' => false,
                'message' => 'Conversion de devise momentanément indisponible. Réessayez plus tard.',
            ], 422);
        }

        try {
            DB::beginTransaction();

            // Débiter le wallet PayPal. Le débit est réel (fonds bloqués immédiatement)
            // mais la transaction visible reste 'pending' : elle ne passera 'completed'
            // que lorsque PayPal aura CONFIRMÉ le versement (réconciliation). Sans ce
            // débit, le retrait était un stub qui ne diminuait jamais le solde.
            $debitTx = $this->walletService->debit(
                $user,
                $amountXaf,
                "Retrait PayPal vers {$paypalEmail}",
                'platform_withdrawal',
                null,
                ['paypal_email' => $paypalEmail, 'amount_usd' => $amountUsd],
                'paypal',
                'pending'
            );

            // Créer l'enregistrement de retrait
            $withdrawal = PlatformWithdrawal::create([
                'user_id' => $user->id,
                'admin_id' => null,
                'amount_requested' => $amountXaf,
                'commission_rate' => 0,
                'commission_amount' => 0,
                'amount_sent' => $amountUsd,
                'currency' => 'USD',
                'provider' => 'paypal',
                'payment_method' => 'paypal',
                'payment_account' => $paypalEmail,
                'payment_account_name' => $user->name,
                'status' => 'pending',
                'transaction_reference' => $this->generateTransactionReference(),
                'admin_notes' => $notes,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            // Relier la transaction visible au retrait (pour la réconciliation).
            $debitTx->reference_id = $withdrawal->id;
            $debitTx->save();

            Log::info("[WalletController] ✅ PayPal withdrawal record created", [
                'withdrawal_id' => $withdrawal->id,
                'user_id' => $user->id,
                'amount_usd' => $amountUsd,
                'amount_xaf' => $amountXaf,
            ]);

            // Persister le débit + l'enregistrement AVANT l'appel réseau PayPal (on ne
            // garde jamais une transaction DB ouverte pendant un appel HTTP externe).
            DB::commit();

            // Versement PayPal RÉEL (Payouts API), hors transaction DB.
            $payout = $this->paypalService->payout(
                $paypalEmail,
                (float) $amountUsd,
                'USD',
                $withdrawal->transaction_reference,
                "Retrait ASSO #{$withdrawal->id}"
            );

            if (!($payout['success'] ?? false)) {
                // Échec du versement → recréditer le solde et marquer l'échec. Aucune
                // transaction ne reste faussement 'completed'.
                $this->walletService->credit(
                    $user,
                    $amountXaf,
                    null,
                    "Remboursement — retrait PayPal échoué (réf. {$withdrawal->transaction_reference})",
                    ['withdrawal_id' => $withdrawal->id, 'refund' => true],
                    'paypal'
                );
                $debitTx->update([
                    'status' => 'failed',
                    'metadata' => array_merge($debitTx->metadata ?? [], [
                        'failure_reason' => $payout['message'] ?? 'Versement PayPal refusé.',
                    ]),
                ]);
                $withdrawal->markAsFailed('paypal_payout_failed', $payout['message'] ?? 'Versement PayPal refusé.');

                Log::warning("[WalletController] ❌ PayPal payout échoué, solde recrédité", [
                    'withdrawal_id' => $withdrawal->id,
                    'message' => $payout['message'] ?? null,
                ]);

                return response()->json([
                    'success' => false,
                    'message' => $payout['message'] ?? "Le versement PayPal a échoué. Votre solde a été recrédité.",
                ], 422);
            }

            // Versement ACCEPTÉ par PayPal. Le règlement final est asynchrone : on reste
            // en 'processing' et on stocke le batch pour la réconciliation ultérieure
            // (checkWithdrawalStatus). La transaction ne passera 'completed' qu'une fois
            // le batch confirmé SUCCESS par PayPal.
            $withdrawal->update([
                'status' => 'processing',
                'paypal_batch_id' => $payout['payout_batch_id'] ?? null,
                'paypal_payout_item_id' => $payout['data']['items'][0]['payout_item_id'] ?? null,
                'paypal_response' => $payout['data'] ?? null,
            ]);

            // Envoyer notification FCM
            try {
                $this->fcmService->sendToUser(
                    $user,
                    '💸 Retrait PayPal en cours',
                    "Votre demande de retrait de " . number_format($amountXaf, 0, ',', ' ') . " FCFA (~\${$amountUsd} USD) vers {$paypalEmail} est en cours de traitement.",
                    [
                        'type' => 'wallet_withdrawal_processing',
                        'provider' => 'paypal',
                        'amount_usd' => $amountUsd,
                        'amount_xaf' => $amountXaf,
                        'withdrawal_id' => $withdrawal->id,
                        'transaction_reference' => $withdrawal->transaction_reference,
                        'paypal_email' => $paypalEmail,
                    ]
                );
                Log::info("[WalletController] 📬 FCM notification sent for PayPal withdrawal");
            } catch (\Exception $e) {
                Log::error("[WalletController] ❌ Failed to send FCM notification: " . $e->getMessage());
            }

            return response()->json([
                'success' => true,
                'message' => 'Retrait PayPal en cours de traitement.',
                'data' => [
                    'withdrawal_id' => $withdrawal->id,
                    'transaction_reference' => $withdrawal->transaction_reference,
                    'amount_usd' => $withdrawal->amount_sent,
                    'amount_xaf' => $withdrawal->amount_requested,
                    'status' => 'processing',
                ],
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("[WalletController] ❌ PayPal withdrawal error: " . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
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
                } elseif ($withdrawal->provider === 'paypal' && !empty($withdrawal->paypal_batch_id)) {
                    // PayPal : réconcilier l'état réel du batch de payout.
                    $this->reconcilePayPalWithdrawal($withdrawal);
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
     * Réconcilie un retrait PayPal avec l'état réel du batch de payout.
     * Ne marque 'completed' QUE si PayPal confirme le règlement (SUCCESS). En cas
     * d'échec terminal, recrédite le solde et marque l'échec.
     */
    private function reconcilePayPalWithdrawal(PlatformWithdrawal $withdrawal): void
    {
        $status = $this->paypalService->getPayoutStatus($withdrawal->paypal_batch_id);
        if (!($status['success'] ?? false)) {
            return; // Indisponible : on retentera au prochain poll.
        }

        $itemStatus = strtoupper((string) ($status['item_status'] ?? ''));
        $batchStatus = strtoupper((string) ($status['batch_status'] ?? ''));

        // Transaction wallet visible liée à ce retrait (pour refléter l'état réel).
        $debitTx = \App\Models\WalletTransaction::where('reference_type', 'platform_withdrawal')
            ->where('reference_id', $withdrawal->id)
            ->where('provider', 'paypal')
            ->where('type', 'debit')
            ->first();

        // Règlement confirmé.
        if ($itemStatus === 'SUCCESS' || $batchStatus === 'SUCCESS') {
            $withdrawal->markAsCompleted($withdrawal->paypal_batch_id, $status['data'] ?? []);
            $debitTx?->update(['status' => 'completed']);
            Log::info('[WalletController] ✅ Retrait PayPal réglé (SUCCESS)', [
                'withdrawal_id' => $withdrawal->id,
            ]);
            return;
        }

        // Échec terminal → recréditer le solde.
        $terminalFailures = ['DENIED', 'FAILED', 'RETURNED', 'BLOCKED', 'REFUNDED', 'CANCELED'];
        if (in_array($itemStatus, $terminalFailures) || in_array($batchStatus, ['DENIED', 'CANCELED'])) {
            $user = $withdrawal->user;
            if ($user && $debitTx && $debitTx->status !== 'failed') {
                $this->walletService->credit(
                    $user,
                    (float) $withdrawal->amount_requested,
                    null,
                    "Remboursement — versement PayPal non abouti (réf. {$withdrawal->transaction_reference})",
                    ['withdrawal_id' => $withdrawal->id, 'refund' => true],
                    'paypal'
                );
                $debitTx->update(['status' => 'failed']);
            }
            $withdrawal->markAsFailed('paypal_payout_' . strtolower($itemStatus ?: $batchStatus), "Versement PayPal non abouti ({$itemStatus}{$batchStatus}).");
            Log::warning('[WalletController] ❌ Retrait PayPal échoué, solde recrédité', [
                'withdrawal_id' => $withdrawal->id,
                'item_status' => $itemStatus,
                'batch_status' => $batchStatus,
            ]);
        }
        // Sinon (PENDING/PROCESSING/UNCLAIMED) : on laisse en 'processing'.
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

    // ============================================
    // MÉTHODES PAYPAL (NATIVE)
    // ============================================

    /**
     * Créer une commande PayPal native pour le paiement
     *
     * POST /api/v1/wallet/paypal/create-native-order
     */
    public function createNativePayPalOrder(Request $request)
    {
        Log::info("╔════════════════════════════════════════════════════════════════════╗");
        Log::info("║ [WalletController] 🔵 CREATE PAYPAL NATIVE ORDER                  ║");
        Log::info("╚════════════════════════════════════════════════════════════════════╝");

        $minDepositAmount = Setting::get('min_deposit_amount', 100);

        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:' . $minDepositAmount,
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

            Log::info("[WalletController] 📝 Request details", [
                'user_id' => $user->id,
                'amount' => $amount,
            ]);

            // Créer d'abord la transaction wallet en status pending
            $currentBalance = $user->paypal_wallet_balance ?? 0;

            DB::beginTransaction();

            $walletTransaction = \App\Models\WalletTransaction::create([
                'user_id' => $user->id,
                'type' => 'credit',
                'amount' => $amount,
                'balance_before' => $currentBalance,
                'balance_after' => $currentBalance, // Pas encore crédité
                'description' => 'Recharge wallet via PayPal',
                'status' => 'pending',
                'provider' => 'paypal',
                'metadata' => [
                    'initiated_at' => now()->toIso8601String(),
                ],
            ]);

            Log::info("[WalletController] ✅ Wallet transaction created in pending state", [
                'transaction_id' => $walletTransaction->id,
            ]);

            // Appeler PayPal pour créer l'ordre
            $paypalService = app(\App\Services\PayPalService::class);

            $orderResult = $paypalService->createOrder([
                'amount' => $amount,
                'user_id' => $user->id,
                'return_url' => url('/api/v1/wallet/paypal/return'),
                'cancel_url' => url('/api/v1/wallet/paypal/cancel'),
            ]);

            if (!$orderResult['success']) {
                DB::rollBack();

                // Supprimer la transaction wallet si la création de l'ordre a échoué
                $walletTransaction->delete();

                Log::error("[WalletController] ❌ PayPal order creation failed", [
                    'error' => $orderResult['message'] ?? 'Unknown error',
                ]);

                return response()->json([
                    'success' => false,
                    'message' => $orderResult['message'] ?? 'Erreur lors de la création de l\'ordre PayPal',
                ], 400);
            }

            // Mettre à jour la transaction avec les infos PayPal
            $walletTransaction->metadata = array_merge($walletTransaction->metadata ?? [], [
                'provider_reference' => $orderResult['order_id'] ?? null,
                'paypal_order_id' => $orderResult['order_id'] ?? null,
                'paypal_status' => 'CREATED',
                'amount_usd' => $orderResult['amount_usd'] ?? null,
            ]);
            $walletTransaction->save();

            DB::commit();

            Log::info("[WalletController] ✅ PayPal order created", [
                'transaction_id' => $walletTransaction->id,
                'order_id' => $orderResult['order_id'],
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Ordre PayPal créé avec succès',
                'data' => [
                    'payment_id' => $walletTransaction->id,
                    'order_id' => $orderResult['order_id'],
                    'amount' => $amount,
                    'amount_usd' => $orderResult['amount_usd'],
                    'approval_url' => $orderResult['approval_url'],
                    'client_id' => $orderResult['client_id'],
                ],
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("[WalletController] ❌ CREATE PAYPAL ORDER FAILED: " . $e->getMessage());
            Log::error($e->getTraceAsString());

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la création de l\'ordre PayPal',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Capturer une commande PayPal native après approbation
     *
     * POST /api/v1/wallet/paypal/capture-native-order
     */
    public function captureNativePayPalOrder(Request $request)
    {
        Log::info("╔════════════════════════════════════════════════════════════════════╗");
        Log::info("║ [WalletController] 🔵 CAPTURE PAYPAL NATIVE ORDER                 ║");
        Log::info("╚════════════════════════════════════════════════════════════════════╝");

        $validator = Validator::make($request->all(), [
            'payment_id' => 'required|integer',
            'order_id' => 'required|string',
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
            $paymentId = $request->payment_id;
            $orderId = $request->order_id;

            Log::info("[WalletController] 📝 Capture request", [
                'user_id' => $user->id,
                'payment_id' => $paymentId,
                'order_id' => $orderId,
            ]);

            // Récupérer la transaction wallet
            $walletTransaction = \App\Models\WalletTransaction::where('id', $paymentId)
                ->where('user_id', $user->id)
                ->where('status', 'pending')
                ->first();

            if (!$walletTransaction) {
                return response()->json([
                    'success' => false,
                    'message' => 'Transaction non trouvée ou déjà traitée',
                ], 404);
            }

            DB::beginTransaction();

            // Capturer l'ordre PayPal
            $paypalService = app(\App\Services\PayPalService::class);
            $captureResult = $paypalService->captureOrder($orderId);

            if (!$captureResult['success']) {
                DB::rollBack();

                Log::error("[WalletController] ❌ PayPal capture failed", [
                    'order_id' => $orderId,
                    'error' => $captureResult['message'] ?? 'Unknown error',
                ]);

                // Marquer la transaction comme échouée
                $walletTransaction->status = 'failed';
                $walletTransaction->metadata = array_merge($walletTransaction->metadata ?? [], [
                    'capture_error' => $captureResult['message'] ?? 'Capture failed',
                    'failed_at' => now()->toIso8601String(),
                ]);
                $walletTransaction->save();

                // Envoyer notification FCM d'échec
                try {
                    $this->fcmService->sendToUser(
                        $user,
                        '❌ Échec de paiement PayPal',
                        "Votre paiement de {$walletTransaction->amount} FCFA via PayPal a échoué. Veuillez réessayer.",
                        [
                            'type' => 'wallet_deposit_failed',
                            'provider' => 'paypal',
                            'amount' => $walletTransaction->amount,
                            'payment_id' => $walletTransaction->id,
                            'order_id' => $orderId,
                            'error' => $captureResult['message'] ?? 'Capture failed',
                        ]
                    );
                    Log::info("[WalletController] 📬 FCM notification sent for PayPal deposit failure");
                } catch (\Exception $e) {
                    Log::error("[WalletController] ❌ Failed to send FCM notification: " . $e->getMessage());
                }

                return response()->json([
                    'success' => false,
                    'message' => $captureResult['message'] ?? 'Échec de la capture du paiement',
                ], 400);
            }

            // Créditer le wallet PayPal de l'utilisateur
            $amount = $walletTransaction->amount;
            $user->increment('paypal_wallet_balance', $amount);

            // Mettre à jour la transaction
            $walletTransaction->status = 'completed';
            $walletTransaction->balance_after = $user->paypal_wallet_balance;
            $walletTransaction->metadata = array_merge($walletTransaction->metadata ?? [], [
                'paypal_status' => $captureResult['status'],
                'paypal_capture_data' => $captureResult['data'] ?? [],
                'completed_at' => now()->toIso8601String(),
            ]);
            $walletTransaction->save();

            DB::commit();

            Log::info("[WalletController] ✅ PayPal order captured", [
                'payment_id' => $paymentId,
                'order_id' => $orderId,
                'amount' => $amount,
                'new_balance' => $user->paypal_wallet_balance,
            ]);

            // Envoyer notification FCM
            try {
                $this->fcmService->sendToUser(
                    $user,
                    '💰 Recharge PayPal réussie',
                    "Votre wallet a été crédité de {$amount} FCFA via PayPal. Nouveau solde: " . number_format($user->paypal_wallet_balance, 0, ',', ' ') . " FCFA",
                    [
                        'type' => 'wallet_deposit_success',
                        'provider' => 'paypal',
                        'amount' => $amount,
                        'new_balance' => $user->paypal_wallet_balance,
                        'payment_id' => $walletTransaction->id,
                        'order_id' => $orderId,
                    ]
                );
                Log::info("[WalletController] 📬 FCM notification sent for PayPal deposit");
            } catch (\Exception $e) {
                Log::error("[WalletController] ❌ Failed to send FCM notification: " . $e->getMessage());
            }

            return response()->json([
                'success' => true,
                'message' => 'Paiement capturé avec succès',
                'data' => [
                    'payment_id' => $walletTransaction->id,
                    'amount' => $amount,
                    'new_balance' => $user->paypal_wallet_balance,
                ],
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("[WalletController] ❌ CAPTURE PAYPAL ORDER FAILED: " . $e->getMessage());
            Log::error($e->getTraceAsString());

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la capture du paiement',
                'error' => $e->getMessage(),
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
     * Generate transaction reference
     */
    protected function generateTransactionReference(): string
    {
        $timestamp = now()->format('YmdHis');
        $random = strtoupper(\Illuminate\Support\Str::random(4));
        return "WTH-{$timestamp}-{$random}";
    }
}
