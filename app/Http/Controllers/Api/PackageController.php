<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\InvalidSalesCodeException;
use App\Http\Controllers\Controller;
use App\Models\Package;
use App\Models\PackageSubscription;
use App\Models\Product;
use App\Models\SalesAgent;
use App\Models\VendorPackage;
use App\Services\WalletService;
use App\Services\InvoiceService;
use App\Services\InvoiceGenerator;
use App\Services\FcmService;
use App\Services\PackageSubscriptionService;
use App\Services\PaymentMethodService;
use App\Services\SalesCommissionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PackageController extends Controller
{
    protected WalletService $walletService;
    protected InvoiceService $invoiceService;
    protected InvoiceGenerator $invoiceGenerator;
    protected FcmService $fcmService;
    protected PackageSubscriptionService $packageSubscriptionService;
    protected SalesCommissionService $salesCommissionService;

    public function __construct(
        WalletService $walletService,
        InvoiceService $invoiceService,
        InvoiceGenerator $invoiceGenerator,
        FcmService $fcmService,
        PackageSubscriptionService $packageSubscriptionService,
        SalesCommissionService $salesCommissionService
    ) {
        $this->walletService = $walletService;
        $this->invoiceService = $invoiceService;
        $this->invoiceGenerator = $invoiceGenerator;
        $this->fcmService = $fcmService;
        $this->packageSubscriptionService = $packageSubscriptionService;
        $this->salesCommissionService = $salesCommissionService;
    }

    /**
     * List all active storage packages
     */
    public function index()
    {
        $packages = Package::ofType('storage')
            ->active()
            ->ordered()
            ->get();

        return response()->json([
            'success' => true,
            'packages' => $packages->map(fn($package) => [
                'id' => $package->id,
                'name' => $package->name,
                'description' => $package->description,
                'price' => (float) $package->price,
                'formatted_price' => $package->formatted_price,
                'duration_days' => $package->duration_days,
                'formatted_duration' => $package->formatted_duration,
                'storage_size_mb' => $package->storage_size_mb,
                'formatted_storage_size' => $package->formatted_storage_size,
                'benefits' => $package->benefits,
                'is_popular' => (bool) $package->is_popular,
            ]),
        ]);
    }

    /**
     * List all active certification packages
     */
    public function certificationPackages()
    {
        $packages = Package::ofType('certification')
            ->active()
            ->ordered()
            ->get();

        return response()->json([
            'success' => true,
            'packages' => $packages->map(fn($package) => [
                'id' => $package->id,
                'name' => $package->name,
                'description' => $package->description,
                'price' => (float) $package->price,
                'formatted_price' => $package->formatted_price,
                'duration_days' => $package->duration_days,
                'formatted_duration' => $package->formatted_duration,
                'benefits' => $package->benefits,
                'is_popular' => (bool) $package->is_popular,
            ]),
        ]);
    }

    /**
     * Forfaits Asso Ads : sponsoriser un produit.
     *
     * GET /v1/packages/boost
     *
     * `reach_users` est le quota d'impressions acheté : la campagne s'arrête
     * quand il est épuisé, ou à l'échéance, au premier des deux.
     */
    public function boostPackages()
    {
        $packages = Package::ofType('boost')
            ->active()
            ->ordered()
            ->get();

        return response()->json([
            'success' => true,
            'packages' => $packages->map(fn($package) => [
                'id' => $package->id,
                'name' => $package->name,
                'description' => $package->description,
                'price' => (float) $package->price,
                'formatted_price' => $package->formatted_price,
                'duration_days' => $package->duration_days,
                'formatted_duration' => $package->formatted_duration,
                'reach_users' => (int) $package->reach_users,
                'formatted_reach' => number_format((int) $package->reach_users, 0, ',', ' ') . ' personnes',
                'is_popular' => (bool) $package->is_popular,
            ]),
        ]);
    }

    /**
     * Souscrire à un forfait (stockage, certification ou sponsoring).
     *
     * POST /v1/packages/subscribe
     *   payment_mode = wallet        → débit immédiat du solde Wallet ASSO (activation instantanée)
     *                  kpay_direct   → Mobile Money (USSD), confirmation par polling
     *                  stripe_direct → carte (Payment Sheet), confirmation par polling
     * Rétro-compat : payment_mode absent + wallet_type=kpay ⇒ wallet.
     *
     * `product_id` est requis pour un forfait de type boost : c'est le produit
     * qui sera sponsorisé.
     */
    public function subscribe(Request $request)
    {
        $validated = $request->validate([
            'package_id' => 'required|exists:packages,id',
            'product_id' => 'nullable|exists:products,id',
            'wallet_type' => 'nullable|in:kpay',
            'payment_mode' => 'nullable|in:wallet,kpay_direct,stripe_direct',
            'provider' => 'required_if:payment_mode,kpay_direct|nullable|string',
            'phone_number' => 'required_if:payment_mode,kpay_direct|nullable|string',
            // P6 : code commercial / code de parrainage (facultatif)
            'sales_code' => 'nullable|string|max:32',
        ]);

        $user = $request->user();
        $package = Package::findOrFail($validated['package_id']);
        $paymentMode = $validated['payment_mode'] ?? 'wallet';

        // Produit à sponsoriser : la propriété et l'éligibilité sont vérifiées
        // par assertSubscribable, avant tout paiement.
        $product = !empty($validated['product_id'])
            ? Product::find($validated['product_id'])
            : null;

        try {
            $this->packageSubscriptionService->assertSubscribable($user, $package, $product);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        // Code commercial vérifié AVANT tout paiement : une faute de frappe ne doit pas
        // faire perdre la vente au commercial.
        try {
            $salesAgent = $this->salesCommissionService->resolveForVendor($validated['sales_code'] ?? null, $user);
        } catch (InvalidSalesCodeException $e) {
            return response()->json([
                'success' => false,
                'code' => 'invalid_sales_code',
                'message' => $e->getMessage(),
                'errors' => ['sales_code' => [$e->getMessage()]],
            ], 422);
        }

        if (in_array($paymentMode, ['kpay_direct', 'stripe_direct'], true)) {
            return $this->subscribeDirect($request, $user, $package, $paymentMode, $salesAgent, $product);
        }

        // ── Paiement par SOLDE Wallet ASSO ──
        $canPay = $this->walletService->canPayWithWallet($user, (float) $package->price, 'kpay');
        if (!$canPay['can_pay']) {
            return response()->json([
                'success' => false,
                'code' => 'insufficient_balance',
                'message' => $canPay['message'],
                'data' => [
                    'current_balance' => $canPay['available_balance'],
                    'required_amount' => (float) $package->price,
                    'missing_amount' => $canPay['missing_amount'],
                ],
            ], 422);
        }

        try {
            $subscription = $this->packageSubscriptionService->payWithWallet($user, $package, $salesAgent, $product);
        } catch (\Exception $e) {
            Log::error('[PackageController] Souscription wallet échouée', ['user_id' => $user->id, 'error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => match ($package->type) {
                'certification' => __('packages.certification_activated'),
                'boost' => __('packages.boost_activated'),
                default => __('packages.storage_activated'),
            },
            'payment_mode' => 'wallet',
            'subscription_id' => $subscription->id,
            'status' => $subscription->status, // paid
            'payment_reference' => $subscription->payment_reference,
            'sales_code' => $subscription->sales_code,
            'data' => $this->subscriptionPayload($subscription),
        ], 201);
    }

    /**
     * Souscription d'un package par RAIL DIRECT (kpay_direct / stripe_direct).
     *
     * Crée un « intent » d'abonnement (PackageSubscription en 'pending') et initie le
     * paiement chez le PSP. Le VendorPackage n'est créé/cumulé qu'à la confirmation du
     * paiement (polling GET /v1/packages/subscription/{id}/payment-status). Aucune
     * validation vendeur ici : l'abonnement s'active dès que l'argent est encaissé.
     */
    private function subscribeDirect(Request $request, $user, Package $package, string $paymentMode, ?SalesAgent $salesAgent = null, ?Product $product = null)
    {
        // Garde-fou : le rail carte (Stripe natif) n'est proposé que s'il est réellement
        // fonctionnel (clés configurées + activé). Sinon on bloque immédiatement.
        if ($paymentMode === 'stripe_direct' && !PaymentMethodService::isEnabled('stripe')) {
            return response()->json([
                'success' => false,
                'message' => __('payments.stripe_unavailable_choose_other'),
            ], 422);
        }

        try {
            $subscription = $this->packageSubscriptionService->createDirect(
                user: $user,
                package: $package,
                paymentMode: $paymentMode,
                kpayProvider: $request->input('provider'),
                kpayPhone: $request->input('phone_number'),
                salesAgent: $salesAgent,
                product: $product,
            );
        } catch (\Exception $e) {
            Log::error('[PackageController] ❌ Direct subscription init failed', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => match ($paymentMode) {
                'kpay_direct' => __('packages.subscription_created_confirm_on_phone'),
                'stripe_direct' => __('packages.subscription_created_complete_card'),
                default => __('packages.subscription_created'),
            },
            'payment_mode' => $paymentMode,
            'subscription_id' => $subscription->id,
            'status' => $subscription->status, // pending
            'payment_reference' => $subscription->payment_reference,
            'sales_code' => $subscription->sales_code,
            // Carte native (stripe_direct) : confirmation via Payment Sheet, puis polling.
            'client_secret' => $paymentMode === 'stripe_direct' ? ($subscription->client_secret ?? null) : null,
            'payment_intent_id' => $paymentMode === 'stripe_direct' ? ($subscription->payment_intent_id ?? null) : null,
            'publishable_key' => $paymentMode === 'stripe_direct' ? ($subscription->stripe_publishable_key ?? null) : null,
        ], 201);
    }

    /**
     * Statut de paiement d'un abonnement direct (pollable).
     * GET /v1/packages/subscription/{id}/payment-status
     *
     * Re-vérifie chez le PSP et active l'abonnement (crée/cumule le VendorPackage) dès
     * que le paiement est confirmé. Renvoie {status: pending|paid|failed, ...}.
     */
    public function subscriptionPaymentStatus(Request $request, $id)
    {
        $subscription = PackageSubscription::where('user_id', $request->user()->id)
            ->findOrFail($id);

        if ($subscription->isPending()) {
            $this->packageSubscriptionService->checkAndConfirm($subscription);
            $subscription->refresh();
        }

        // Mobile Money refusé : motif de l'opérateur (wrong_network, insufficient_funds…).
        $paymentFailure = $subscription->status === 'failed' && $subscription->payment_method === 'kpay_direct'
            ? app(\App\Services\MobileMoneyGateway::class)->failureFor($subscription->payment_reference)
            : null;

        return response()->json([
            'success' => true,
            'data' => $this->subscriptionPayload($subscription) + ['payment_failure' => $paymentFailure],
        ]);
    }

    /** Représentation API d'une souscription (statut + résultat de l'activation). */
    private function subscriptionPayload(PackageSubscription $subscription): array
    {
        $vendorPackage = $subscription->vendor_package_id
            ? VendorPackage::find($subscription->vendor_package_id)
            : null;
        $package = Package::find($subscription->package_id);

        return [
            'subscription_id' => $subscription->id,
            // pending | paid | failed
            'status' => $subscription->status,
            'payment_mode' => $subscription->payment_method,
            'payment_reference' => $subscription->payment_reference,
            'package_type' => $package?->type,
            'package_name' => $package?->name,
            'amount' => (float) $subscription->amount_xaf,
            'sales_code' => $subscription->sales_code,
            'certification_expires_at' => $subscription->metadata['certification_expires_at'] ?? null,
            'vendor_package_id' => $subscription->vendor_package_id,
            'vendor_package' => $vendorPackage ? [
                'id' => $vendorPackage->id,
                'storage_total_mb' => (float) $vendorPackage->storage_total_mb,
                'storage_remaining_mb' => (float) $vendorPackage->storage_remaining_mb,
                'expires_at' => $vendorPackage->expires_at->toIso8601String(),
                'status' => $vendorPackage->status,
                'is_cumulative' => $vendorPackage->package_id === null,
            ] : null,
        ];
    }

    /**
     * Get current active package for the authenticated user
     */
    public function currentPackage(Request $request)
    {
        $user = $request->user();
        $vendorPackage = $user->activeVendorPackage;

        if (!$vendorPackage) {
            // Un vendeur sans forfait est un état normal, pas une ressource
            // absente : le 404 faisait passer la réponse pour une erreur côté
            // client alors que `has_package: false` suffit à décrire le cas.
            return response()->json([
                'success' => true,
                'message' => __('packages.no_active_package'),
                'has_package' => false,
                'vendor_package' => null,
            ]);
        }

        // Load package relationship only if package_id is not null
        if ($vendorPackage->package_id) {
            $vendorPackage->load('package');
        }

        // Prepare package data based on whether it's cumulative or not
        $packageData = $vendorPackage->package_id && $vendorPackage->package
            ? [
                'id' => $vendorPackage->package->id,
                'name' => $vendorPackage->package->name,
                'description' => $vendorPackage->package->description,
                'price' => (float) $vendorPackage->package->price,
                'formatted_price' => $vendorPackage->package->formatted_price,
                'duration_days' => $vendorPackage->package->duration_days,
                'formatted_duration' => $vendorPackage->package->formatted_duration,
                'storage_size_mb' => $vendorPackage->package->storage_size_mb,
                'formatted_storage_size' => $vendorPackage->package->formatted_storage_size,
            ]
            : [
                'id' => null,
                'name' => $vendorPackage->custom_name ?? __('packages.cumulative.name'),
                'description' => __('packages.cumulative.description'),
                'price' => null,
                'formatted_price' => __('packages.cumulative.price'),
                'duration_days' => null,
                'formatted_duration' => __('packages.cumulative.duration'),
                'storage_size_mb' => (float) $vendorPackage->storage_total_mb,
                'formatted_storage_size' => number_format($vendorPackage->storage_total_mb, 0) . ' MB',
            ];

        return response()->json([
            'success' => true,
            'has_package' => true,
            'vendor_package' => [
                'id' => $vendorPackage->id,
                'storage_total_mb' => (float) $vendorPackage->storage_total_mb,
                'storage_used_mb' => (float) $vendorPackage->storage_used_mb,
                'storage_remaining_mb' => (float) $vendorPackage->storage_remaining_mb,
                'storage_percentage_used' => $vendorPackage->storage_total_mb > 0
                    ? round(($vendorPackage->storage_used_mb / $vendorPackage->storage_total_mb) * 100, 2)
                    : 0,
                'purchased_at' => $vendorPackage->purchased_at->toIso8601String(),
                'expires_at' => $vendorPackage->expires_at->toIso8601String(),
                'days_remaining' => now()->diffInDays($vendorPackage->expires_at, false),
                'status' => $vendorPackage->status,
                'payment_reference' => $vendorPackage->payment_reference,
                'is_cumulative' => $vendorPackage->package_id === null,
                'package' => $packageData,
            ],
        ]);
    }
}
