<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\SettingController;
// use App\Http\Controllers\Api\ConfessionController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\OtpController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\CurrencyController;
use App\Http\Controllers\Api\AppController;
use App\Http\Controllers\Api\PostController;
use App\Http\Controllers\Api\DiaspoController;
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
use App\Http\Controllers\Api\SearchController;
use App\Http\Controllers\Api\AnalyzeProductController;
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
    // Phone-based authentication
    Route::post('/send-otp', [AuthController::class, 'sendOtp']);
    Route::post('/verify-otp', [AuthController::class, 'verifyOtp']);
    Route::post('/login', [AuthController::class, 'login']);
    // Email-based auth
    Route::post('/register-email', [AuthController::class, 'registerEmail']);
    Route::post('/login-email', [AuthController::class, 'loginEmail']);
    Route::post('/verify-email-otp', [AuthController::class, 'verifyEmailOtp']);
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

// App information (public)
Route::prefix('v1/app')->group(function () {
    Route::get('/about', [AppController::class, 'about']);
    Route::get('/version', [AppController::class, 'version']);
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

    // Currencies & countries (public - used by country selection & pricing)
    // Specific routes BEFORE the generic list route.
    Route::get('/currencies/all-with-countries', [CurrencyController::class, 'allWithCountries']);
    Route::get('/currencies/by-country', [CurrencyController::class, 'byCountry']);
    Route::get('/currencies/exchange-rate', [CurrencyController::class, 'exchangeRate']);
    Route::get('/currencies/convert', [CurrencyController::class, 'convert']);
    Route::get('/currencies', [CurrencyController::class, 'index']);

    // Pays d'origine des produits importés (Chine, Turquie, Dubaï…) - gérés en base
    Route::get('/import-countries', [\App\Http\Controllers\Api\ImportCountryController::class, 'index']);

    // App info (public)
    Route::get('/app/about', [AppController::class, 'about']);
    Route::get('/app/version', [AppController::class, 'version']);

    // AI Product Analysis (Gemini Vision) — collab upstream
    Route::post('/products/analyze', [AnalyzeProductController::class, 'analyze']);
    Route::get('/products/categories', [AnalyzeProductController::class, 'categories']);
    Route::get('/products/analyze/health', [AnalyzeProductController::class, 'health']);

    // Intelligent Search (Public) — collab upstream
    Route::get('/search', [SearchController::class, 'search']);
    Route::get('/search/suggestions', [SearchController::class, 'suggestions']);
    Route::get('/search/popular', [SearchController::class, 'popularSearches']);

    // Public shop routes
    Route::get('/shops/{shopId}', [ShopController::class, 'showPublic']);

    // Delivery zone availability check (public)
    Route::post('/delivery/check-availability', [DeliveryController::class, 'checkDeliveryAvailability']);

    // Get all delivery partners with their positions (public - for vendor map)
    Route::get('/delivery/partners', [DeliveryController::class, 'getDeliveryPartners']);
});

// Payment webhooks (no auth — protégé par vérification de signature HMAC + re-poll KPay)
Route::post('/v1/payments/webhook/kpay', [PaymentController::class, 'webhookKpay']);
// Alias court (certaines configs KPay utilisent cette forme)
Route::post('/webhooks/kpay', [PaymentController::class, 'webhookKpay']);

