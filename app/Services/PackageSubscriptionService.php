<?php

namespace App\Services;

use App\Models\Package;
use App\Models\PackageSubscription;
use App\Models\Product;
use App\Models\ProductBoost;
use App\Models\SalesAgent;
use App\Models\User;
use App\Models\VendorPackage;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Paiement d'un abonnement vendeur (package storage/certification) par RAIL DIRECT.
 *
 * Reproduit EXACTEMENT la mécanique des commandes directes (OrderService) :
 *  - kpay_direct   : PayIn Mobile Money (USSD), confirmation par polling
 *  - stripe_direct : carte NATIVE (PaymentIntent) → client_secret (Payment Sheet), polling
 *
 * Le VendorPackage n'est créé/cumulé QU'À la confirmation du paiement (applyPackage),
 * ce qui garantit qu'aucun espace n'est crédité tant que l'argent n'est pas encaissé.
 */
class PackageSubscriptionService
{
    protected FcmService $fcmService;
    protected InvoiceGenerator $invoiceGenerator;

    public function __construct(FcmService $fcmService, InvoiceGenerator $invoiceGenerator)
    {
        $this->fcmService = $fcmService;
        $this->invoiceGenerator = $invoiceGenerator;
    }

    /**
     * Crée l'intent d'abonnement direct et initie le paiement chez le PSP.
     * Renvoie la PackageSubscription (statut 'pending', approval_url éventuelle).
     *
     * @param string $paymentMode kpay_direct | stripe_direct
     */
    public function createDirect(
        User $user,
        Package $package,
        string $paymentMode,
        ?string $kpayProvider = null,
        ?string $kpayPhone = null,
        ?SalesAgent $salesAgent = null,
        ?Product $product = null
    ): PackageSubscription {
        $this->assertSubscribable($user, $package, $product);
        $amountXaf = (float) $package->price;

        $subscription = PackageSubscription::create([
            'user_id' => $user->id,
            'package_id' => $package->id,
            'sales_agent_id' => $salesAgent?->id,
            'sales_code' => $salesAgent?->code,
            'payment_method' => $paymentMode,
            'status' => 'pending',
            'amount_xaf' => $amountXaf,
            'metadata' => [
                'package_name' => $package->name,
                'provider' => $kpayProvider,
                // Boost : la campagne ne s'ouvrira qu'à la confirmation, mais le
                // produit ciblé doit survivre au délai de paiement.
                'boost_product_id' => $package->type === 'boost' ? $product?->id : null,
            ],
        ]);

        // Peut lancer une exception → la souscription reste 'pending' sans référence,
        // le contrôleur renvoie l'erreur (rien n'est crédité au vendeur).
        // Pour la carte native, renvoie ['client_secret','payment_intent_id','publishable_key'].
        try {
            $stripeMeta = $this->initiatePayment($subscription, $amountXaf, $paymentMode, $kpayProvider, $kpayPhone);
        } catch (\Throwable $e) {
            // Rien n'a été encaissé : on clôt la tentative pour ne pas la laisser « en attente ».
            $this->fail($subscription, 'init_failed');
            throw $e;
        }

        $subscription = $subscription->fresh();

        // Attributs transitoires (non persistés) consommés par le contrôleur pour la
        // Payment Sheet côté mobile.
        if (is_array($stripeMeta)) {
            $subscription->client_secret = $stripeMeta['client_secret'] ?? null;
            $subscription->payment_intent_id = $stripeMeta['payment_intent_id'] ?? null;
            $subscription->stripe_publishable_key = $stripeMeta['publishable_key'] ?? null;
        }

        return $subscription;
    }

