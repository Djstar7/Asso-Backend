<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PlatformWithdrawal;
use App\Models\Setting;
use App\Services\WalletService;
use App\Services\FirebaseMessagingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class WalletController extends Controller
{
    protected WalletService $walletService;
    protected FirebaseMessagingService $fcmService;

    public function __construct(WalletService $walletService, FirebaseMessagingService $fcmService)
    {
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
                // Créer d'abord la transaction wallet en status pending
                $currentBalance = $user->kpay_wallet_balance ?? 0;

                DB::beginTransaction();

                $walletTransaction = \App\Models\WalletTransaction::create([
                    'user_id' => $user->id,
                    'type' => 'credit',
                    'amount' => $amount,
                    'balance_before' => $currentBalance,
                    'balance_after' => $currentBalance, // Pas encore crédité
                    'description' => 'Recharge wallet via KPay',
                    'status' => 'pending',
                    'provider' => 'kpay',
                    'metadata' => [
                        'phone_number' => $phoneNumber,
                        'kpay_provider' => $provider,
                        'currency' => \App\Services\KPayCatalog::currencyForProvider($provider),
                        'initiated_at' => now()->toIso8601String(),
                    ],
                ]);

                Log::info("[WalletController] ✅ Wallet transaction created in pending state", [
                    'transaction_id' => $walletTransaction->id,
                ]);

                // Appeler KPay pour initier le paiement USSD (push direct)
                $kpayService = app(\App\Services\KPayService::class);

                $paymentResult = $kpayService->initializePayment([
                    'amount' => $amount,
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
                        'amount' => $amount,
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

            $paypalBalance = $user->paypal_wallet_balance ?? 0;
            $xafAvailable = $user->kpayAvailableFor('XAF');

            return response()->json([
                'success' => true,
                'data' => [
                    // Multi-devise : liste des soldes KPay par devise
                    'kpay_balances' => $kpayBalances,
                    // Compat rétro (XAF + PayPal)
                    'kpay_wallet_balance' => max(0, $xafAvailable),
                    'paypal_balance' => max(0, $paypalBalance),
                    'total_balance' => max(0, $xafAvailable + $paypalBalance),
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

        $amount = $request->input('amount');
        $provider = $request->input('provider'); // code opérateur KPay
        $paymentMethod = $provider;              // stocké tel quel dans platform_withdrawals
        $phone = $request->input('phone');
        $notes = $request->input('notes');

        // Devise déduite de l'opérateur (le retrait reste dans le pays de l'opérateur)
        $currency = \App\Services\KPayCatalog::currencyForProvider($provider);

        // Vérifier le solde KPay disponible dans cette devise
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

            // Récupérer le solde actuel dans la devise
            $currentBalance = $user->kpayBalanceFor($currency);

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

        // Calculer le montant minimum en USD basé sur le minimum FCFA
        $minWithdrawalAmountFcfa = Setting::get('min_withdrawal_amount', 100);
        $exchangeRate = 600; // 1 USD = 600 XAF
        $minWithdrawalAmountUsd = round($minWithdrawalAmountFcfa / $exchangeRate, 2);

        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:' . $minWithdrawalAmountUsd,
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

        $amountUsd = $request->input('amount');
        $paypalEmail = $request->input('paypal_email');
        $notes = $request->input('notes');

        // Convertir le montant USD en XAF (taux approximatif)
        $exchangeRate = 600; // 1 USD = 600 XAF
        $amountXaf = $amountUsd * $exchangeRate;

        // Vérifier le solde PayPal wallet disponible
        $availableBalance = $user->paypal_wallet_balance ?? 0;

        if ($amountXaf > $availableBalance) {
            Log::warning("[WalletController] ❌ Insufficient PayPal wallet balance", [
                'available_xaf' => $availableBalance,
                'requested_xaf' => $amountXaf,
                'requested_usd' => $amountUsd,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Solde PayPal insuffisant.',
            ], 400);
        }

        try {
            DB::beginTransaction();

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

            Log::info("[WalletController] ✅ PayPal withdrawal record created", [
                'withdrawal_id' => $withdrawal->id,
                'user_id' => $user->id,
                'amount_usd' => $amountUsd,
                'amount_xaf' => $amountXaf,
            ]);

            // TODO: Intégrer avec PayPal Payout API
            // Pour l'instant, on marque comme en cours

            $withdrawal->markAsProcessing();

            DB::commit();

            // Envoyer notification FCM
            try {
                $this->fcmService->sendToUser(
                    $user,
                    '💸 Retrait PayPal en cours',
                    "Votre demande de retrait de \${$amountUsd} USD ({$amountXaf} FCFA) vers {$paypalEmail} est en cours de traitement.",
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
