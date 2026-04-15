<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\SettingController;
// use App\Http\Controllers\Api\ConfessionController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\OtpController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\BannerController;
use App\Http\Controllers\Api\VendorOrderController;
use App\Http\Controllers\Api\DeliveryController;
use App\Http\Controllers\Api\WalletController;
use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\PackageController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\VendorProductController;
use App\Http\Controllers\Api\DeviceTokenController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\DelivererSyncController;
use App\Http\Controllers\Api\ShopController;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

// ============================================
// PUBLIC ROUTES (no auth required)
// ============================================

// Auth
Route::prefix('v1/auth')->group(function () {
    Route::post('/send-otp', [AuthController::class, 'sendOtp']);
    Route::post('/verify-otp', [AuthController::class, 'verifyOtp']);
    Route::post('/login', [AuthController::class, 'login']);
});

// Deliverer Sync - verify-sync-code is public, sync-profile requires auth
Route::prefix('v1/deliverer')->group(function () {
    Route::post('/verify-sync-code', [DelivererSyncController::class, 'verifySyncCode']); // Public: just verify code validity
});

// OTP for registration (Nexaah implementation)
Route::prefix('v1/register')->group(function () {
    Route::post('/send-otp', [OtpController::class, 'sendOtp']);
    Route::post('/verify-otp', [OtpController::class, 'verifyOtp']);
});

// Settings
Route::prefix('settings')->group(function () {
    Route::get('/', [SettingController::class, 'index'])->name('api.settings.index');
    Route::get('/group/{group}', [SettingController::class, 'getByGroup'])->name('api.settings.group');
    Route::get('/{key}', [SettingController::class, 'show'])->name('api.settings.show');
});

// Public products & categories
Route::prefix('v1')->group(function () {
    // Specific routes BEFORE parametrized routes
    Route::get('/products/nearby', [ProductController::class, 'nearby']);
    Route::get('/products/recent', [ProductController::class, 'recent']);

    // General routes
    Route::get('/products', [ProductController::class, 'index']);
    Route::get('/products/{id}', [ProductController::class, 'show']);
    Route::get('/categories', [CategoryController::class, 'index']);
    Route::get('/banners', [BannerController::class, 'index']);

    // Public shop routes
    Route::get('/shops/{shopId}', [ShopController::class, 'showPublic']);

    // Delivery zone availability check (public)
    Route::post('/delivery/check-availability', [DeliveryController::class, 'checkDeliveryAvailability']);

    // Get all delivery partners with their positions (public - for vendor map)
    Route::get('/delivery/partners', [DeliveryController::class, 'getDeliveryPartners']);
});

// Payment webhooks (no auth)
Route::post('/v1/payments/webhook/freemopay', [PaymentController::class, 'webhookFreemopay']);

// ============================================
// PROTECTED ROUTES (auth:sanctum)
// ============================================

// Broadcasting auth endpoint for WebSocket private channels
Broadcast::routes(['middleware' => ['auth:sanctum']]);