    /**
     * Initie le paiement direct chez le PSP et enregistre la référence sur la souscription.
     * Renvoie null (KPay) ou, pour la carte native, un tableau
     * ['client_secret','payment_intent_id','publishable_key']. Calqué sur
     * OrderService::initiateDirectPayment.
     */
    protected function initiatePayment(
        PackageSubscription $subscription,
        float $total,
        string $paymentMode,
        ?string $kpayProvider,
        ?string $kpayPhone
    ): ?array {
        $externalRef = 'SUB-' . $subscription->id;

        if ($paymentMode === 'kpay_direct') {
            $payCurrency = KPayCatalog::currencyForProvider($kpayProvider);
            $payAmount = (float) round($total);

            if ($payCurrency !== 'XAF') {
                $converted = ExchangeRateService::convertAmount('XAF', $payCurrency, $total);
                if ($converted === null) {
                    throw new \Exception("Conversion XAF → {$payCurrency} indisponible. Réessayez plus tard.");
                }
                $payAmount = (float) round($converted);
            }

            $result = app(KPayService::class)->initializePayment([
                'amount' => $payAmount,
                'provider' => $kpayProvider,
                'phone_number' => $kpayPhone,
                'description' => "Abonnement {$subscription->metadata['package_name']}",
                'external_reference' => $externalRef,
            ]);

            if (empty($result['success'])) {
                throw new \Exception($result['message'] ?? "Échec de l'initiation du paiement KPay.");
            }

            $subscription->update([
                'payment_reference' => $result['id'] ?? null,
                'payment_currency' => $payCurrency,
                'payment_amount' => $payAmount,
            ]);
            Log::info('[PackageSubscription] PayIn KPay initié', ['subscription_id' => $subscription->id, 'charged' => $payAmount, 'currency' => $payCurrency]);

            return null;
        }

        if ($paymentMode === 'stripe_direct') {
            $stripe = app(StripeService::class);
            if (!$stripe->isConfigured()) {
                throw new \Exception('Le paiement par carte est momentanément indisponible.');
            }

            $stripeCurrency = PaymentMethodService::currencyFor('stripe') ?? 'USD';
            $stripeAmount = strtoupper($stripeCurrency) === 'XAF'
                ? (float) round($total)
                : ExchangeRateService::convertAmount('XAF', $stripeCurrency, $total);
            if ($stripeAmount === null) {
                throw new \Exception("Conversion XAF → {$stripeCurrency} indisponible pour le paiement carte.");
            }

            // Carte NATIVE : PaymentIntent → client_secret confirmé par la Payment Sheet.
            $intent = $stripe->createPaymentIntent(
                (float) $stripeAmount,
                $stripeCurrency,
                ['asso_kind' => 'package_subscription', 'subscription_id' => (string) $subscription->id]
            );

            if (empty($intent['id']) || empty($intent['client_secret'])) {
                throw new \Exception("Échec de l'initiation du paiement carte (Stripe).");
            }

            $subscription->update([
                'payment_reference' => $intent['id'],
                'payment_currency' => strtoupper($stripeCurrency),
                'payment_amount' => round((float) $stripeAmount, 2),
            ]);
            Log::info('[PackageSubscription] PaymentIntent Stripe initié', ['subscription_id' => $subscription->id, 'payment_intent' => $intent['id']]);

            return [
                'client_secret' => $intent['client_secret'],
                'payment_intent_id' => $intent['id'],
                'publishable_key' => $intent['publishable_key'] ?? null,
            ];
        }

        return null;
    }

    /**
     * Re-vérifie le paiement chez le PSP et confirme/échoue la souscription (idempotent).
     * Appelé par le polling GET /v1/packages/subscription/{id}/payment-status.
     */
    public function checkAndConfirm(PackageSubscription $subscription): void
    {
        if ($subscription->status !== 'pending' || !$subscription->payment_reference) {
            return;
        }

        switch ($subscription->payment_method) {
            case 'kpay_direct':
                $result = app(KPayService::class)->checkPaymentStatus($subscription->payment_reference);
                $status = strtoupper($result['status'] ?? 'UNKNOWN');
                if (in_array($status, ['SUCCESS', 'SUCCESSFUL', 'COMPLETED'])) {
                    $this->confirm($subscription);
                } elseif (in_array($status, ['FAILED', 'FAILURE', 'ERROR', 'REJECTED', 'CANCELLED', 'CANCELED'])) {
                    $this->fail($subscription);
                }
                break;

            case 'stripe_direct':
                // Carte native : on relit le PaymentIntent (succeeded → confirmé,
                // canceled → échec ; sinon on reste en attente pour le prochain poll).
                try {
                    $intent = app(StripeService::class)->retrievePaymentIntent($subscription->payment_reference);
                } catch (\Throwable $e) {
                    Log::warning('[PackageSubscription] Stripe retrieve PaymentIntent: ' . $e->getMessage());
                    return;
                }
                $status = strtolower($intent['status'] ?? '');
                if ($status === 'succeeded') {
                    $this->confirm($subscription);
                } elseif ($status === 'canceled') {
                    $this->fail($subscription);
                }
                break;
        }
    }

