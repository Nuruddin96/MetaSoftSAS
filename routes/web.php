<?php

use App\Http\Controllers\Affiliate\AuthController as AffiliateAuthController;
use App\Http\Controllers\Affiliate\DashboardController as AffiliateDashboardController;
use App\Http\Controllers\CentralAuth\CentralLoginController;
use App\Http\Controllers\CentralAuth\RegisterController;
use App\Http\Controllers\FacebookOAuthCallbackController;
use App\Http\Controllers\LandingController;
use App\Http\Controllers\MessengerWebhookController;
use App\Http\Controllers\PwaController;
use App\Http\Controllers\ServicesController;
use App\Http\Controllers\Storefront\CartController;
use App\Http\Controllers\Storefront\CheckoutController;
use App\Http\Controllers\Storefront\HomeController as StorefrontHome;
use App\Http\Controllers\Storefront\PageController as StorefrontPage;
use App\Http\Controllers\Storefront\ProductController as StorefrontProduct;
use App\Http\Controllers\SuperAdmin\AdvertisingController as SuperAdvertisingController;
use App\Http\Controllers\SuperAdmin\AffiliateController as SuperAffiliateController;
use App\Http\Controllers\SuperAdmin\AiCreditController as SuperAiCreditController;
use App\Http\Controllers\SuperAdmin\AuthController;
use App\Http\Controllers\SuperAdmin\ClientController;
use App\Http\Controllers\SuperAdmin\ClientPaymentController;
use App\Http\Controllers\SuperAdmin\PaymentController;
use App\Http\Controllers\SuperAdmin\PlanController;
use App\Http\Controllers\SuperAdmin\SourceOrderController;
use App\Http\Controllers\SuperAdmin\SourceProductController;
use App\Http\Controllers\SuperAdmin\TenantController;
use App\Http\Controllers\TelegramController;
use App\Http\Controllers\Tenant\AdvertisingController;
use App\Http\Controllers\Tenant\AiChatController;
use App\Http\Controllers\Tenant\BarcodeController;
use App\Http\Controllers\Tenant\BillingController;
use App\Http\Controllers\Tenant\CategoryController;
use App\Http\Controllers\Tenant\CourierController;
use App\Http\Controllers\Tenant\CustomerController;
use App\Http\Controllers\Tenant\DashboardController;
use App\Http\Controllers\Tenant\ExpenseController;
use App\Http\Controllers\Tenant\FacebookConnectController;
use App\Http\Controllers\Tenant\FraudCheckController;
use App\Http\Controllers\Tenant\InboxController;
use App\Http\Controllers\Tenant\IncompleteOrderController;
use App\Http\Controllers\Tenant\InventoryController;
use App\Http\Controllers\Tenant\MessengerInboxController;
use App\Http\Controllers\Tenant\NotificationController;
use App\Http\Controllers\Tenant\NotificationPreferenceController;
use App\Http\Controllers\Tenant\OrderController;
use App\Http\Controllers\Tenant\PosController;
use App\Http\Controllers\Tenant\ProductController;
use App\Http\Controllers\Tenant\ProductImportController;
use App\Http\Controllers\Tenant\ProductSourceController;
use App\Http\Controllers\Tenant\PushSubscriptionController;
use App\Http\Controllers\Tenant\PwaController as TenantPwaController;
use App\Http\Controllers\Tenant\ReportController;
use App\Http\Controllers\Tenant\SettingController;
use App\Http\Controllers\Tenant\WebsiteController;
use App\Http\Controllers\Tenant\WhatsAppConnectController;
use App\Http\Controllers\Tenant\WhatsAppInboxController;
use App\Http\Controllers\TenantAuth\LoginController;
use App\Http\Controllers\WhatsAppWebhookController;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| 1. CENTRAL  (metasoftbd.com)
|--------------------------------------------------------------------------
*/
Route::domain(config('app.central_domain'))->group(function () {

    Route::get('/', [LandingController::class, 'index'])->name('landing');

    // Backs the landing page's "Install App" button — see PwaController's
    // docblock for why this can't just reuse Tenant\PwaController's
    // manifest (different scope), and why it's still not a second PWA
    // mechanism (same service-worker script, same icon set).
    Route::get('/manifest.json', [PwaController::class, 'manifest'])->name('central.pwa.manifest');
    Route::get('/sw.js', [PwaController::class, 'serviceWorker'])->name('central.pwa.sw');

    Route::get('/services', [ServicesController::class, 'index'])->name('services');

    Route::get('/register', [RegisterController::class, 'show'])->name('register');
    Route::post('/register', [RegisterController::class, 'store'])->name('register.store');

    Route::get('/login', [CentralLoginController::class, 'show'])->name('central.login');
    Route::post('/login', [CentralLoginController::class, 'login'])->name('central.login.attempt');

    Route::get('/forgot-password', [CentralLoginController::class, 'forgotForm'])->name('central.password.forgot');
    Route::post('/forgot-password', [CentralLoginController::class, 'sendResetLink'])->name('central.password.email');
    Route::get('/reset-password/{token}', [CentralLoginController::class, 'resetForm'])->name('central.password.reset');
    Route::post('/reset-password', [CentralLoginController::class, 'resetPassword'])->name('central.password.update');

    /* ---------------- AFFILIATE ---------------- */
    Route::prefix('affiliate')->name('affiliate.')->group(function () {
        Route::get('register', [AffiliateAuthController::class, 'registerForm'])->name('register');
        Route::post('register', [AffiliateAuthController::class, 'register'])->name('register.submit');
        Route::get('login', [AffiliateAuthController::class, 'loginForm'])->name('login');
        Route::post('login', [AffiliateAuthController::class, 'login'])->name('login.attempt');
        Route::post('logout', [AffiliateAuthController::class, 'logout'])->name('logout');

        Route::middleware('auth:affiliate')->group(function () {
            Route::get('dashboard', [AffiliateDashboardController::class, 'index'])->name('dashboard');
            Route::post('lead', [AffiliateDashboardController::class, 'submitLead'])->name('lead.submit');
        });
    });

    /* ---------------- SUPER ADMIN ---------------- */
    Route::prefix('super-admin')->name('super.')->group(function () {
        Route::get('login', [AuthController::class, 'show'])->name('login');
        Route::post('login', [AuthController::class, 'login'])->name('login.attempt');
        Route::post('logout', [AuthController::class, 'logout'])->name('logout');

        Route::middleware('auth:super_admin')->group(function () {
            Route::get('/', [App\Http\Controllers\SuperAdmin\DashboardController::class, 'index'])->name('dashboard');

            Route::get('tenants', [TenantController::class, 'index'])->name('tenants');
            Route::get('tenants/{tenant}', [TenantController::class, 'show'])->name('tenants.show');
            Route::get('tenants/{tenant}/edit', [TenantController::class, 'edit'])->name('tenants.edit');
            Route::put('tenants/{tenant}', [TenantController::class, 'update'])->name('tenants.update');
            Route::post('tenants/{tenant}/reset-password', [TenantController::class, 'resetPassword'])->name('tenants.password.reset');
            Route::delete('tenants/{tenant}', [TenantController::class, 'destroy'])->name('tenants.destroy');
            Route::post('tenants/{tenant}/suspend', [TenantController::class, 'suspend'])->name('tenants.suspend');
            Route::post('tenants/{tenant}/activate', [TenantController::class, 'activate'])->name('tenants.activate');
            Route::post('tenants/{tenant}/extend', [TenantController::class, 'extend'])->name('tenants.extend');
            Route::post('tenants/{tenant}/domain/verify', [TenantController::class, 'verifyDomainDns'])->name('tenants.domain.verify');
            Route::post('tenants/{tenant}/domain/approve', [TenantController::class, 'approveDomain'])->name('tenants.domain.approve');
            Route::post('tenants/{tenant}/domain/reject', [TenantController::class, 'rejectDomain'])->name('tenants.domain.reject');

            Route::get('payments', [PaymentController::class, 'index'])->name('payments');

            // Regular Client Payment (agency clients — sub-module of Payments)
            Route::get('clients', [ClientController::class, 'index'])->name('clients.index');
            Route::get('clients/create', [ClientController::class, 'create'])->name('clients.create');
            Route::post('clients', [ClientController::class, 'store'])->name('clients.store');
            Route::get('clients/{client}', [ClientController::class, 'show'])->name('clients.show');
            Route::get('clients/{client}/edit', [ClientController::class, 'edit'])->name('clients.edit');
            Route::put('clients/{client}', [ClientController::class, 'update'])->name('clients.update');
            Route::delete('clients/{client}', [ClientController::class, 'destroy'])->name('clients.destroy');

            Route::get('client-payments', [ClientPaymentController::class, 'index'])->name('clients.payments');
            Route::post('clients/{client}/payments', [ClientPaymentController::class, 'store'])->name('clients.payments.store');
            Route::delete('client-payments/{payment}', [ClientPaymentController::class, 'destroy'])->name('clients.payments.destroy');

            Route::get('plans', [PlanController::class, 'index'])->name('plans');
            Route::put('plans/{plan}', [PlanController::class, 'update'])->name('plans.update');

            // Advertising / Ads Billing — full visibility (incl. Meta spend/margin), admin-only mutations
            Route::prefix('advertising')->name('advertising.')->group(function () {
                Route::get('/', [SuperAdvertisingController::class, 'index'])->name('index');
                Route::get('{tenant}', [SuperAdvertisingController::class, 'show'])->name('show');
                Route::post('{tenant}/activate', [SuperAdvertisingController::class, 'activate'])->name('activate');
                Route::put('{tenant}/settings', [SuperAdvertisingController::class, 'updateSettings'])->name('settings');
                Route::post('{tenant}/payments', [SuperAdvertisingController::class, 'storePayment'])->name('payments.store');
                Route::post('{tenant}/charges', [SuperAdvertisingController::class, 'storeCharge'])->name('charges.store');
                Route::post('{tenant}/adjustments', [SuperAdvertisingController::class, 'storeAdjustment'])->name('adjustments.store');
            });

            // AI Agent credit/wallet — allocation and manual adjustment only;
            // no "activate"/module-settings step, unlike Advertising above,
            // since an AI credit wallet has no separate module-enabled
            // switch — see AiCreditAccount's docblock.
            Route::prefix('ai-credit')->name('ai-credit.')->group(function () {
                Route::get('/', [SuperAiCreditController::class, 'index'])->name('index');
                Route::get('{tenant}', [SuperAiCreditController::class, 'show'])->name('show');
                Route::post('{tenant}/allocate', [SuperAiCreditController::class, 'allocate'])->name('allocate');
                Route::post('{tenant}/adjustments', [SuperAiCreditController::class, 'adjust'])->name('adjustments.store');
            });

            Route::get('source/products', [SourceProductController::class, 'index'])->name('source.products');
            Route::get('source/products/create', [SourceProductController::class, 'create'])->name('source.products.create');
            Route::post('source/products', [SourceProductController::class, 'store'])->name('source.products.store');
            Route::get('source/products/{product}/edit', [SourceProductController::class, 'edit'])->name('source.products.edit');
            Route::put('source/products/{product}', [SourceProductController::class, 'update'])->name('source.products.update');
            Route::delete('source/products/{product}', [SourceProductController::class, 'destroy'])->name('source.products.destroy');
            Route::delete('source/product-images/{image}', [SourceProductController::class, 'destroyImage'])->name('source.products.image.destroy');

            Route::get('source/orders', [SourceOrderController::class, 'index'])->name('source.orders');
            Route::put('source/orders/{order}', [SourceOrderController::class, 'update'])->name('source.orders.update');

            Route::get('affiliates', [SuperAffiliateController::class, 'index'])->name('affiliates');
            Route::get('affiliates/create', [SuperAffiliateController::class, 'create'])->name('affiliates.create');
            Route::post('affiliates', [SuperAffiliateController::class, 'store'])->name('affiliates.store');
            Route::get('affiliates/{affiliate}', [SuperAffiliateController::class, 'show'])->name('affiliates.show');
            Route::get('affiliates/{affiliate}/edit', [SuperAffiliateController::class, 'edit'])->name('affiliates.edit');
            Route::put('affiliates/{affiliate}', [SuperAffiliateController::class, 'updateInfo'])->name('affiliates.update');
            Route::delete('affiliates/{affiliate}', [SuperAffiliateController::class, 'destroy'])->name('affiliates.destroy');
            Route::post('affiliates/{affiliate}/suspend', [SuperAffiliateController::class, 'suspend'])->name('affiliates.suspend');
            Route::post('commissions/{commission}/paid', [SuperAffiliateController::class, 'markPaid'])->name('affiliates.commission.paid');
            Route::post('leads/{lead}/commission', [SuperAffiliateController::class, 'addServiceCommission'])->name('affiliates.lead.commission');
            Route::put('leads/{lead}', [SuperAffiliateController::class, 'updateLead'])->name('affiliates.lead.update');
        });
    });
});

