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
use App\Http\Controllers\Admin\MessageController;
use App\Http\Controllers\Admin\AffiliateController;
use App\Http\Controllers\Admin\SalesAgentController;
use App\Http\Controllers\Admin\SalesCommissionController;
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
use App\Http\Controllers\Admin\ImportShippingOptionController;

// Redirect root to admin login
Route::get('/', function () {
    return redirect()->route('admin.login');
});

// Lien public d'un produit, celui que l'application partage.
//
// Sur un appareil où l'application est installée, le système ouvre la fiche
// directement (lien universel) et cette route n'est jamais atteinte. Ailleurs
// — navigateur de bureau, téléphone sans l'application — elle renvoie vers la
// boutique d'applications correspondante.
//
// Le lien n'ouvre l'application que si le domaine sert aussi
// /.well-known/assetlinks.json (Android) et
// /.well-known/apple-app-site-association (iOS).
Route::get('/produit/{id}', function (string $id) {
    $agent = (string) request()->header('User-Agent');

    $store = match (true) {
        (bool) preg_match('/android/i', $agent) => config('app.android_store_url'),
        (bool) preg_match('/iphone|ipad|ipod/i', $agent) => config('app.ios_store_url'),
        default => config('app.android_store_url'),
    };

    return redirect()->away($store);
})->name('product.share')->where('id', '[0-9]+');

// iOS refuse le fichier d'association s'il n'est pas servi en
// application/json. Sans extension, le serveur web ne devine pas le type :
// on le sert donc explicitement.
Route::get('/.well-known/apple-app-site-association', function () {
    // Le fichier porte l'extension .json sur le disque : sans elle, le
    // serveur web le sert en statique, sans type, avant même d'atteindre
    // Laravel — et iOS l'ignore alors silencieusement.
    $path = public_path('.well-known/apple-app-site-association.json');
    abort_unless(is_file($path), 404);

    return response()->file($path, ['Content-Type' => 'application/json']);
});

// Pages de retour des paiements par redirection (PayPal, Stripe Checkout).
// La WebView mobile intercepte ces URLs pour clôturer le parcours ; la confirmation
// réelle du paiement se fait côté serveur (webhook + polling), pas sur ces pages.
Route::get('/payment/success', function () {
    return response('<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Paiement effectué</title></head><body style="font-family:sans-serif;text-align:center;padding:40px"><h2>Paiement effectué</h2><p>Vous pouvez fermer cette page et revenir à l\'application.</p></body></html>');
})->name('payment.success');