    /**
     * Confirme le paiement : crée/cumule le VendorPackage et active l'abonnement (idempotent).
     */
    public function confirm(PackageSubscription $subscription): void
    {
        $activated = false;
        $refundNeeded = false;

        DB::transaction(function () use ($subscription, &$activated, &$refundNeeded) {
            $sub = PackageSubscription::whereKey($subscription->id)->lockForUpdate()->first();
            if (!$sub || $sub->status === 'paid') {
                return; // déjà traité
            }

            $package = Package::findOrFail($sub->package_id);
            $user = User::findOrFail($sub->user_id);

            // Boost : le produit ciblé a été mémorisé à la création de l'intent,
            // le paiement ayant pu être confirmé bien après (polling, webhook, cron).
            $product = null;
            if ($package->type === 'boost') {
                $productId = $sub->metadata['boost_product_id'] ?? null;
                $product = $productId ? Product::find($productId) : null;

                // Le produit a disparu entre l'intent et l'encaissement (vendeur
                // qui le supprime pendant un paiement Mobile Money, par exemple).
                // L'argent est déjà chez le PSP : on ne peut pas ouvrir la
                // campagne, mais il est hors de question de garder la somme.
                if (!$product) {
                    $refundNeeded = true;
                    return;
                }
            }

            $result = $this->applyPackage($user, $package, $sub->payment_reference, $product, $sub);

            $sub->update([
                'status' => 'paid',
                'vendor_package_id' => $result['vendor_package']?->id,
                'paid_at' => now(),
                'metadata' => array_merge($sub->metadata ?? [], [
                    'package_type' => $package->type,
                    'certification_expires_at' => $result['certification_expires_at'],
                    'product_boost_id' => $result['product_boost_id'],
                ]),
            ]);

            // P6 : commission du commercial dont le code a été saisi (dans la même transaction).
            app(SalesCommissionService::class)->recordForSubscription($sub);

            // Trace dans l'historique du client (solde NON modifié : encaissé chez le PSP).
            $balance = $user->kpayBalanceFor('XAF');
            WalletTransaction::create([
                'user_id' => $sub->user_id,
                'type' => 'debit',
                'amount' => (float) $sub->amount_xaf,
                'balance_before' => $balance,
                'balance_after' => $balance,
                'description' => $this->transactionLabel($package),
                'reference_type' => 'package_subscription',
                'reference_id' => $sub->id,
                'metadata' => [
                    'payment_method' => $sub->payment_method,
                    'payment_reference' => $sub->payment_reference,
                    'subscription_id' => $sub->id,
                    'paid_outside_wallet' => true,
                ],
                'status' => 'completed',
                'provider' => $this->providerFor($sub->payment_method),
            ]);

            $activated = true;

            Log::info('[PackageSubscription] Abonnement confirmé (payé)', [
                'subscription_id' => $sub->id,
                'package_type' => $package->type,
                'vendor_package_id' => $result['vendor_package']?->id,
            ]);
        });

        if ($refundNeeded) {
            $this->refundUndeliverable($subscription->fresh());
            return;
        }

        if ($activated) {
            $this->notifyActivated($subscription->fresh());
        }
    }