/*
|--------------------------------------------------------------------------
| 2. TENANT ROUTES
|--------------------------------------------------------------------------
*/
$tenantRoutes = function () {

    /* ---------- 2a. TENANT ADMIN PANEL (/panel) ---------- */
    Route::prefix('panel')->name('tenant.')->group(function () {

        Route::get('login', [LoginController::class, 'show'])->name('login');
        Route::post('login', [LoginController::class, 'login'])->name('login.attempt');
        Route::post('logout', [LoginController::class, 'logout'])->name('logout');

        // Payment gateway redirects back here (GET + POST, no auth/session guaranteed)
        Route::match(['get', 'post'], 'billing/callback/{gateway}', [BillingController::class, 'callback'])
            ->name('billing.callback')->withoutMiddleware([ValidateCsrfToken::class]);

        // Public PWA endpoints — dynamic per-tenant (see PwaController docblock
        // for why this can't be a static public/manifest.json under path
        // tenancy). Deliberately OUTSIDE auth:tenant/check.subscription: the
        // browser must be able to fetch these from the (unauthenticated)
        // login page itself to discover/install the PWA before a session
        // exists. Only 'resolve.tenant' (applied at the group above) is
        // required — both handlers read app('currentTenant') alone and never
        // touch session/auth state or tenant business data.
        Route::get('manifest.json', [TenantPwaController::class, 'manifest'])->name('pwa.manifest');
        Route::get('sw.js', [TenantPwaController::class, 'serviceWorker'])->name('pwa.sw');

        Route::middleware(['auth:tenant', 'check.subscription'])->group(function () {

            Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

            // AI Agent panel chat (Phase 4) — the first live consumer of
            // the AI tool registry; see Tenant\AiChatController's docblock
            // for why this is tenant-authenticated only, unlike the
            // public Messenger auto-reply flow.
            Route::get('ai-chat', [AiChatController::class, 'index'])->name('ai-chat');
            Route::post('ai-chat/messages', [AiChatController::class, 'send'])->name('ai-chat.send');
            // Phase 5 confirmation system — the only routes that ever
            // trigger a mutating tool's actual execution (see
            // AiChatController's docblock).
            Route::post('ai-chat/actions/{pendingAction}/confirm', [AiChatController::class, 'confirm'])->name('ai-chat.actions.confirm');
            Route::post('ai-chat/actions/{pendingAction}/reject', [AiChatController::class, 'reject'])->name('ai-chat.actions.reject');

            // Notification bell "mark seen" beacon (session-based, see NotificationController)
            Route::post('notifications/seen', [NotificationController::class, 'markSeen'])->name('notifications.seen');

            // Web Push (VAPID) — subscribe/unsubscribe this browser, and the
            // per-category preferences screen. See App\Services\Notifications
            // and the mobile audit's Part C-H for the full architecture.
            Route::post('push/subscribe', [PushSubscriptionController::class, 'store'])->name('push.subscribe');
            Route::post('push/unsubscribe', [PushSubscriptionController::class, 'destroy'])->name('push.unsubscribe');
            Route::get('notifications/preferences', [NotificationPreferenceController::class, 'edit'])->name('notifications.preferences');
            Route::post('notifications/preferences', [NotificationPreferenceController::class, 'update'])->name('notifications.preferences.update');

            // Billing
            Route::get('billing', [BillingController::class, 'index'])->name('billing');
            Route::post('billing/pay', [BillingController::class, 'pay'])->name('billing.pay');

            // Catalog
            Route::get('categories', [CategoryController::class, 'index'])->name('categories.index');
            Route::post('categories', [CategoryController::class, 'store'])->name('categories.store');
            Route::delete('categories/{category}', [CategoryController::class, 'destroy'])->name('categories.destroy');

            Route::get('products', [ProductController::class, 'index'])->name('products.index');
            Route::get('products/create', [ProductController::class, 'create'])->name('products.create');
            Route::post('products', [ProductController::class, 'store'])->name('products.store');
            Route::get('products/{product}/edit', [ProductController::class, 'edit'])->name('products.edit');
            Route::put('products/{product}', [ProductController::class, 'update'])->name('products.update');
            Route::delete('products/{product}', [ProductController::class, 'destroy'])->name('products.destroy');
            Route::get('products/{product}/barcodes', [BarcodeController::class, 'print'])->name('products.barcodes');
            Route::delete('products/images/{image}', [ProductController::class, 'destroyImage'])->name('products.images.destroy');
            Route::post('products/{product}/images/reorder', [ProductController::class, 'reorderImages'])->name('products.images.reorder');

            // CSV bulk import
            Route::get('import', [ProductImportController::class, 'form'])->name('products.import.form');
            Route::get('import/template', [ProductImportController::class, 'template'])->name('products.template');
            Route::post('import', [ProductImportController::class, 'store'])->name('products.import');

            // Inventory
            Route::get('inventory', [InventoryController::class, 'index'])->name('inventory');
            Route::post('inventory/adjust', [InventoryController::class, 'adjust'])->name('inventory.adjust');
            Route::get('inventory/low-stock', [InventoryController::class, 'lowStock'])->name('inventory.low');

            // Orders
            Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
            Route::get('orders/create', [OrderController::class, 'create'])->name('orders.create');
            Route::post('orders', [OrderController::class, 'store'])->name('orders.store');
            Route::get('orders/{order}', [OrderController::class, 'show'])->name('orders.show');
            Route::post('orders/{order}/complete', [OrderController::class, 'complete'])->name('orders.complete');
            Route::post('orders/{order}/status', [OrderController::class, 'updateStatus'])->name('orders.status');
            Route::post('orders/{order}/channel', [OrderController::class, 'updateChannel'])->name('orders.channel');
            Route::post('orders/{order}/courier', [CourierController::class, 'send'])->name('orders.courier');
            Route::post('orders/{order}/courier/refresh', [CourierController::class, 'refreshStatus'])->name('orders.courier.refresh');
            Route::post('orders/bulk-status', [OrderController::class, 'bulkStatus'])->name('orders.bulk-status');
            Route::post('orders/bulk-courier', [OrderController::class, 'bulkCourier'])->name('orders.bulk-courier');
            Route::post('fraud-check', [FraudCheckController::class, 'check'])->name('fraud.check');

            // POS
            Route::get('pos', [PosController::class, 'index'])->name('pos');
            Route::get('pos/scan/{code}', [PosController::class, 'scan'])->name('pos.scan');
            Route::post('pos/sell', [PosController::class, 'sell'])->name('pos.sell');
            Route::get('pos/receipt/{order}', [PosController::class, 'receipt'])->name('pos.receipt');

            // Customers + due khata
            Route::get('customers', [CustomerController::class, 'index'])->name('customers.index');
            Route::post('customers', [CustomerController::class, 'store'])->name('customers.store');
            Route::get('customers/{customer}', [CustomerController::class, 'show'])->name('customers.show');
            Route::post('customers/{customer}/due', [CustomerController::class, 'receiveDue'])->name('customers.due.receive');
            Route::post('customers/{customer}/due/add', [CustomerController::class, 'addDue'])->name('customers.due.add');

            // Incomplete orders
            Route::get('incomplete-orders', [IncompleteOrderController::class, 'index'])->name('incomplete');
            Route::post('incomplete-orders/{incompleteOrder}/status', [IncompleteOrderController::class, 'updateStatus'])->name('incomplete.status');
            Route::delete('incomplete-orders/{incompleteOrder}', [IncompleteOrderController::class, 'destroy'])->name('incomplete.destroy');

            // Expenses
            Route::get('expenses', [ExpenseController::class, 'index'])->name('expenses.index');
            Route::post('expenses', [ExpenseController::class, 'store'])->name('expenses.store');
            Route::delete('expenses/{expense}', [ExpenseController::class, 'destroy'])->name('expenses.destroy');

            // Reports
            Route::get('reports/sales', [ReportController::class, 'sales'])->name('reports.sales');
            Route::get('reports/profit-loss', [ReportController::class, 'profitLoss'])->name('reports.pl');
            Route::get('reports/locations', [ReportController::class, 'locations'])->name('reports.locations');
            Route::get('reports/products', [ReportController::class, 'products'])->name('reports.products');

            // Website builder
            Route::get('website', [WebsiteController::class, 'index'])->name('website');
            Route::post('website/brand', [WebsiteController::class, 'brand'])->name('website.brand');
            Route::get('website/logo/remove', [WebsiteController::class, 'removeLogo'])->name('website.logo.remove');
            Route::post('website/homepage', [WebsiteController::class, 'homepage'])->name('website.homepage');
            Route::post('website/footer', [WebsiteController::class, 'footer'])->name('website.footer');
            Route::post('website/banner', [WebsiteController::class, 'storeBanner'])->name('website.banner.store');
            Route::delete('website/banner/{banner}', [WebsiteController::class, 'destroyBanner'])->name('website.banner.destroy');
            Route::post('website/page', [WebsiteController::class, 'storePage'])->name('website.page.store');
            Route::get('website/page/{page}/edit', [WebsiteController::class, 'editPage'])->name('website.page.edit');
            Route::put('website/page/{page}', [WebsiteController::class, 'updatePage'])->name('website.page.update');
            Route::delete('website/page/{page}', [WebsiteController::class, 'destroyPage'])->name('website.page.destroy');

            // Product Source
            Route::get('product-source', [ProductSourceController::class, 'index'])->name('product-source.index');
            Route::get('product-source/my-orders', [ProductSourceController::class, 'myOrders'])->name('product-source.orders');
            Route::get('product-source/{product}', [ProductSourceController::class, 'show'])->name('product-source.show');
            Route::post('product-source/{product}/order', [ProductSourceController::class, 'order'])->name('product-source.order');

            // Unified Inbox (Phase 5) — read-model list only; opening a
            // conversation hands off to that channel's own native route
            // below (messenger.show, unchanged; whatsapp.show, new).
            Route::get('inbox', [InboxController::class, 'index'])->name('inbox');
            Route::get('inbox/more', [InboxController::class, 'more'])->name('inbox.more');

            // Messenger inbox
            Route::get('messenger', [MessengerInboxController::class, 'index'])->name('messenger.index');
            Route::get('messenger/updates', [MessengerInboxController::class, 'updates'])->name('messenger.updates');
            Route::get('messenger/{psid}', [MessengerInboxController::class, 'show'])->name('messenger.show');
            Route::post('messenger/{psid}/reply', [MessengerInboxController::class, 'reply'])->name('messenger.reply');
            Route::post('messenger/{psid}/status', [MessengerInboxController::class, 'updateStatus'])->name('messenger.status');

            // Advertising / Ads Billing — read-only for tenants, gated by
            // AdvertisingBalanceService::isEnabled() inside the controller
            Route::prefix('advertising')->name('advertising.')->group(function () {
                Route::get('/', [AdvertisingController::class, 'overview'])->name('overview');
                Route::get('ledger', [AdvertisingController::class, 'ledger'])->name('ledger');
                Route::get('payments', [AdvertisingController::class, 'payments'])->name('payments');
                Route::get('charges', [AdvertisingController::class, 'charges'])->name('charges');
            });

            // Settings
            Route::get('settings', [SettingController::class, 'index'])->name('settings');
            Route::post('settings/marketing', [SettingController::class, 'marketing'])->name('settings.marketing');
            Route::post('settings/domain', [SettingController::class, 'requestDomain'])->name('settings.domain');
            Route::post('settings/messenger', [SettingController::class, 'messenger'])->name('settings.messenger');
            Route::post('settings/courier', [SettingController::class, 'courier'])->name('settings.courier');
            Route::post('settings/store', [SettingController::class, 'store'])->name('settings.store');
            Route::post('settings/ai-agent', [SettingController::class, 'aiAgent'])->name('settings.ai-agent');

            // Facebook OAuth "Connect Facebook" (Phase 1). The callback these
            // redirect out to is registered separately, outside this tenant
            // group — see the facebook.callback route below, and
            // FacebookOAuthCallbackController's docblock for why.
            Route::get('facebook/connect', [FacebookConnectController::class, 'redirect'])->name('facebook.connect');
            Route::get('facebook/pages', [FacebookConnectController::class, 'pages'])->name('facebook.pages');
            Route::post('facebook/pages/{pageId}/connect', [FacebookConnectController::class, 'connect'])->name('facebook.pages.connect');
            Route::post('facebook/pages/{page}/disconnect', [FacebookConnectController::class, 'disconnect'])->name('facebook.pages.disconnect');

            // WhatsApp Embedded Signup "Connect WhatsApp" (Phase 4). No
            // separate central callback route needed here, unlike Facebook
            // above — the signup popup runs entirely inside this
            // already-tenant-authenticated Settings page, so complete()
            // stays a normal panel route. See WhatsAppConnectController's
            // docblock for why.
            //
            // 'feature:whatsapp' gates connecting and using the inbox behind
            // the tenant's Plan (config/features.php + Plan::hasFeature()) —
            // disconnect is deliberately NOT gated, same reasoning
            // CheckSubscription always allows tenant.billing/tenant.logout:
            // a tenant who loses the feature (downgrade, or was never
            // granted it) must still be able to turn an existing connection
            // off, not get stuck unable to reach the one action that exits
            // the feature.
            Route::post('whatsapp/connect', [WhatsAppConnectController::class, 'complete'])->middleware('feature:whatsapp')->name('whatsapp.connect.complete');
            Route::post('whatsapp/{phone}/disconnect', [WhatsAppConnectController::class, 'disconnect'])->name('whatsapp.disconnect');

            // WhatsApp inbox (Phase 5) — channel-native thread view, mirrors
            // the Messenger inbox routes above wherever the concept
            // genuinely matches. 'updates' registered before the {waId}
            // wildcard so it isn't swallowed by it, same ordering Messenger
            // uses for messenger/updates vs messenger/{psid}.
            Route::middleware('feature:whatsapp')->group(function () {
                Route::get('whatsapp/updates', [WhatsAppInboxController::class, 'updates'])->name('whatsapp.updates');
                // Two segments ('whatsapp/media/{id}'), so this never collides
                // with the single-segment 'whatsapp/{waId}' wildcard below
                // regardless of registration order — kept next to 'updates'
                // for the same "register the non-wildcard route first" clarity.
                Route::get('whatsapp/media/{id}', [WhatsAppInboxController::class, 'media'])->name('whatsapp.media');
                Route::get('whatsapp/{waId}', [WhatsAppInboxController::class, 'show'])->name('whatsapp.show');
                Route::post('whatsapp/{waId}/reply', [WhatsAppInboxController::class, 'reply'])->name('whatsapp.reply');
                Route::post('whatsapp/{waId}/status', [WhatsAppInboxController::class, 'updateStatus'])->name('whatsapp.status');
            });
        });
    });

    /* ---------- 2b. STOREFRONT (customers, no login) ---------- */
    Route::middleware('check.subscription')->name('storefront.')->group(function () {
        Route::get('/', [StorefrontHome::class, 'index'])->name('home');
        Route::get('/products', [StorefrontProduct::class, 'index'])->name('products');
        Route::get('/product/{slug}', [StorefrontProduct::class, 'show'])->name('product');
        Route::get('/page/{slug}', [StorefrontPage::class, 'show'])->name('page');

        Route::get('/cart', [CartController::class, 'index'])->name('cart');
        Route::post('/cart/add', [CartController::class, 'add'])->name('cart.add');
        Route::post('/cart/update', [CartController::class, 'update'])->name('cart.update');
        Route::post('/cart/remove/{variantId}', [CartController::class, 'remove'])->name('cart.remove');
        Route::post('/cart/clear', [CartController::class, 'clear'])->name('cart.clear');

        Route::get('/checkout', [CheckoutController::class, 'show'])->name('checkout');
        Route::post('/checkout/track', [CheckoutController::class, 'trackIncomplete'])->name('checkout.track');
        Route::post('/checkout', [CheckoutController::class, 'place'])->name('checkout.place');
        Route::get('/order-success/{orderNumber}', [CheckoutController::class, 'success'])->name('order.success');
    });
};

