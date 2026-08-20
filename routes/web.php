<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\ShopController;
use App\Http\Controllers\Admin\CategorySettingsController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\MaintenanceController;
use App\Http\Controllers\Admin\LegalPageController;
use App\Http\Controllers\Admin\BannerController;
use App\Http\Controllers\Admin\ImportCountryController;
use App\Http\Controllers\Admin\AnnouncementController;
use App\Http\Controllers\Admin\TransactionController;
use App\Http\Controllers\Admin\ExchangeController;
use App\Http\Controllers\Admin\MapController;
use App\Http\Controllers\Admin\PackageController;
use App\Http\Controllers\Admin\SupportController;
use App\Http\Controllers\Admin\AffiliateController;
use App\Http\Controllers\Admin\DelivererController;
use App\Http\Controllers\Admin\DelivererSyncManagementController;
use App\Http\Controllers\Admin\DocumentController;
use App\Http\Controllers\Admin\DatabaseController;
use App\Http\Controllers\Admin\VaultController;
use App\Http\Controllers\Admin\PreferenceController;
use App\Http\Controllers\Admin\FcmTokenController;
use App\Http\Controllers\Admin\OtpBypassController;
use App\Http\Controllers\Admin\DiaspoVerificationController;
use App\Http\Controllers\Admin\DiaspoOfferController;
use App\Http\Controllers\Admin\PostController;

// Redirect root to admin login
Route::get('/', function () {
    return redirect()->route('admin.login');
});

// Default login route (for Laravel authentication redirects)
Route::get('/login', function () {
    return redirect()->route('admin.login');
})->name('login');