    /**
     * Rembourse sur le Wallet une souscription encaissée que l'on ne peut pas
     * honorer (produit à sponsoriser disparu entre l'intent et la confirmation).
     *
     * Le crédit passe par le Wallet plutôt que par un remboursement PSP : il est
     * immédiat, traçable, et le vendeur peut s'en resservir tout de suite. La
     * souscription est close en `failed` avec le motif, pour que la
     * réconciliation ne la reprenne pas.
     */
    protected function refundUndeliverable(PackageSubscription $subscription): void
    {
        DB::transaction(function () use ($subscription) {
            $sub = PackageSubscription::whereKey($subscription->id)->lockForUpdate()->first();
            if (!$sub || ($sub->metadata['refunded_at'] ?? null)) {
                return; // déjà remboursé
            }

            $user = User::find($sub->user_id);
            if (!$user) {
                return;
            }

            app(WalletService::class)->credit(
                user: $user,
                amount: (float) $sub->amount_xaf,
                description: 'Remboursement sponsoring — article introuvable',
                metadata: [
                    'reference_type' => 'package_subscription',
                    'subscription_id' => $sub->id,
                    'reason' => 'boost_product_missing',
                    'payment_reference' => $sub->payment_reference,
                ],
                provider: 'kpay'
            );

            $sub->update([
                'status' => 'failed',
                'metadata' => array_merge($sub->metadata ?? [], [
                    'failure_reason' => 'boost_product_missing',
                    'refunded_at' => now()->toIso8601String(),
                ]),
            ]);

            Log::warning('[PackageSubscription] Boost non honorable, remboursé au Wallet', [
                'subscription_id' => $sub->id,
                'amount_xaf' => (float) $sub->amount_xaf,
            ]);
        });

        try {
            $user = User::find($subscription->user_id);
            if ($user) {
                $this->fcmService->sendToUser(
                    $user,
                    'Sponsoring non activé',
                    "L'article à sponsoriser n'existe plus. Le montant a été recrédité sur votre portefeuille.",
                    ['type' => 'boost_refunded', 'subscription_id' => (string) $subscription->id]
                );
            }
        } catch (\Throwable $e) {
            Log::warning('[PackageSubscription] Notification de remboursement échouée: ' . $e->getMessage());
        }
    }

    /**
     * Marque l'abonnement échoué (idempotent). Aucun espace n'a été crédité.
     */
    public function fail(PackageSubscription $subscription, string $reason = 'payment_failed'): void
    {
        DB::transaction(function () use ($subscription, $reason) {
            $sub = PackageSubscription::whereKey($subscription->id)->lockForUpdate()->first();
            if (!$sub || $sub->status !== 'pending') {
                return;
            }
            $sub->update([
                'status' => 'failed',
                'metadata' => array_merge($sub->metadata ?? [], ['failure_reason' => $reason]),
            ]);
            Log::info('[PackageSubscription] Abonnement échoué', ['subscription_id' => $sub->id, 'reason' => $reason]);
        });
    }

    /**
     * Vérifie qu'un package peut être souscrit par cet utilisateur AVANT tout paiement.
     * Lance une exception au message affichable sinon.
     */
    public function assertSubscribable(User $user, Package $package, ?Product $product = null): void
    {
        if (!in_array($package->type, ['storage', 'certification', 'boost'], true)) {
            throw new \Exception('Type de forfait non supporté.');
        }
        if (!$package->is_active) {
            throw new \Exception("Ce forfait n'est plus disponible.");
        }
        if ($package->type === 'storage' && (int) $package->storage_size_mb <= 0) {
            throw new \Exception("Ce forfait de stockage est mal configuré. Contactez le support.");
        }
        if ($package->type === 'certification' && !$user->shops()->exists()) {
            throw new \Exception('Créez votre boutique avant de souscrire à une certification.');
        }
        if ($package->type === 'boost') {
            if (!$product) {
                throw new \Exception('Choisissez le produit à sponsoriser.');
            }
            if ((int) $package->reach_users <= 0) {
                throw new \Exception('Ce forfait de sponsoring est mal configuré. Contactez le support.');
            }
            app(ProductBoostService::class)->assertBoostable($user, $product);
        }
    }