/*
|--------------------------------------------------------------------------
| 3. www -> apex redirect
|--------------------------------------------------------------------------
| Registered BEFORE the tenant routes below on purpose: in path mode that
| group is no longer domain()-constrained (see the comment there), so this
| still needs to win the match for any www.{central} request before the
| tenant group's URI-only prefix match gets a chance to.
*/
Route::domain('www.'.config('app.central_domain'))->group(function () {
    Route::any('/{any?}', function () {
        return redirect()->away(
            'https://'.config('app.central_domain').request()->getRequestUri()
        );
    })->where('any', '.*');
});

if (config('app.tenancy_mode', 'subdomain') === 'path') {
    // No domain() constraint here. A verified custom-domain request is
    // rewritten to this exact /shop/{slug} shape by ResolveCustomDomain (a
    // global, pre-routing middleware) without touching the request's Host —
    // so it needs to match here on URI alone. The central-domain case (the
    // overwhelming majority of traffic) is unaffected: the /shop/{tenant_slug}
    // prefix already fully disambiguates this group from every other route in
    // this file, so the domain() call was redundant for that case to begin
    // with. The www case above is guarded by registration order, not domain().
    Route::prefix('shop/{tenant_slug}')
        ->where(['tenant_slug' => '[a-z0-9-]+'])
        ->middleware('resolve.tenant')
        ->group($tenantRoutes);
} else {
    Route::middleware('resolve.tenant')->group($tenantRoutes);
}

