<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Package;
use App\Models\VendorPackage;
use App\Services\WalletService;
use App\Services\InvoiceService;
use App\Services\InvoiceGenerator;
use App\Services\FcmService;
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

    public function __construct(
        WalletService $walletService,
        InvoiceService $invoiceService,
        InvoiceGenerator $invoiceGenerator,
        FcmService $fcmService
    ) {
        $this->walletService = $walletService;
        $this->invoiceService = $invoiceService;
        $this->invoiceGenerator = $invoiceGenerator;
        $this->fcmService = $fcmService;
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
     * Subscribe to a package
     */
    public function subscribe(Request $request)
    {
        Log::info("╔════════════════════════════════════════════════════════════════════╗");
        Log::info("║ [PackageController] 📦 PACKAGE SUBSCRIPTION REQUEST               ║");
        Log::info("╚════════════════════════════════════════════════════════════════════╝");

        $validated = $request->validate([
            'package_id' => 'required|exists:packages,id',
            'wallet_type' => 'required|in:kpay,paypal',
        ]);

        $user = $request->user();
        $package = Package::findOrFail($validated['package_id']);
        $walletType = $validated['wallet_type'];

        Log::info("[PackageController] 📝 Request details", [
            'user_id' => $user->id,
            'package_id' => $package->id,
            'package_name' => $package->name,
            'package_price' => $package->price,
            'wallet_type' => $walletType,
        ]);

        // Verify package type is storage or certification
        if (!in_array($package->type, ['storage', 'certification'])) {
            return response()->json([
                'success' => false,
                'message' => 'Type de package non supporté',
            ], 422);
        }

        // Verify package is active
        if (!$package->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'Ce package n\'est plus disponible',
            ], 422);
        }

        // Check if user already has an active package - we'll cumulate it
        $existingPackage = $user->activeVendorPackage;
        if ($existingPackage) {
            Log::info("[PackageController] ℹ️ User has an active package, will cumulate storage and extend expiration", [
                'user_id' => $user->id,
                'existing_package_id' => $existingPackage->id,
                'existing_storage_total' => $existingPackage->storage_total_mb,
                'new_storage_to_add' => $package->storage_size_mb,
                'existing_expires_at' => $existingPackage->expires_at,
            ]);
        }

        // Verify user has enough balance in the selected wallet
        $canPay = $this->walletService->canPayWithWallet($user, $package->price, $walletType);

        if (!$canPay['can_pay']) {
            Log::warning("[PackageController] ❌ Insufficient balance", [
                'user_id' => $user->id,
                'wallet_type' => $walletType,
                'current_balance' => $canPay['current_balance'],
                'required_amount' => $package->price,
                'missing_amount' => $canPay['missing_amount'],
            ]);

            return response()->json([
                'success' => false,
                'message' => $canPay['message'],
                'data' => [
                    'current_balance' => $canPay['current_balance'],
                    'required_amount' => $package->price,
                    'missing_amount' => $canPay['missing_amount'],
                    'wallet_type' => $walletType,
                ],
            ], 422);
        }

        try {
            DB::beginTransaction();

            Log::info("[PackageController] 💳 Debiting wallet...", [
                'wallet_type' => $walletType,
                'amount' => $package->price,
            ]);

            // Debit the wallet
            $walletTransaction = $this->walletService->debit(
                user: $user,
                amount: $package->price,
                description: "Achat de package de stockage: {$package->name}",
                referenceType: 'vendor_package',
                referenceId: null, // Will be updated after creating the vendor package
                metadata: [
                    'package_id' => $package->id,
                    'package_name' => $package->name,
                    'package_price' => (float) $package->price,
                    'storage_size_mb' => $package->storage_size_mb,
                    'duration_days' => $package->duration_days,
                ],
                provider: $walletType
            );

            Log::info("[PackageController] ✅ Wallet debited successfully", [
                'wallet_transaction_id' => $walletTransaction->id,
                'balance_before' => $walletTransaction->balance_before,
                'balance_after' => $walletTransaction->balance_after,
            ]);

            // If user has an existing package, cumulate storage and extend expiration
            if ($existingPackage) {
                // Calculate new totals
                $newStorageTotal = $existingPackage->storage_total_mb + $package->storage_size_mb;
                $newStorageRemaining = $existingPackage->storage_remaining_mb + $package->storage_size_mb;

                // Extend expiration date by adding new package duration
                $newExpiresAt = $existingPackage->expires_at->addDays($package->duration_days);

                $existingPackage->update([
                    'storage_total_mb' => $newStorageTotal,
                    'storage_remaining_mb' => $newStorageRemaining,
                    'expires_at' => $newExpiresAt,
                    'package_id' => null, // Set to null for cumulative packages
                    'custom_name' => 'Espace Cumulé', // Custom name for cumulative packages
                ]);

                $vendorPackage = $existingPackage;

                Log::info("[PackageController] ✅ Package cumulated successfully", [
                    'vendor_package_id' => $vendorPackage->id,
                    'new_storage_total' => $newStorageTotal,
                    'new_storage_remaining' => $newStorageRemaining,
                    'new_expires_at' => $newExpiresAt,
                ]);
            } else {
                // Create new vendor package
                $vendorPackage = VendorPackage::create([
                    'user_id' => $user->id,
                    'package_id' => $package->id,
                    'storage_total_mb' => $package->storage_size_mb,
                    'storage_used_mb' => 0,
                    'storage_remaining_mb' => $package->storage_size_mb,
                    'purchased_at' => now(),
                    'expires_at' => now()->addDays($package->duration_days),
                    'status' => 'active',
                    'payment_reference' => 'PKG-' . strtoupper(Str::random(10)),
                ]);

                Log::info("[PackageController] 📦 New vendor package created successfully", [
                    'vendor_package_id' => $vendorPackage->id,
                    'payment_reference' => $vendorPackage->payment_reference,
                ]);
            }

            // Update wallet transaction with vendor package reference
            $walletTransaction->update([
                'reference_id' => $vendorPackage->id,
            ]);

            // If this is a certification package, activate shop certification
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

                    Log::info("[PackageController] ✅ Shop certification activated", [
                        'shop_id' => $shop->id,
                        'certified_at' => now(),
                        'expires_at' => $expiresAt,
                    ]);
                } else {
                    Log::warning("[PackageController] ⚠️ User has no shop to certify", [
                        'user_id' => $user->id,
                    ]);
                }
            }

            DB::commit();

            // Reload package relationship if exists (for non-cumulative packages)
            if ($vendorPackage->package_id) {
                $vendorPackage->load('package');
            }

            // Generate invoice URL
            $invoiceUrl = $this->invoiceGenerator->getInvoiceDownloadUrl($vendorPackage);

            Log::info("╔════════════════════════════════════════════════════════════════════╗");
            Log::info("║ [PackageController] ✅ PACKAGE SUBSCRIPTION SUCCESSFUL            ║");
            Log::info("╚════════════════════════════════════════════════════════════════════╝");
            Log::info("[PackageController] 📄 Invoice URL: {$invoiceUrl}");

            // Send FCM push notification
            try {
                $packageName = $vendorPackage->custom_name ?? $package->name ?? 'Package';
                $this->fcmService->sendPackagePurchaseNotification($user, [
                    'name' => $packageName,
                    'storage_total' => $vendorPackage->storage_total_mb . ' MB',
                    'expires_at' => $vendorPackage->expires_at->format('d/m/Y'),
                ]);
                Log::info("[PackageController] 📱 Push notification sent");
            } catch (\Exception $e) {
                Log::error("[PackageController] ❌ Failed to send push notification", [
                    'error' => $e->getMessage(),
                ]);
                // Don't fail the request if notification fails
            }

            // Prepare package data based on whether it's cumulative or not
            $packageData = $vendorPackage->package_id && $vendorPackage->package
                ? [
                    'id' => $vendorPackage->package->id,
                    'name' => $vendorPackage->package->name,
                    'price' => (float) $vendorPackage->package->price,
                    'formatted_price' => $vendorPackage->package->formatted_price,
                ]
                : [
                    'id' => null,
                    'name' => $vendorPackage->custom_name ?? 'Espace Cumulé',
                    'price' => (float) $package->price, // Price of the package just purchased
                    'formatted_price' => $package->formatted_price,
                ];

            return response()->json([
                'success' => true,
                'message' => $existingPackage ? 'Espace ajouté avec succès à votre package' : 'Package souscrit avec succès',
                'invoice_url' => $invoiceUrl,
                'vendor_package' => [
                    'id' => $vendorPackage->id,
                    'storage_total_mb' => (float) $vendorPackage->storage_total_mb,
                    'storage_used_mb' => (float) $vendorPackage->storage_used_mb,
                    'storage_remaining_mb' => (float) $vendorPackage->storage_remaining_mb,
                    'purchased_at' => $vendorPackage->purchased_at->toIso8601String(),
                    'expires_at' => $vendorPackage->expires_at->toIso8601String(),
                    'status' => $vendorPackage->status,
                    'payment_reference' => $vendorPackage->payment_reference,
                    'wallet_type' => $walletType,
                    'is_cumulative' => $vendorPackage->package_id === null,
                    'package' => $packageData,
                ],
                'wallet_transaction' => [
                    'id' => $walletTransaction->id,
                    'amount' => (float) $walletTransaction->amount,
                    'balance_before' => (float) $walletTransaction->balance_before,
                    'balance_after' => (float) $walletTransaction->balance_after,
                    'provider' => $walletTransaction->provider,
                ],
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('[PackageController] ❌ Error during subscription:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la souscription au package',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Get current active package for the authenticated user
     */
    public function currentPackage(Request $request)
    {
        $user = $request->user();
        $vendorPackage = $user->activeVendorPackage;

        if (!$vendorPackage) {
            return response()->json([
                'success' => false,
                'message' => 'Aucun package actif',
                'has_package' => false,
            ], 404);
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
                'name' => $vendorPackage->custom_name ?? 'Espace Cumulé',
                'description' => 'Package personnalisé avec espace cumulé',
                'price' => null,
                'formatted_price' => 'Variable',
                'duration_days' => null,
                'formatted_duration' => 'Personnalisé',
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