// Admin routes
Route::prefix('admin')->name('admin.')->group(function () {
    // Login routes (no guest middleware to avoid redirect loops)
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->name('login.submit');

    // Authenticated routes
    Route::middleware('auth')->group(function () {
        Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
        Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

        // Users management
        Route::resource('users', UserController::class);

        // Preferences management
        Route::get('/preferences', [PreferenceController::class, 'index'])->name('preferences.index');

        // Deliverers (Livreurs partenaires)
        Route::resource('deliverers', DelivererController::class);

        // Deliverer Sync Management
        Route::prefix('deliverers/syncs')->name('deliverers.syncs.')->group(function () {
            Route::get('/code/{syncCode}', [DelivererSyncManagementController::class, 'showCodeSyncs'])->name('code');
            Route::get('/company/{company}', [DelivererSyncManagementController::class, 'showCompanySyncs'])->name('company');
            Route::post('/{codeSync}/unsync', [DelivererSyncManagementController::class, 'unsyncUser'])->name('unsync');
            Route::post('/{codeSync}/reactivate', [DelivererSyncManagementController::class, 'reactivateSync'])->name('reactivate');
            Route::post('/{codeSync}/ban', [DelivererSyncManagementController::class, 'banUser'])->name('ban');
            Route::post('/{codeSync}/unban', [DelivererSyncManagementController::class, 'unbanUser'])->name('unban');
            Route::delete('/{codeSync}', [DelivererSyncManagementController::class, 'deleteSync'])->name('delete');
        });

        // Shops management
        Route::resource('shops', ShopController::class);
        Route::post('/shops/{shop}/verify', [\App\Http\Controllers\Admin\ShopVerificationController::class, 'verify'])->name('shops.verify');
        Route::post('/shops/{shop}/reject', [\App\Http\Controllers\Admin\ShopVerificationController::class, 'reject'])->name('shops.reject');
        Route::post('/shops/{shop}/toggle-status', [\App\Http\Controllers\Admin\ShopVerificationController::class, 'toggleStatus'])->name('shops.toggleStatus');

        // Shop Location Requests
        Route::post('/shops/{shop}/location-requests/{request}/approve', [ShopController::class, 'approveLocationRequest'])->name('shops.location-requests.approve');
        Route::post('/shops/{shop}/location-requests/{request}/reject', [ShopController::class, 'rejectLocationRequest'])->name('shops.location-requests.reject');

        // Products management
        Route::resource('products', ProductController::class);
        Route::get('/categories/{category}/subcategories', [ProductController::class, 'getSubcategories'])->name('categories.subcategories');
        Route::delete('/products/{product}/images/{image}', [ProductController::class, 'deleteImage'])->name('products.images.delete');
        Route::post('/products/{product}/images/{image}/primary', [ProductController::class, 'setPrimaryImage'])->name('products.images.setPrimary');

        // Settings
        Route::prefix('settings')->name('settings.')->group(function () {
            // General Settings
            Route::get('/', [SettingsController::class, 'index'])->name('index');
            Route::put('/update', [SettingsController::class, 'update'])->name('update');

            // Maintenance
            Route::get('/maintenance', [SettingsController::class, 'maintenance'])->name('maintenance');
            Route::put('/maintenance/update', [SettingsController::class, 'updateMaintenance'])->name('maintenance.update');
            Route::post('/maintenance/enable', [MaintenanceController::class, 'enable'])->name('maintenance.enable');
            Route::post('/maintenance/disable', [MaintenanceController::class, 'disable'])->name('maintenance.disable');
            Route::post('/maintenance/toggle', [MaintenanceController::class, 'toggle'])->name('maintenance.toggle');

            // Payments
            Route::get('/payments', [SettingsController::class, 'payments'])->name('payments');
            Route::put('/payments/update', [SettingsController::class, 'updatePayments'])->name('payments.update');
            Route::post('/payments/test-kpay', [SettingsController::class, 'testKpay'])->name('payments.test-kpay');

            // Services
            Route::get('/services', [SettingsController::class, 'services'])->name('services');
            Route::put('/services/update', [SettingsController::class, 'updateServices'])->name('services.update');

            // Commissions
            Route::put('/commissions/update', [SettingsController::class, 'updateCommissions'])->name('commissions.update');
            Route::delete('/commissions/{commission}', [SettingsController::class, 'destroyCommission'])->name('commissions.destroy');

            // Category Settings
            Route::get('/categories', [CategorySettingsController::class, 'index'])->name('categories');

            // Categories
            Route::post('/categories', [CategorySettingsController::class, 'storeCategory'])->name('categories.store');
            Route::put('/categories/{category}', [CategorySettingsController::class, 'updateCategory'])->name('categories.update');
            Route::delete('/categories/{category}', [CategorySettingsController::class, 'destroyCategory'])->name('categories.destroy');

            // Subcategories
            Route::post('/subcategories', [CategorySettingsController::class, 'storeSubcategory'])->name('subcategories.store');
            Route::put('/subcategories/{subcategory}', [CategorySettingsController::class, 'updateSubcategory'])->name('subcategories.update');
            Route::delete('/subcategories/{subcategory}', [CategorySettingsController::class, 'destroySubcategory'])->name('subcategories.destroy');
        });

        // Legal Pages
        Route::resource('legal-pages', LegalPageController::class)->except(['show']);
        Route::patch('/legal-pages/{legalPage}/toggle', [LegalPageController::class, 'toggle'])->name('legal-pages.toggle');

        // Banners
        Route::resource('banners', BannerController::class);
        Route::patch('/banners/{banner}/toggle-status', [BannerController::class, 'toggleStatus'])->name('banners.toggle-status');

        // Pays importés (Chine, Turquie, Dubaï…) — alimente la section "Produits importés" de l'app
        Route::get('/import-countries', [ImportCountryController::class, 'index'])->name('import-countries.index');
        Route::post('/import-countries', [ImportCountryController::class, 'store'])->name('import-countries.store');
        Route::put('/import-countries/{importCountry}', [ImportCountryController::class, 'update'])->name('import-countries.update');
        Route::patch('/import-countries/{importCountry}/toggle-status', [ImportCountryController::class, 'toggleStatus'])->name('import-countries.toggle-status');
        Route::delete('/import-countries/{importCountry}', [ImportCountryController::class, 'destroy'])->name('import-countries.destroy');

        // Announcements
        Route::resource('announcements', AnnouncementController::class);
        Route::post('/announcements/{announcement}/send', [AnnouncementController::class, 'send'])->name('announcements.send');

        // Transactions
        Route::get('/transactions', [TransactionController::class, 'index'])->name('transactions.index');
        Route::get('/transactions/{transaction}', [TransactionController::class, 'show'])->name('transactions.show');
        Route::get('/transactions-export-excel', [TransactionController::class, 'exportExcel'])->name('transactions.export-excel');
        Route::get('/transactions-export-pdf', [TransactionController::class, 'exportPdf'])->name('transactions.export-pdf');

        // Exchanges - Surveillance des échanges
        Route::get('/exchanges', [ExchangeController::class, 'index'])->name('exchanges.index');
        Route::get('/exchanges/conversations', [ExchangeController::class, 'conversations'])->name('exchanges.conversations');
        Route::get('/exchanges/conversations/{conversation}', [ExchangeController::class, 'show'])->name('exchanges.show');
        Route::get('/exchanges/contact-clicks', [ExchangeController::class, 'contactClicks'])->name('exchanges.contact-clicks');

        // Map - Visualisation géographique
        Route::get('/map', [MapController::class, 'index'])->name('map.index');
        Route::get('/map/data', [MapController::class, 'getUsersData'])->name('map.data');

        // Packages - Gestion des abonnements
        Route::resource('packages', PackageController::class);
        Route::post('/packages/{package}/toggle-active', [PackageController::class, 'toggleActive'])->name('packages.toggle-active');
        Route::post('/packages/{package}/toggle-popular', [PackageController::class, 'togglePopular'])->name('packages.toggle-popular');

        // Support - Gestion des tickets
        Route::get('/support', [SupportController::class, 'index'])->name('support.index');
        Route::get('/support/{ticket}', [SupportController::class, 'show'])->name('support.show');
        Route::post('/support/{ticket}/reply', [SupportController::class, 'reply'])->name('support.reply');
        Route::post('/support/{ticket}/update-status', [SupportController::class, 'updateStatus'])->name('support.update-status');
        Route::post('/support/{ticket}/update-priority', [SupportController::class, 'updatePriority'])->name('support.update-priority');
        Route::post('/support/{ticket}/assign', [SupportController::class, 'assign'])->name('support.assign');
        Route::delete('/support/{ticket}', [SupportController::class, 'destroy'])->name('support.destroy');

        // Affiliation - Système de parrainage
        Route::get('/affiliate/settings', [AffiliateController::class, 'settings'])->name('affiliate.settings');
        Route::post('/affiliate/settings', [AffiliateController::class, 'updateSettings'])->name('affiliate.update-settings');
        Route::get('/affiliate/tree', [AffiliateController::class, 'tree'])->name('affiliate.tree');
        Route::get('/affiliate/tree-data/{user}', [AffiliateController::class, 'getTreeData'])->name('affiliate.tree-data');
        Route::get('/affiliate/commissions', [AffiliateController::class, 'commissions'])->name('affiliate.commissions');
        Route::post('/affiliate/commissions/{commission}/approve', [AffiliateController::class, 'approveCommission'])->name('affiliate.approve-commission');
        Route::post('/affiliate/commissions/{commission}/pay', [AffiliateController::class, 'payCommission'])->name('affiliate.pay-commission');
        Route::post('/affiliate/commissions/{commission}/reject', [AffiliateController::class, 'rejectCommission'])->name('affiliate.reject-commission');

        // Documents - Gestion documentaire
        Route::resource('documents', DocumentController::class);
        Route::get('/documents/{document}/download', [DocumentController::class, 'download'])->name('documents.download');
        Route::post('/documents/{document}/archive', [DocumentController::class, 'archive'])->name('documents.archive');
        Route::post('/documents/{document}/unarchive', [DocumentController::class, 'unarchive'])->name('documents.unarchive');
        Route::get('/documents-categories', [DocumentController::class, 'categories'])->name('documents.categories');
        Route::post('/documents-categories', [DocumentController::class, 'storeCategory'])->name('documents.categories.store');
        Route::put('/documents-categories/{category}', [DocumentController::class, 'updateCategory'])->name('documents.categories.update');
        Route::delete('/documents-categories/{category}', [DocumentController::class, 'destroyCategory'])->name('documents.categories.destroy');

        // Database - Interface SQL
        Route::get('/database', [DatabaseController::class, 'index'])->name('database.index');
        Route::get('/database/table/{table}', [DatabaseController::class, 'table'])->name('database.table');
        Route::post('/database/query', [DatabaseController::class, 'query'])->name('database.query');
        Route::post('/database/export', [DatabaseController::class, 'export'])->name('database.export');

        // Vault - Gestionnaire de credentials sécurisé
        Route::resource('vault', VaultController::class);
        Route::get('/vault/{credential}/reveal', [VaultController::class, 'reveal'])->name('vault.reveal');
        Route::post('/vault/{credential}/toggle-favorite', [VaultController::class, 'toggleFavorite'])->name('vault.toggle-favorite');
        Route::get('/vault-categories', [VaultController::class, 'categories'])->name('vault.categories');

        // FCM Tokens - Gestion des tokens de notifications push
        Route::get('/fcm-tokens', [FcmTokenController::class, 'index'])->name('fcm-tokens.index');
        Route::get('/fcm-tokens/{user}', [FcmTokenController::class, 'show'])->name('fcm-tokens.show');
        Route::delete('/fcm-tokens/token/{token}', [FcmTokenController::class, 'destroyToken'])->name('fcm-tokens.token.destroy');
        Route::post('/fcm-tokens/token/{token}/toggle', [FcmTokenController::class, 'toggleToken'])->name('fcm-tokens.token.toggle');

        // OTP Bypass - Gestion des numéros autorisés à bypasser l'OTP
        Route::prefix('otp-bypass')->name('otp-bypass.')->group(function () {
            Route::get('/', [OtpBypassController::class, 'index'])->name('index');
            Route::post('/', [OtpBypassController::class, 'store'])->name('store');
            Route::put('/{id}', [OtpBypassController::class, 'update'])->name('update');
            Route::patch('/{id}/toggle', [OtpBypassController::class, 'toggleStatus'])->name('toggle');
            Route::delete('/{id}', [OtpBypassController::class, 'destroy'])->name('destroy');
        });

        // Stripe Connect - Validation des comptes de virement (IBAN) vendeurs
        Route::prefix('stripe/accounts')->name('stripe.accounts.')->group(function () {
            Route::get('/', [App\Http\Controllers\Admin\StripeConnectController::class, 'indexWeb'])->name('index');
            Route::post('/{userId}/approve', [App\Http\Controllers\Admin\StripeConnectController::class, 'approveWeb'])->name('approve');
            Route::post('/{userId}/reject', [App\Http\Controllers\Admin\StripeConnectController::class, 'rejectWeb'])->name('reject');
        });

        // DIASPO - Vérification des documents d'identité
        Route::prefix('diaspo')->name('diaspo.')->group(function () {
            Route::prefix('verifications')->name('verifications.')->group(function () {
                Route::get('/', [DiaspoVerificationController::class, 'indexWeb'])->name('index');
                Route::get('/{userId}', [DiaspoVerificationController::class, 'showWeb'])->name('show');
                Route::post('/{userId}/approve', [DiaspoVerificationController::class, 'approveWeb'])->name('approve');
                Route::post('/{userId}/reject', [DiaspoVerificationController::class, 'rejectWeb'])->name('reject');
            });

            // Posts management
            Route::prefix('posts')->name('posts.')->group(function () {
                Route::get('/', [PostController::class, 'index'])->name('index');
                Route::get('/{post}', [PostController::class, 'show'])->name('show');
                Route::delete('/{post}', [PostController::class, 'destroy'])->name('destroy');
                Route::delete('/{post}/comments/{comment}', [PostController::class, 'deleteComment'])->name('comments.destroy');
            });

            // Offers management
            Route::prefix('offers')->name('offers.')->group(function () {
                Route::get('/', [DiaspoOfferController::class, 'index'])->name('index');
                Route::get('/{offer}', [DiaspoOfferController::class, 'show'])->name('show');
                Route::post('/{offer}/approve', [DiaspoOfferController::class, 'approve'])->name('approve');
                Route::post('/{offer}/reject', [DiaspoOfferController::class, 'reject'])->name('reject');
                Route::delete('/{offer}', [DiaspoOfferController::class, 'destroy'])->name('destroy');
                Route::post('/{offer}/bookings/{booking}/cancel', [DiaspoOfferController::class, 'cancelBooking'])->name('bookings.cancel');
            });
        });

    });
});