    /**
     * Souscription payée depuis le SOLDE du Wallet ASSO : débit + activation dans une
     * seule transaction (tout ou rien). Renvoie la PackageSubscription 'paid'.
     */
    public function payWithWallet(
        User $user,
        Package $package,
        ?SalesAgent $salesAgent = null,
        ?Product $product = null
    ): PackageSubscription {
        $this->assertSubscribable($user, $package, $product);
        $price = (float) $package->price;

        $subscription = DB::transaction(function () use ($user, $package, $price, $salesAgent, $product) {
            $walletService = app(WalletService::class);

            // Débit sous verrou de ligne (lance une exception si solde insuffisant).
            $walletTx = $walletService->debit(
                user: $user,
                amount: $price,
                description: $this->transactionLabel($package),
                referenceType: 'package_subscription',
                referenceId: null,
                metadata: [
                    'package_id' => $package->id,
                    'package_name' => $package->name,
                    'package_type' => $package->type,
                    'boost_product_id' => $product?->id,
                ],
                provider: 'kpay'
            );

            $reference = 'PKG-' . strtoupper(Str::random(10));
            $result = $this->applyPackage($user, $package, $reference, $product);

            $sub = PackageSubscription::create([
                'user_id' => $user->id,
                'package_id' => $package->id,
                'sales_agent_id' => $salesAgent?->id,
                'sales_code' => $salesAgent?->code,
                'payment_method' => 'wallet',
                'status' => 'paid',
                'payment_reference' => $reference,
                'payment_currency' => 'XAF',
                'payment_amount' => $price,
                'amount_xaf' => $price,
                'vendor_package_id' => $result['vendor_package']?->id,
                'paid_at' => now(),
                'metadata' => [
                    'package_name' => $package->name,
                    'package_type' => $package->type,
                    'wallet_transaction_id' => $walletTx->id,
                    'certification_expires_at' => $result['certification_expires_at'],
                    'boost_product_id' => $product?->id,
                    'product_boost_id' => $result['product_boost_id'],
                ],
            ]);

            $walletTx->update(['reference_id' => $sub->id]);

            // La campagne est créée avant la souscription (elle en fait partie) :
            // on referme le lien une fois l'identifiant connu.
            if ($result['product_boost_id']) {
                ProductBoost::whereKey($result['product_boost_id'])
                    ->update(['package_subscription_id' => $sub->id]);
            }

            // P6 : commission du commercial dont le code a été saisi.
            app(SalesCommissionService::class)->recordForSubscription($sub);

            return $sub;
        });

        $this->notifyActivated($subscription);

        return $subscription;
    }

    /**
     * Active le package payé :
     *  - storage       : cumule sur le forfait de stockage actif (espace + durée) ou en crée un ;
     *  - certification : certifie la boutique (prolonge une certification en cours) ;
     *  - boost         : ouvre une campagne Asso Ads sur le produit choisi.
     *
     * Ni la certification ni le boost ne touchent au forfait de stockage : chaque
     * type a sa branche explicite, et tout type inconnu est refusé plutôt que de
     * retomber sur le stockage (ancien bug : la certification y était cumulée).
     *
     * @return array{vendor_package: ?VendorPackage, certification_expires_at: ?string, product_boost_id: ?int}
     */
    public function applyPackage(
        User $user,
        Package $package,
        ?string $paymentReference = null,
        ?Product $product = null,
        ?PackageSubscription $subscription = null
    ): array {
        if ($package->type === 'boost') {
            if (!$product) {
                throw new \Exception('Produit à sponsoriser introuvable.');
            }

            $boost = app(ProductBoostService::class)->startCampaign($user, $product, $package, $subscription);

            return [
                'vendor_package' => null,
                'certification_expires_at' => null,
                'product_boost_id' => $boost->id,
            ];
        }

        if ($package->type === 'certification') {
            $shop = $user->primaryShop;
            if (!$shop) {
                throw new \Exception('Aucune boutique à certifier.');
            }

            // Renouvellement : on prolonge à partir de l'échéance en cours si encore valide.
            $currentEnd = $shop->is_certified && $shop->certification_expires_at && $shop->certification_expires_at->isFuture()
                ? $shop->certification_expires_at->copy()
                : now();
            $expiresAt = $currentEnd->addDays($package->duration_days);

            $shop->update([
                'is_certified' => true,
                'certified_at' => $shop->is_certified ? $shop->certified_at : now(),
                'certification_expires_at' => $expiresAt,
                'certified_by' => $user->id,
            ]);

            return [
                'vendor_package' => null,
                'certification_expires_at' => $expiresAt->toIso8601String(),
                'product_boost_id' => null,
            ];
        }

        if ($package->type !== 'storage') {
            throw new \Exception('Type de forfait non supporté.');
        }

        $existingPackage = VendorPackage::where('user_id', $user->id)
            ->active()
            ->latest('purchased_at')
            ->lockForUpdate()
            ->first();

        if ($existingPackage) {
            $existingPackage->update([
                'storage_total_mb' => $existingPackage->storage_total_mb + $package->storage_size_mb,
                'storage_remaining_mb' => $existingPackage->storage_remaining_mb + $package->storage_size_mb,
                'expires_at' => $existingPackage->expires_at->copy()->addDays($package->duration_days),
                'package_id' => null,
                'custom_name' => 'Espace Cumulé',
            ]);

            return [
                'vendor_package' => $existingPackage->fresh(),
                'certification_expires_at' => null,
                'product_boost_id' => null,
            ];
        }

        $vendorPackage = VendorPackage::create([
            'user_id' => $user->id,
            'package_id' => $package->id,
            'storage_total_mb' => $package->storage_size_mb,
            'storage_used_mb' => 0,
            'storage_remaining_mb' => $package->storage_size_mb,
            'purchased_at' => now(),
            'expires_at' => now()->addDays($package->duration_days),
            'status' => 'active',
            'payment_reference' => $paymentReference ?? ('PKG-' . strtoupper(Str::random(10))),
        ]);

        return [
            'vendor_package' => $vendorPackage,
            'certification_expires_at' => null,
            'product_boost_id' => null,
        ];
    }