Route::middleware('auth:sanctum')->group(function () {

    // User profile
    Route::prefix('v1/auth')->group(function () {
        Route::get('/profile', [AuthController::class, 'profile']);
        Route::put('/profile', [AuthController::class, 'updateProfile']);
        Route::get('/preferences', [AuthController::class, 'getPreferences']);
        Route::put('/preferences', [AuthController::class, 'updatePreferences']);
        Route::post('/logout', [AuthController::class, 'logout']);
    });

    Route::prefix('v1')->group(function () {

        // Deliverer Sync (requires authentication)
        Route::post('/deliverer/sync-profile', [DelivererSyncController::class, 'syncProfile']);
        Route::post('/deliverer/unsync-profile', [DelivererSyncController::class, 'unsyncProfile']);

        // Favorites
        Route::get('/favorites', [ProductController::class, 'favorites']);
        Route::post('/products/{id}/favorite', [ProductController::class, 'toggleFavorite']);

        // Orders
        Route::get('/orders', [OrderController::class, 'index']);
        Route::post('/orders', [OrderController::class, 'store']);
        Route::get('/orders/{id}', [OrderController::class, 'show']);
        Route::post('/orders/{id}/cancel', [OrderController::class, 'cancel']);
        Route::post('/orders/{id}/rate', [OrderController::class, 'rate']);

        // Payments
        Route::post('/payments/initiate', [PaymentController::class, 'initiate']);
        Route::get('/payments/status/{reference}', [PaymentController::class, 'status']);

        // Packages
        Route::prefix('packages')->group(function () {
            Route::get('/', [PackageController::class, 'index']);
            Route::get('/certification', [PackageController::class, 'certificationPackages']);
            Route::post('/subscribe', [PackageController::class, 'subscribe']);
        });

        // Invoices
        Route::prefix('invoices')->group(function () {
            Route::get('/package/{token}', [InvoiceController::class, 'showPackageInvoice']);
            Route::get('/download/{vendorPackageId}', [InvoiceController::class, 'download'])->name('api.invoices.download');
            Route::get('/pdf/{vendorPackageId}', [InvoiceController::class, 'downloadPdf'])->name('api.invoices.pdf');
        });

        // Vendor process
        Route::post('/vendor/apply', [ProfileController::class, 'applyVendor']);
        Route::get('/vendor/dashboard', [ProfileController::class, 'vendorDashboard']);
        Route::get('/vendor/package/current', [PackageController::class, 'currentPackage']);

        // Vendor shop management
        Route::get('/vendor/shop', [ShopController::class, 'show']);
        Route::put('/vendor/shop', [ShopController::class, 'update']);
        Route::get('/vendor/shops', [ShopController::class, 'index']);
        Route::get('/vendor/shop/location-requests', [ShopController::class, 'getLocationRequests']);

        // Delivery process
        Route::post('/delivery/apply', [ProfileController::class, 'applyDelivery']);
        Route::get('/delivery/dashboard', [ProfileController::class, 'deliveryDashboard']);

        // Vendor order management
        Route::prefix('vendor/orders')->group(function () {
            Route::get('/', [VendorOrderController::class, 'index']);
            Route::get('/check-active', [VendorOrderController::class, 'checkActiveOrders']);
            Route::post('/{id}/validate', [VendorOrderController::class, 'validate']);
            Route::post('/{id}/reject', [VendorOrderController::class, 'reject']);
            Route::post('/{id}/assign-delivery', [VendorOrderController::class, 'assignDelivery']);
            Route::get('/delivery-persons', [VendorOrderController::class, 'availableDeliveryPersons']);
        });

        // Delivery management
        Route::prefix('delivery')->group(function () {
            Route::get('/pending', [DeliveryController::class, 'pendingRequests']);
            Route::get('/active', [DeliveryController::class, 'activeDeliveries']);
            Route::post('/{id}/accept', [DeliveryController::class, 'accept']);
            Route::post('/{id}/complete', [DeliveryController::class, 'complete']);
        });

        // Wallet - Système à deux portefeuilles (FreeMoPay + PayPal)
        Route::prefix('wallet')->group(function () {
            // Stats & historique
            Route::get('/', [WalletController::class, 'index']); // Solde et stats
            Route::get('/transactions', [WalletController::class, 'transactions']); // Historique transactions

            // Recharge wallet (dépôt)
            Route::post('/recharge', [WalletController::class, 'recharge']); // FreeMoPay ou PayPal

            // PayPal Native Integration
            Route::post('/paypal/create-native-order', [WalletController::class, 'createNativePayPalOrder']);
            Route::post('/paypal/capture-native-order', [WalletController::class, 'captureNativePayPalOrder']);
            Route::get('/payment-status/{paymentId}', [WalletController::class, 'checkPaymentStatus']);

            // Vérifier si peut payer
            Route::post('/can-pay', [WalletController::class, 'canPay']);

            // Payer avec wallet
            Route::post('/pay', [WalletController::class, 'pay']);

            // Retrait wallet
            Route::get('/withdrawal-balances', [WalletController::class, 'getWithdrawalBalances']);
            Route::post('/withdraw/freemopay', [WalletController::class, 'initiateFreeMoPayWithdrawal']);
            Route::post('/withdraw/paypal', [WalletController::class, 'initiatePayPalWithdrawal']);
            Route::get('/withdrawals', [WalletController::class, 'getWithdrawalHistory']);
            Route::get('/withdrawal-status/{withdrawalId}', [WalletController::class, 'checkWithdrawalStatus']);
        });

        // Product creation (for vendors)
        Route::post('/products', [ProductController::class, 'store']);

        // Vendor product management
        Route::prefix('vendor/products')->group(function () {
            Route::get('/', [VendorProductController::class, 'index']);
            Route::put('/{id}', [VendorProductController::class, 'update']);
            Route::post('/{id}', [VendorProductController::class, 'update']); // Support POST avec _method=PUT pour multipart
            Route::delete('/{id}', [VendorProductController::class, 'destroy']);

            // Stock management
            Route::post('/{id}/stock', [VendorProductController::class, 'updateStock']);
        });

        // Inventory management
        Route::prefix('vendor/inventory')->group(function () {
            Route::get('/', [VendorProductController::class, 'getInventory']);
            Route::post('/entry', [VendorProductController::class, 'addInventoryEntry']);
        });

        // Conversations & Messages
        Route::prefix('conversations')->group(function () {
            Route::get('/', [ConversationController::class, 'index']);
            Route::post('/start', [ConversationController::class, 'startOrGet']);
            Route::get('/{id}/messages', [ConversationController::class, 'messages']);
            Route::post('/{id}/messages', [ConversationController::class, 'sendMessage']);
            Route::post('/{id}/typing', [ConversationController::class, 'typing']);
        });

        // User online status
        Route::post('/user/online-status', [ConversationController::class, 'updateOnlineStatus']);

        // ============================================
        // DEVICE TOKENS & NOTIFICATIONS (FCM)
        // ============================================

        // Device tokens management
        Route::prefix('device-tokens')->group(function () {
            Route::get('/', [DeviceTokenController::class, 'index']);
            Route::post('/', [DeviceTokenController::class, 'store']);
            Route::delete('/{id}', [DeviceTokenController::class, 'destroy']);
            Route::delete('/by-token/delete', [DeviceTokenController::class, 'deleteByToken']);
            Route::post('/{id}/deactivate', [DeviceTokenController::class, 'deactivate']);
            Route::post('/{id}/activate', [DeviceTokenController::class, 'activate']);
        });

        // Notifications
        Route::prefix('notifications')->group(function () {
            // Get user notifications (history)
            Route::get('/', [NotificationController::class, 'index']);
            Route::get('/unread', [NotificationController::class, 'unread']);
            Route::get('/unread-count', [NotificationController::class, 'unreadCount']);

            // Mark notifications as read
            Route::post('/{id}/mark-as-read', [NotificationController::class, 'markAsRead']);
            Route::post('/mark-all-as-read', [NotificationController::class, 'markAllAsRead']);

            // Delete notifications
            Route::delete('/{id}', [NotificationController::class, 'destroy']);
            Route::delete('/', [NotificationController::class, 'destroyAll']);

            // Send test notification to yourself
            Route::post('/test', [NotificationController::class, 'sendTestNotification']);

            // Admin only routes (will be checked in controller or middleware)
            Route::post('/send-to-user', [NotificationController::class, 'sendToUser']);
            Route::post('/send-to-users', [NotificationController::class, 'sendToUsers']);
            Route::post('/send-to-all', [NotificationController::class, 'sendToAll']);
            Route::post('/send-to-topic', [NotificationController::class, 'sendToTopic']);
        });
    });
});

