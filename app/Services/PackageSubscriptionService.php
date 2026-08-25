<?php

namespace App\Services;

use App\Models\Package;
use App\Models\PackageSubscription;
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
 *  - paypal_direct : Checkout PayPal → approval_url (WebView), capture au polling
 *  - stripe_direct : Checkout Session carte → url (WebView), confirmation au polling
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
     * @param string $paymentMode kpay_direct | paypal_direct | stripe_direct
     */
    public function createDirect(
        User $user,
        Package $package,
        string $paymentMode,
        ?string $kpayProvider = null,
        ?string $kpayPhone = null
    ): PackageSubscription {
        $amountXaf = (float) $package->price;

        $subscription = PackageSubscription::create([
            'user_id' => $user->id,
            'package_id' => $package->id,
            'payment_method' => $paymentMode,
            'status' => 'pending',
            'amount_xaf' => $amountXaf,
            'metadata' => [
                'package_name' => $package->name,
                'provider' => $kpayProvider,
            ],
        ]);

        // Peut lancer une exception → la souscription reste 'pending' sans référence,
        // le contrôleur renvoie l'erreur (rien n'est crédité au vendeur).
        $approvalUrl = $this->initiatePayment($subscription, $amountXaf, $paymentMode, $kpayProvider, $kpayPhone);

        if ($approvalUrl) {
            $subscription->update(['approval_url' => $approvalUrl]);
        }

        return $subscription->fresh();
    }

    /**
     * Initie le paiement direct chez le PSP et enregistre la référence sur la souscription.
     * Renvoie l'URL de checkout (PayPal / Stripe) ou null (KPay). Calqué sur
     * OrderService::initiateDirectPayment.
     */
    protected function initiatePayment(
        PackageSubscription $subscription,
        float $total,
        string $paymentMode,
        ?string $kpayProvider,
        ?string $kpayPhone
    ): ?string {
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

        if ($paymentMode === 'paypal_direct') {
            $pp = app(PayPalService::class)->createOrder([
                'amount' => (float) round($total),
                'currency' => 'XAF',
                'user_id' => $subscription->user_id,
                'description' => "Abonnement {$subscription->metadata['package_name']}",
                'return_url' => route('payment.success'),
                'cancel_url' => route('payment.cancel'),
            ]);

            if (empty($pp['success']) || empty($pp['approval_url']) || empty($pp['order_id'])) {
                throw new \Exception($pp['message'] ?? "Échec de l'initiation du paiement PayPal.");
            }

            $subscription->update([
                'payment_reference' => $pp['order_id'],
                'payment_currency' => 'USD',
                'payment_amount' => $pp['amount_usd'] ?? null,
            ]);
            Log::info('[PackageSubscription] Checkout PayPal initié', ['subscription_id' => $subscription->id, 'paypal_order_id' => $pp['order_id']]);

            return $pp['approval_url'];
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

            $session = $stripe->createCheckoutSession(
                (float) $stripeAmount,
                $stripeCurrency,
                ['asso_kind' => 'package_subscription', 'subscription_id' => (string) $subscription->id],
                route('payment.success'),
                route('payment.cancel'),
                "Abonnement {$subscription->metadata['package_name']}"
            );

            if (empty($session['url']) || empty($session['id'])) {
                throw new \Exception("Échec de l'initiation du paiement carte (Stripe).");
            }

            $subscription->update([
                'payment_reference' => $session['id'],
                'payment_currency' => strtoupper($stripeCurrency),
                'payment_amount' => round((float) $stripeAmount, 2),
            ]);
            Log::info('[PackageSubscription] Checkout Stripe initié', ['subscription_id' => $subscription->id, 'session_id' => $session['id']]);

            return $session['url'];
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

            case 'paypal_direct':
                $paypal = app(PayPalService::class);
                $details = $paypal->getOrderDetails($subscription->payment_reference);
                $status = strtoupper($details['data']['status'] ?? 'UNKNOWN');
                if ($status === 'APPROVED') {
                    $capture = $paypal->captureOrder($subscription->payment_reference);
                    if (!empty($capture['success']) && strtoupper($capture['status'] ?? '') === 'COMPLETED') {
                        $this->confirm($subscription);
                    }
                } elseif ($status === 'COMPLETED') {
                    $this->confirm($subscription);
                } elseif (in_array($status, ['VOIDED', 'EXPIRED', 'CANCELLED', 'CANCELED'])) {
                    $this->fail($subscription);
                }
                break;

            case 'stripe_direct':
                $session = app(StripeService::class)->retrieveCheckoutSession($subscription->payment_reference);
                $status = strtolower($session['status'] ?? '');
                $paymentStatus = strtolower($session['payment_status'] ?? '');
                if ($status === 'complete' && $paymentStatus === 'paid') {
                    $this->confirm($subscription);
                } elseif ($status === 'expired') {
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
        $applied = null;

        DB::transaction(function () use ($subscription, &$applied) {
            $sub = PackageSubscription::whereKey($subscription->id)->lockForUpdate()->first();
            if (!$sub || $sub->status === 'paid') {
                return; // déjà traité
            }

            $package = Package::findOrFail($sub->package_id);
            $user = User::findOrFail($sub->user_id);

            $vendorPackage = $this->applyPackage($user, $package, $sub->payment_reference);

            $sub->update([
                'status' => 'paid',
                'vendor_package_id' => $vendorPackage->id,
                'paid_at' => now(),
            ]);

            // Trace dans l'historique du client (solde NON modifié : encaissé chez le PSP).
            $provider = $this->providerFor($sub->payment_method);
            $balanceColumn = $provider === 'paypal' ? 'paypal_wallet_balance' : 'kpay_wallet_balance';
            $balance = (float) (User::where('id', $sub->user_id)->value($balanceColumn) ?? 0);
            WalletTransaction::create([
                'user_id' => $sub->user_id,
                'type' => 'debit',
                'amount' => (float) $sub->amount_xaf,
                'balance_before' => $balance,
                'balance_after' => $balance,
                'description' => "Abonnement - {$package->name}",
                'reference_type' => 'vendor_package',
                'reference_id' => $vendorPackage->id,
                'metadata' => [
                    'payment_method' => $sub->payment_method,
                    'payment_reference' => $sub->payment_reference,
                    'subscription_id' => $sub->id,
                ],
                'status' => 'completed',
                'provider' => $provider,
            ]);

            $applied = $vendorPackage;

            Log::info('[PackageSubscription] Abonnement confirmé (payé)', [
                'subscription_id' => $sub->id,
                'vendor_package_id' => $vendorPackage->id,
            ]);
        });

        if (!$applied) {
            return; // déjà traité / rien à notifier
        }

        // Notification push (hors transaction).
        try {
            $subscription->refresh();
            $package = Package::find($subscription->package_id);
            $this->fcmService->sendPackagePurchaseNotification(
                User::find($subscription->user_id),
                [
                    'name' => $applied->custom_name ?? ($package->name ?? 'Package'),
                    'storage_total' => $applied->storage_total_mb . ' MB',
                    'expires_at' => $applied->expires_at->format('d/m/Y'),
                ]
            );
        } catch (\Exception $e) {
            Log::warning('[PackageSubscription] FCM package confirmé échec: ' . $e->getMessage());
        }
    }

    /**
     * Marque l'abonnement échoué (idempotent). Aucun espace n'a été crédité.
     */
    public function fail(PackageSubscription $subscription): void
    {
        $sub = PackageSubscription::whereKey($subscription->id)->lockForUpdate()->first();
        if (!$sub || $sub->status !== 'pending') {
            return;
        }
        $sub->update(['status' => 'failed']);
        Log::info('[PackageSubscription] Abonnement échoué', ['subscription_id' => $sub->id]);
    }

    /**
     * Crée ou cumule le VendorPackage du vendeur + active la certification si besoin.
     *
     * Logique IDENTIQUE au flux wallet historique (PackageController::subscribe) :
     * si un package actif existe, on cumule le stockage et on prolonge l'expiration ;
     * sinon on crée un nouveau VendorPackage.
     */
    public function applyPackage(User $user, Package $package, ?string $paymentReference = null): VendorPackage
    {
        $existingPackage = $user->activeVendorPackage;

        if ($existingPackage) {
            $newStorageTotal = $existingPackage->storage_total_mb + $package->storage_size_mb;
            $newStorageRemaining = $existingPackage->storage_remaining_mb + $package->storage_size_mb;
            $newExpiresAt = $existingPackage->expires_at->addDays($package->duration_days);

            $existingPackage->update([
                'storage_total_mb' => $newStorageTotal,
                'storage_remaining_mb' => $newStorageRemaining,
                'expires_at' => $newExpiresAt,
                'package_id' => null,
                'custom_name' => 'Espace Cumulé',
            ]);

            $vendorPackage = $existingPackage;
        } else {
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
        }

        // Certification boutique si package de type certification.
        if ($package->type === 'certification') {
            $shop = $user->shops()->first();
            if ($shop) {
                $expiresAt = now()->addDays($package->duration_days);
                $shop->update([
                    'is_certified' => true,
                    'certified_at' => now(),
                    'certification_expires_at' => $expiresAt,
                    'certified_by' => $user->id,
                ]);
            }
        }

        return $vendorPackage;
    }

    private function providerFor(string $paymentMethod): string
    {
        return match ($paymentMethod) {
            'paypal_direct' => 'paypal',
            'stripe_direct' => 'stripe',
            default => 'kpay',
        };
    }
}