    /** Notification push d'activation (hors transaction, jamais bloquante). */
    public function notifyActivated(PackageSubscription $subscription): void
    {
        try {
            $package = Package::find($subscription->package_id);
            $user = User::find($subscription->user_id);
            if (!$package || !$user) {
                return;
            }

            if ($package->type === 'certification') {
                $until = $subscription->metadata['certification_expires_at'] ?? null;
                $this->fcmService->sendToUser(
                    $user,
                    'Boutique certifiée ✅',
                    "Votre {$package->name} est active" . ($until ? ' jusqu\'au ' . \Carbon\Carbon::parse($until)->format('d/m/Y') : '') . '.',
                    ['type' => 'certification_activated', 'subscription_id' => (string) $subscription->id]
                );
                return;
            }

            if ($package->type === 'boost') {
                $boostId = $subscription->metadata['product_boost_id'] ?? null;
                $boost = $boostId ? ProductBoost::with('product')->find($boostId) : null;
                $name = $boost?->product?->name;
                $reach = number_format((int) ($boost?->impressions_quota ?? $package->reach_users), 0, ',', ' ');

                $this->fcmService->sendToUser(
                    $user,
                    'Sponsoring activé 🚀',
                    ($name ? "« {$name} » est sponsorisé" : 'Votre produit est sponsorisé')
                        . " : jusqu'à {$reach} personnes vont le voir.",
                    [
                        'type' => 'boost_activated',
                        'subscription_id' => (string) $subscription->id,
                        'product_boost_id' => (string) ($boostId ?? ''),
                        'product_id' => (string) ($boost?->product_id ?? ''),
                    ]
                );

                // La campagne est payée et ouverte : on l'annonce à tous les
                // utilisateurs. notifyActivated n'est appelé qu'une fois par
                // souscription (confirm() est idempotent), donc un seul envoi.
                if ($boost) {
                    app(ProductBroadcastService::class)->sponsoredProduct($boost);
                }
                return;
            }

            $vendorPackage = $subscription->vendor_package_id ? VendorPackage::find($subscription->vendor_package_id) : null;
            if ($vendorPackage) {
                $this->fcmService->sendPackagePurchaseNotification($user, [
                    'name' => $vendorPackage->custom_name ?? $package->name,
                    'storage_total' => $vendorPackage->storage_total_mb . ' MB',
                    'expires_at' => $vendorPackage->expires_at->format('d/m/Y'),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('[PackageSubscription] Notification d\'activation échouée: ' . $e->getMessage());
        }
    }

    /** Libellé lisible dans l'historique du portefeuille. */
    private function transactionLabel(Package $package): string
    {
        $prefix = match ($package->type) {
            'certification' => 'Certification',
            'boost' => 'Sponsoring',
            default => 'Forfait',
        };

        return "{$prefix} — {$package->name}";
    }

    private function providerFor(string $paymentMethod): string
    {
        return match ($paymentMethod) {
            'paypal_direct' => 'paypal',
            'stripe_direct' => 'stripe',
            'wallet' => 'kpay',
            default => 'kpay',
        };
    }
}