// Confession Routes (Protected by auth:sanctum)
// TODO: Uncomment when ConfessionController is created
/*
Route::middleware('auth:sanctum')->prefix('v1/confessions')->group(function () {
    Route::get('/', [ConfessionController::class, 'index']);
    Route::get('/favorites', [ConfessionController::class, 'favorites']);
    Route::post('/', [ConfessionController::class, 'store']);
    Route::get('/{id}', [ConfessionController::class, 'show']);
    Route::put('/{id}', [ConfessionController::class, 'update']);
    Route::delete('/{id}', [ConfessionController::class, 'destroy']);

    // Actions
    Route::post('/{id}/favorite', [ConfessionController::class, 'toggleFavorite']);
    Route::post('/{id}/reveal-identity', [ConfessionController::class, 'revealIdentity']);
    Route::post('/{id}/like', [ConfessionController::class, 'like']);
    Route::delete('/{id}/like', [ConfessionController::class, 'unlike']);
});
*/

// ============================================
// ADMIN ROUTES (auth:sanctum + admin role)
// ============================================

Route::middleware('auth:sanctum')->prefix('v1/admin')->group(function () {
    // Shop location change requests
    Route::prefix('shop-location-requests')->group(function () {
        Route::get('/', [App\Http\Controllers\Admin\ShopLocationRequestController::class, 'index']);
        Route::get('/{locationRequest}', [App\Http\Controllers\Admin\ShopLocationRequestController::class, 'show']);
        Route::post('/{locationRequest}/approve', [App\Http\Controllers\Admin\ShopLocationRequestController::class, 'approve']);
        Route::post('/{locationRequest}/reject', [App\Http\Controllers\Admin\ShopLocationRequestController::class, 'reject']);
    });
});

// Service Configuration Routes - DÉSACTIVÉ (utiliser /admin/settings/services à la place)
/*
Route::middleware('auth:sanctum')->prefix('v1/admin')->group(function () {
    Route::prefix('service-config')->group(function () {
        Route::get('/', [App\Http\Controllers\Admin\ServiceConfigurationController::class, 'index']);
        Route::put('/nexah', [App\Http\Controllers\Admin\ServiceConfigurationController::class, 'updateNexah']);
        Route::post('/test/{serviceName}', [App\Http\Controllers\Admin\ServiceConfigurationController::class, 'test']);
        Route::post('/test/nexah/sms', [App\Http\Controllers\Admin\ServiceConfigurationController::class, 'sendTestNexah']);
        Route::post('/test/nexah/otp', [App\Http\Controllers\Admin\ServiceConfigurationController::class, 'sendTestOtp']);
        Route::post('/{serviceName}/toggle', [App\Http\Controllers\Admin\ServiceConfigurationController::class, 'toggle']);
    });
});
*/