// ============================================
// CURRENCY & EXCHANGE RATES (Public)
// ============================================
Route::prefix('v1/currencies')->group(function () {
    Route::get('/', [\App\Http\Controllers\Api\V1\CurrencyController::class, 'index']); // Get all currencies
    Route::get('/all-with-countries', [\App\Http\Controllers\Api\V1\CurrencyController::class, 'getAllWithCountries']); // Get all currencies with countries for country selection
    Route::get('/by-country', [\App\Http\Controllers\Api\V1\CurrencyController::class, 'getByCountry']); // Get currency by country name
    Route::get('/exchange-rate', [\App\Http\Controllers\Api\V1\CurrencyController::class, 'getExchangeRate']); // Get specific exchange rate
});

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
        Route::post('/request-phone-change', [AuthController::class, 'requestPhoneChange']);
        Route::post('/confirm-phone-change', [AuthController::class, 'confirmPhoneChange']);
        Route::post('/delete-account', [AuthController::class, 'deleteAccount']);
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::post('/request-phone-change', [AuthController::class, 'requestPhoneChange']);
        Route::post('/confirm-phone-change', [AuthController::class, 'confirmPhoneChange']);
        Route::post('/delete-account', [AuthController::class, 'deleteAccount']);
    });

    Route::prefix('v1')->group(function () {

        // Deliverer Sync (requires authentication)
        Route::post('/deliverer/sync-profile', [DelivererSyncController::class, 'syncProfile']);
        Route::post('/deliverer/unsync-profile', [DelivererSyncController::class, 'unsyncProfile']);

        // Favorites
        Route::get('/favorites', [ProductController::class, 'favorites']);
        Route::post('/products/{id}/favorite', [ProductController::class, 'toggleFavorite']);

        // Community feed ("MyVoice") — FAKE data for testing
        Route::get('/posts/my-posts', [PostController::class, 'myPosts']);
        Route::get('/posts', [PostController::class, 'index']);
        Route::post('/posts', [PostController::class, 'store']);
        Route::get('/posts/{id}', [PostController::class, 'show']);
        Route::put('/posts/{id}', [PostController::class, 'update']);
        Route::delete('/posts/{id}', [PostController::class, 'destroy']);
        Route::post('/posts/{id}/react', [PostController::class, 'react']);
        Route::delete('/posts/{id}/react', [PostController::class, 'unreact']);
        Route::get('/posts/{postId}/comments', [PostController::class, 'comments']);
        Route::post('/posts/{postId}/comments', [PostController::class, 'storeComment']);
        Route::put('/posts/{postId}/comments/{commentId}', [PostController::class, 'updateComment']);
        Route::delete('/posts/{postId}/comments/{commentId}', [PostController::class, 'destroyComment']);
        Route::post('/posts/{postId}/comments/{commentId}/react', [PostController::class, 'reactComment']);
        Route::delete('/posts/{postId}/comments/{commentId}/react', [PostController::class, 'unreactComment']);

        // Diaspo — RÉSERVATIONS (flux de paiement KPay direct, DiaspoController).
        // Les OFFRES et la VÉRIFICATION sont servies par DiaspoOfferController (schéma
        // unifié upstream), plus bas dans le groupe prefix('v1/diaspo'). Chaque chemin
        // n'est défini qu'une seule fois (pas de collision de routes).
        Route::post('/diaspo/offers/{id}/book', [DiaspoController::class, 'bookOffer']);
        Route::post('/diaspo/confirm-by-code', [DiaspoController::class, 'confirmByCode']);
        Route::get('/diaspo/bookings', [DiaspoController::class, 'bookings']);
        Route::get('/diaspo/bookings/{id}/payment-status', [DiaspoController::class, 'bookingPaymentStatus']);
        Route::get('/diaspo/bookings/{id}', [DiaspoController::class, 'showBooking']);
        Route::post('/diaspo/bookings/{id}/cancel', [DiaspoController::class, 'cancelBooking']);
        Route::post('/diaspo/bookings/{id}/confirm-receipt', [DiaspoController::class, 'confirmReceipt']);
        Route::post('/diaspo/bookings/{id}/seller-confirm-code', [DiaspoController::class, 'sellerConfirmCode']);

        // Orders
        Route::get('/orders', [OrderController::class, 'index']);
        Route::post('/orders', [OrderController::class, 'store']);
        Route::get('/orders/{id}/payment-status', [OrderController::class, 'paymentStatus']);
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
            Route::get('/', [InvoiceController::class, 'index']);
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

        // Wallet - Système à deux portefeuilles (KPay + PayPal)
        Route::prefix('wallet')->group(function () {
            // Stats & historique
            Route::get('/', [WalletController::class, 'index']); // Solde et stats
            Route::get('/transactions', [WalletController::class, 'transactions']); // Historique transactions

            // Recharge wallet (dépôt)
            Route::post('/recharge', [WalletController::class, 'recharge']); // KPay ou PayPal

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
            Route::post('/withdraw/kpay', [WalletController::class, 'initiateKpayWithdrawal']);
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
            Route::get('/', [VendorProductController::class, 'getInventoryHistory']);
            Route::post('/entry', [VendorProductController::class, 'addInventoryEntry']);
        });

        // Conversations & Messages
        Route::prefix('conversations')->group(function () {
            Route::get('/', [ConversationController::class, 'index']);
            Route::post('/start', [ConversationController::class, 'startOrGet']);
            Route::get('/{id}/messages', [ConversationController::class, 'messages']);
            Route::post('/{id}/messages', [ConversationController::class, 'sendMessage']);
            Route::post('/{id}/typing', [ConversationController::class, 'typing']);
            Route::post('/{id}/hide', [ConversationController::class, 'hide']);
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

// ============================================
// MY VOICE / POSTS ROUTES (auth:sanctum)
// ============================================

use App\Http\Controllers\Api\PostCommentController;

Route::middleware('auth:sanctum')->prefix('v1/posts')->group(function () {
    // Posts CRUD
    Route::get('/', [PostController::class, 'index']);
    Route::get('/my-posts', [PostController::class, 'myPosts']);
    Route::post('/', [PostController::class, 'store']);
    Route::get('/{id}', [PostController::class, 'show']);
    Route::put('/{id}', [PostController::class, 'update']);
    Route::delete('/{id}', [PostController::class, 'destroy']);

    // Post Reactions (like/dislike)
    Route::post('/{id}/react', [PostController::class, 'react']);
    Route::delete('/{id}/react', [PostController::class, 'unreact']);

    // Comments
    Route::get('/{postId}/comments', [PostCommentController::class, 'index']);
    Route::post('/{postId}/comments', [PostCommentController::class, 'store']);
    Route::put('/{postId}/comments/{commentId}', [PostCommentController::class, 'update']);
    Route::delete('/{postId}/comments/{commentId}', [PostCommentController::class, 'destroy']);

    // Comment Reactions (like only)
    Route::post('/{postId}/comments/{commentId}/react', [PostCommentController::class, 'react']);
    Route::delete('/{postId}/comments/{commentId}/react', [PostCommentController::class, 'unreact']);
});

// ============================================
// DIASPO EXCHANGE ROUTES (auth:sanctum)
// ============================================

use App\Http\Controllers\Api\DiaspoOfferController;

Route::middleware('auth:sanctum')->prefix('v1/diaspo')->group(function () {
    // Verification
    Route::post('/upload-verification', [DiaspoOfferController::class, 'uploadVerificationDocument']);
    Route::get('/verification-status', [DiaspoOfferController::class, 'getVerificationStatus']);

    // Offers
    Route::get('/offers', [DiaspoOfferController::class, 'index']);
    Route::get('/offers/my-offers', [DiaspoOfferController::class, 'myOffers']);
    Route::post('/offers', [DiaspoOfferController::class, 'store']);
    Route::get('/offers/{id}', [DiaspoOfferController::class, 'show']);
    Route::put('/offers/{id}', [DiaspoOfferController::class, 'update']);
    Route::delete('/offers/{id}', [DiaspoOfferController::class, 'destroy']);

    // Réservations : servies par DiaspoController (flux de paiement KPay direct),
    // déclarées dans le groupe /v1 plus haut. L'ancien flux wallet de
    // DiaspoBookingController (basé sur la colonne morte freemopay_wallet_balance) est
    // retiré au profit du paiement KPay pour lequel le mobile est construit.
});

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

    // DIASPO verification management
    Route::prefix('diaspo')->group(function () {
        Route::get('/verifications', [App\Http\Controllers\Admin\DiaspoVerificationController::class, 'index']);
        Route::get('/verifications/{userId}', [App\Http\Controllers\Admin\DiaspoVerificationController::class, 'show']);
        Route::post('/verifications/{userId}/approve', [App\Http\Controllers\Admin\DiaspoVerificationController::class, 'approve']);
        Route::post('/verifications/{userId}/reject', [App\Http\Controllers\Admin\DiaspoVerificationController::class, 'reject']);
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