Route::get('/payment/cancel', function () {
    return response('<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Paiement annulé</title></head><body style="font-family:sans-serif;text-align:center;padding:40px"><h2>Paiement annulé</h2><p>Vous pouvez fermer cette page et revenir à l\'application.</p></body></html>');
})->name('payment.cancel');

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

        // P4 — Partenaires logistiques (réglages, conditions, trajets au poids) et expéditions
        Route::prefix('delivery-partners')->name('delivery-partners.')->group(function () {
            Route::get('/', [\App\Http\Controllers\Admin\DeliveryPartnerController::class, 'index'])->name('index');
            Route::put('/settings', [\App\Http\Controllers\Admin\DeliveryPartnerController::class, 'updateSettings'])->name('settings');
            Route::post('/', [\App\Http\Controllers\Admin\DeliveryPartnerController::class, 'store'])->name('store');
            Route::get('/{partner}', [\App\Http\Controllers\Admin\DeliveryPartnerController::class, 'edit'])->name('edit');
            Route::put('/{partner}', [\App\Http\Controllers\Admin\DeliveryPartnerController::class, 'update'])->name('update');
            Route::post('/{partner}/routes', [\App\Http\Controllers\Admin\DeliveryPartnerController::class, 'storeRoute'])->name('routes.store');
            Route::put('/{partner}/routes/{route}', [\App\Http\Controllers\Admin\DeliveryPartnerController::class, 'updateRoute'])->name('routes.update');
            Route::delete('/{partner}/routes/{route}', [\App\Http\Controllers\Admin\DeliveryPartnerController::class, 'destroyRoute'])->name('routes.destroy');
            Route::put('/{partner}/city-grids/{grid}', [\App\Http\Controllers\Admin\DeliveryPartnerController::class, 'updateCityGrid'])->name('city-grids.update');
            Route::post('/{partner}/city-grids', [\App\Http\Controllers\Admin\DeliveryPartnerController::class, 'storeCityGrid'])->name('city-grids.store');
            Route::delete('/{partner}/city-grids/{grid}', [\App\Http\Controllers\Admin\DeliveryPartnerController::class, 'destroyCityGrid'])->name('city-grids.destroy');
        });
        Route::get('/shipments', [\App\Http\Controllers\Admin\ShipmentController::class, 'index'])->name('shipments.index');
        Route::get('/shipments/{order}', [\App\Http\Controllers\Admin\ShipmentController::class, 'show'])->name('shipments.show');
        Route::post('/shipments/{order}/steps', [\App\Http\Controllers\Admin\ShipmentController::class, 'addStep'])->name('shipments.step');

        // Commandes en gros (Chine, Dubaï, Turquie → Douala → SOLEX)
        Route::get('/wholesale-orders', [\App\Http\Controllers\Admin\WholesaleOrderController::class, 'index'])->name('wholesale-orders.index');
        Route::get('/wholesale-orders/{order}', [\App\Http\Controllers\Admin\WholesaleOrderController::class, 'show'])->name('wholesale-orders.show');
        Route::post('/wholesale-orders/{order}/confirm', [\App\Http\Controllers\Admin\WholesaleOrderController::class, 'confirm'])->name('wholesale-orders.confirm');
        Route::post('/wholesale-orders/{order}/reject', [\App\Http\Controllers\Admin\WholesaleOrderController::class, 'reject'])->name('wholesale-orders.reject');

        // Deliverers (Livreurs partenaires)
        Route::resource('deliverers', DelivererController::class)->except(['store']);
        Route::post('deliverers/{deliverer}/sync-code', [DelivererController::class, 'generateSyncCode'])->name('deliverers.sync-code');

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

        // Statistiques boutiques (P8)
        Route::prefix('statistics')->name('statistics.')->group(function () {
            Route::get('/', [\App\Http\Controllers\Admin\ShopStatisticsController::class, 'index'])->name('index');
            Route::post('/settings', [\App\Http\Controllers\Admin\ShopStatisticsController::class, 'updateSettings'])->name('settings');
            Route::get('/shops/{shop}', [\App\Http\Controllers\Admin\ShopStatisticsController::class, 'show'])->name('shops.show');
            Route::post('/shops/{shop}/reset-audience', [\App\Http\Controllers\Admin\ShopStatisticsController::class, 'resetAudience'])->name('shops.reset');
        });

        // Asso Ads — campagnes de sponsoring de produits
        Route::prefix('ads')->name('ads.')->group(function () {
            Route::get('/', [\App\Http\Controllers\Admin\ProductBoostController::class, 'index'])->name('index');
            Route::post('/settings', [\App\Http\Controllers\Admin\ProductBoostController::class, 'updateSettings'])->name('settings');
            Route::get('/{boost}', [\App\Http\Controllers\Admin\ProductBoostController::class, 'show'])->name('show');
            Route::post('/{boost}/cancel', [\App\Http\Controllers\Admin\ProductBoostController::class, 'cancel'])->name('cancel');
        });

        // Shop Location Requests
        Route::post('/shops/{shop}/location-requests/{request}/approve', [ShopController::class, 'approveLocationRequest'])->name('shops.location-requests.approve');
        Route::post('/shops/{shop}/location-requests/{request}/reject', [ShopController::class, 'rejectLocationRequest'])->name('shops.location-requests.reject');

        // Products management
        Route::resource('products', ProductController::class);
        Route::get('/categories/{category}/subcategories', [ProductController::class, 'getSubcategories'])->name('categories.subcategories');
        Route::delete('/products/{product}/images/{image}', [ProductController::class, 'deleteImage'])->name('products.images.delete');
        Route::post('/products/{product}/images/{image}/primary', [ProductController::class, 'setPrimaryImage'])->name('products.images.setPrimary');
        Route::post('/products/{product}/images/reorder', [ProductController::class, 'reorderImages'])->name('products.images.reorder');

        // Vidéos produits grossistes : envoi par morceaux avant l'enregistrement du produit
        Route::post('/product-videos/chunks', [\App\Http\Controllers\Admin\ProductVideoController::class, 'chunk'])->name('product-videos.chunk');
        Route::get('/product-videos/{video}', [\App\Http\Controllers\Admin\ProductVideoController::class, 'show'])->name('product-videos.show');
        Route::delete('/product-videos/{video}', [\App\Http\Controllers\Admin\ProductVideoController::class, 'destroy'])->name('product-videos.destroy');
        Route::get('/product-videos/{video}/media/{kind}', [\App\Http\Controllers\Admin\ProductVideoController::class, 'media'])
            ->whereIn('kind', ['video', 'preview', 'poster'])
            ->name('product-videos.media');

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
            // Diagnostic de la chaîne de virement IBAN (équivalent web de stripe:doctor).
            Route::post('/payments/diagnose-stripe', [SettingsController::class, 'diagnoseStripe'])->name('payments.diagnose-stripe');

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
        Route::post('/import-countries/hub', [ImportCountryController::class, 'createHub'])->name('import-countries.hub');
        Route::post('/import-countries', [ImportCountryController::class, 'store'])->name('import-countries.store');
        Route::put('/import-countries/{importCountry}', [ImportCountryController::class, 'update'])->name('import-countries.update');
        Route::patch('/import-countries/{importCountry}/toggle-status', [ImportCountryController::class, 'toggleStatus'])->name('import-countries.toggle-status');
        Route::delete('/import-countries/{importCountry}', [ImportCountryController::class, 'destroy'])->name('import-countries.destroy');

        // Options d'expédition rattachées à un pays importé
        Route::prefix('import-countries/{importCountry}/shipping-options')
            ->name('import-countries.shipping-options.')
            ->group(function () {
                Route::get('/', [ImportShippingOptionController::class, 'index'])->name('index');
                Route::post('/', [ImportShippingOptionController::class, 'store'])->name('store');
                Route::put('/{shippingOption}', [ImportShippingOptionController::class, 'update'])->name('update');
                Route::patch('/{shippingOption}/toggle-status', [ImportShippingOptionController::class, 'toggleStatus'])->name('toggle-status');
                Route::delete('/{shippingOption}', [ImportShippingOptionController::class, 'destroy'])->name('destroy');
            });

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

        // Messagerie support (style WhatsApp) — conversations client ↔ compte support
        Route::get('/messages', [MessageController::class, 'index'])->name('messages.index');
        Route::get('/messages/{conversation}', [MessageController::class, 'show'])->name('messages.show');
        Route::post('/messages/{conversation}/reply', [MessageController::class, 'reply'])->name('messages.reply');

        // Affiliation - Système de parrainage
        Route::get('/affiliate/settings', [AffiliateController::class, 'settings'])->name('affiliate.settings');
        Route::post('/affiliate/settings', [AffiliateController::class, 'updateSettings'])->name('affiliate.update-settings');
        Route::get('/affiliate/tree', [AffiliateController::class, 'tree'])->name('affiliate.tree');
        Route::get('/affiliate/tree-data/{user}', [AffiliateController::class, 'getTreeData'])->name('affiliate.tree-data');
        Route::get('/affiliate/commissions', [AffiliateController::class, 'commissions'])->name('affiliate.commissions');
        Route::post('/affiliate/commissions/{commission}/approve', [AffiliateController::class, 'approveCommission'])->name('affiliate.approve-commission');
        Route::post('/affiliate/commissions/{commission}/pay', [AffiliateController::class, 'payCommission'])->name('affiliate.pay-commission');
        Route::post('/affiliate/commissions/{commission}/reject', [AffiliateController::class, 'rejectCommission'])->name('affiliate.reject-commission');

        // P6 — Commerciaux : codes, suivi et commissions sur les forfaits
        Route::post('/sales/agents/default-rate', [SalesAgentController::class, 'updateDefaultRate'])->name('sales.agents.default-rate');
        Route::post('/sales/agents/{agent}/toggle-active', [SalesAgentController::class, 'toggleActive'])->name('sales.agents.toggle-active');
        Route::post('/sales/agents/{agent}/regenerate-code', [SalesAgentController::class, 'regenerateCode'])->name('sales.agents.regenerate-code');
        Route::resource('sales/agents', SalesAgentController::class)
            ->parameters(['agents' => 'agent'])
            ->names('sales.agents')
            ->except(['destroy']);
        Route::get('/sales/commissions', [SalesCommissionController::class, 'index'])->name('sales.commissions.index');
        Route::get('/sales/commissions/export', [SalesCommissionController::class, 'export'])->name('sales.commissions.export');
        Route::post('/sales/commissions/mark-paid', [SalesCommissionController::class, 'markPaid'])->name('sales.commissions.mark-paid');
        Route::post('/sales/commissions/{commission}/cancel', [SalesCommissionController::class, 'cancel'])->name('sales.commissions.cancel');

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
                Route::post('/settings', [DiaspoVerificationController::class, 'updateSettingsWeb'])->name('settings');
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
                Route::get('/{offer}', [DiaspoOfferController::class, 'show'])->name('show')->withTrashed();
                Route::post('/{offer}/approve', [DiaspoOfferController::class, 'approve'])->name('approve');
                Route::post('/{offer}/reject', [DiaspoOfferController::class, 'reject'])->name('reject');
                Route::post('/{offer}/extend-deadline', [DiaspoOfferController::class, 'extendDeadline'])->name('extend-deadline');
                Route::delete('/{offer}', [DiaspoOfferController::class, 'destroy'])->name('destroy');
                Route::post('/{offer}/bookings/{booking}/cancel', [DiaspoOfferController::class, 'cancelBooking'])->name('bookings.cancel');
            });
        });

    });
});