/*
|--------------------------------------------------------------------------
| PERSONAL TELEGRAM AI ASSISTANT (owner-only, not tied to any tenant/domain)
|--------------------------------------------------------------------------
*/
Route::post('/telegram/webhook/{secret}', [TelegramController::class, 'webhook'])
    ->withoutMiddleware([ValidateCsrfToken::class])
    ->name('telegram.webhook');

Route::get('/telegram/setup', [TelegramController::class, 'setup'])->name('telegram.setup');
Route::get('/telegram/status', [TelegramController::class, 'status'])->name('telegram.status');

/*
|--------------------------------------------------------------------------
| MESSENGER WEBHOOK (single global URL — Meta routes all pages here;
| we look up the owning tenant per-page inside the controller)
|--------------------------------------------------------------------------
*/
Route::get('/webhook/messenger', [MessengerWebhookController::class, 'verify'])->name('messenger.webhook.verify');
Route::post('/webhook/messenger', [MessengerWebhookController::class, 'receive'])
    ->withoutMiddleware([ValidateCsrfToken::class])
    ->name('messenger.webhook.receive');

/*
|--------------------------------------------------------------------------
| WHATSAPP WEBHOOK (single global URL — same "one URL, many tenants" shape
| as the Messenger webhook above; tenant is resolved per-event inside the
| controller via phone_number_id, never from this route)
|--------------------------------------------------------------------------
*/
Route::get('/webhook/whatsapp', [WhatsAppWebhookController::class, 'verify'])->name('whatsapp.webhook.verify');
Route::post('/webhook/whatsapp', [WhatsAppWebhookController::class, 'receive'])
    ->withoutMiddleware([ValidateCsrfToken::class])
    ->name('whatsapp.webhook.receive');

/*
|--------------------------------------------------------------------------
| FACEBOOK OAUTH CALLBACK (single fixed URL registered in the Meta App
| dashboard — cannot be tenant-prefixed, Meta doesn't support templated
| redirect URIs; see FacebookOAuthCallbackController's docblock). A plain
| GET route — no CSRF exemption, ValidateCsrfToken never guards GET.
|--------------------------------------------------------------------------
*/
Route::get('/panel/facebook/callback', [FacebookOAuthCallbackController::class, 'handle'])->name('facebook.callback');
