<?php

use App\Http\Controllers\Api\Mobile\AdvertisingController;
use App\Http\Controllers\Api\Mobile\AiChatController;
use App\Http\Controllers\Api\Mobile\AiMemoryController;
use App\Http\Controllers\Api\Mobile\AuthController;
use App\Http\Controllers\Api\Mobile\BannerController;
use App\Http\Controllers\Api\Mobile\BillingController;
use App\Http\Controllers\Api\Mobile\CategoryController;
use App\Http\Controllers\Api\Mobile\CustomerController;
use App\Http\Controllers\Api\Mobile\DashboardController;
use App\Http\Controllers\Api\Mobile\DeviceController;
use App\Http\Controllers\Api\Mobile\ExpenseController;
use App\Http\Controllers\Api\Mobile\FacebookConnectController;
use App\Http\Controllers\Api\Mobile\FraudCheckController;
use App\Http\Controllers\Api\Mobile\IncompleteOrderController;
use App\Http\Controllers\Api\Mobile\InventoryController;
use App\Http\Controllers\Api\Mobile\LandingPageController;
use App\Http\Controllers\Api\Mobile\MessengerController;
use App\Http\Controllers\Api\Mobile\NotificationController;
use App\Http\Controllers\Api\Mobile\OnboardingController;
use App\Http\Controllers\Api\Mobile\OrderController;
use App\Http\Controllers\Api\Mobile\PageController;
use App\Http\Controllers\Api\Mobile\PosController;
use App\Http\Controllers\Api\Mobile\ProductAttributeController;
use App\Http\Controllers\Api\Mobile\ProductCatalogController;
use App\Http\Controllers\Api\Mobile\ProductController;
use App\Http\Controllers\Api\Mobile\ProductSourceController;
use App\Http\Controllers\Api\Mobile\ReferenceDataController;
use App\Http\Controllers\Api\Mobile\ReportController;
use App\Http\Controllers\Api\Mobile\ReviewController;
use App\Http\Controllers\Api\Mobile\SettingController;
use App\Http\Controllers\Api\Mobile\SignalController;
use App\Http\Controllers\Api\Mobile\WhatsAppController;
use Illuminate\Support\Facades\Route;

/**
 * Mobile Business App API — entirely new surface, does not touch/alter
 * anything in routes/web.php. Stateless (Sanctum personal access tokens,
 * no session middleware), so tenant can't be derived from the URL the way
 * the web panel does — see BindTenantFromSanctumUser's docblock.
 */
Route::prefix('mobile/v1')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login']);
    Route::post('auth/register', [AuthController::class, 'register']);

    Route::middleware(['auth:sanctum', 'bind.tenant.token', 'check.subscription.mobile'])->group(function () {
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::post('auth/logout', [AuthController::class, 'logout']);

        Route::get('dashboard', [DashboardController::class, 'index']);

        Route::get('orders', [OrderController::class, 'index']);
        Route::post('orders', [OrderController::class, 'store']);
        Route::get('orders/{order}', [OrderController::class, 'show'])->whereNumber('order');
        Route::post('orders/{order}/complete', [OrderController::class, 'complete'])->whereNumber('order');
        Route::patch('orders/{order}/status', [OrderController::class, 'updateStatus'])->whereNumber('order');
        Route::patch('orders/{order}/channel', [OrderController::class, 'updateChannel'])->whereNumber('order');
        Route::post('orders/{order}/courier', [OrderController::class, 'courier'])->whereNumber('order');
        Route::post('orders/{order}/courier/refresh', [OrderController::class, 'refreshCourierStatus'])->whereNumber('order');

        // Mirrors Tenant\FraudCheckController::check() — same FraudChecker
        // service, same "documented duplication over refactor risk"
        // convention MessengerController/OrderCreationService already use
        // for this API surface. Web only surfaces this on the order-details
        // page (checked by customer_phone, not order id), so this is a
        // phone lookup, not an order sub-resource route.
        Route::post('fraud-check', [FraudCheckController::class, 'check']);

        Route::get('incomplete-orders', [IncompleteOrderController::class, 'index']);
        Route::patch('incomplete-orders/{incompleteOrder}/status', [IncompleteOrderController::class, 'updateStatus'])
            ->whereNumber('incompleteOrder');

        Route::get('customers', [CustomerController::class, 'index']);
        Route::post('customers', [CustomerController::class, 'store']);
        Route::get('customers/{customer}', [CustomerController::class, 'show'])->whereNumber('customer');
        Route::post('customers/{customer}/due', [CustomerController::class, 'due'])->whereNumber('customer');
        Route::post('customers/{customer}/due/add', [CustomerController::class, 'addDue'])->whereNumber('customer');

        Route::get('reference/divisions', [ReferenceDataController::class, 'divisions']);
        Route::get('reference/districts', [ReferenceDataController::class, 'districts']);
        Route::get('reference/upazilas', [ReferenceDataController::class, 'upazilas']);

        // Delivery-charge + brand settings — see SettingController's
        // docblock for why the rest of Tenant\SettingController's surface
        // (AI-agent toggles, marketing pixel) isn't mirrored here.
        Route::get('settings', [SettingController::class, 'index']);
        Route::post('settings', [SettingController::class, 'store']);
        Route::get('settings/brand', [SettingController::class, 'brand']);
        Route::post('settings/brand', [SettingController::class, 'updateBrand']);

        // Courier (Steadfast/Pathao) credential connect — mirrors
        // Tenant\SettingController::courier() exactly. GET is masked
        // (never returns a decrypted secret), see SettingController's
        // courier() docblock.
        Route::get('settings/courier', [SettingController::class, 'courier']);
        Route::post('settings/courier', [SettingController::class, 'updateCourier']);

        // Marketing Pixel/CAPI/GTM — mirrors Tenant\SettingController::
        // marketing()/testCapiConnection() exactly, only the 5 real fields
        // (see SettingController::marketing()'s docblock for the 4 unused
        // fields deliberately omitted). GET is masked (never returns a
        // decrypted fb_capi_token).
        Route::get('settings/marketing', [SettingController::class, 'marketing']);
        Route::post('settings/marketing', [SettingController::class, 'updateMarketing']);
        Route::post('settings/marketing/test-capi', [SettingController::class, 'testMarketingCapi']);

        // Website builder remainder — homepage/footer/domain — mirrors
        // Tenant\WebsiteController::homepage()/footer() and
        // Tenant\SettingController::requestDomain()/cancelDomainRequest()
        // exactly. Banners/pages/reviews/brand already have their own
        // dedicated routes above/below; theme is deliberately not mirrored
        // (see SettingController's class docblock).
        Route::get('settings/homepage', [SettingController::class, 'homepage']);
        Route::post('settings/homepage', [SettingController::class, 'updateHomepage']);
        Route::get('settings/footer', [SettingController::class, 'footer']);
        Route::post('settings/footer', [SettingController::class, 'updateFooter']);
        Route::get('settings/domain', [SettingController::class, 'domain']);
        Route::post('settings/domain', [SettingController::class, 'requestDomain']);
        Route::delete('settings/domain', [SettingController::class, 'cancelDomain']);

        // Facebook Connect (OAuth) — mirrors Tenant\FacebookConnectController
        // exactly, see Api\Mobile\FacebookConnectController's docblock for
        // why this returns JSON instead of redirecting. The actual OAuth
        // code exchange still happens at the existing, unchanged central
        // callback route (routes/web.php's facebook.callback) — never
        // duplicated here.
        Route::get('settings/facebook/connect-url', [FacebookConnectController::class, 'connectUrl']);
        Route::get('settings/facebook/status', [FacebookConnectController::class, 'status']);
        Route::get('settings/facebook/pages', [FacebookConnectController::class, 'pages']);
        Route::post('settings/facebook/pages/{pageId}/connect', [FacebookConnectController::class, 'connect']);
        Route::post('settings/facebook/pages/{page}/disconnect', [FacebookConnectController::class, 'disconnect'])->whereNumber('page');

        // Storefront banners only — mirrors Tenant\WebsiteController's
        // banner slice (storeBanner/destroyBanner). The rest of that
        // controller's surface (homepage/footer text) isn't mirrored
        // here, see BannerController's docblock.
        Route::get('settings/banners', [BannerController::class, 'index']);
        Route::post('settings/banners', [BannerController::class, 'store']);
        Route::delete('settings/banners/{banner}', [BannerController::class, 'destroy'])->whereNumber('banner');

        // Storefront reviews (merchant-curated testimonials) only — mirrors
        // Tenant\WebsiteController's review slice, see ReviewController's
        // docblock. update is POST, not PUT/PATCH: PHP only populates
        // $_FILES for multipart bodies on a literal POST request — Blade's
        // own @method('PUT') spoofing works because the wire method stays
        // POST, but a mobile client's real PUT/PATCH would not (same
        // reasoning as ProductCatalogController::update()'s docblock).
        Route::get('settings/reviews', [ReviewController::class, 'index']);
        Route::post('settings/reviews', [ReviewController::class, 'store']);
        Route::post('settings/reviews/{review}', [ReviewController::class, 'update'])->whereNumber('review');
        Route::delete('settings/reviews/{review}', [ReviewController::class, 'destroy'])->whereNumber('review');

        // "AI মেমোরী" (Teach Your AI Agent) Q&A CRUD — mirrors
        // Tenant\AiMemoryController exactly, see AiMemoryController's
        // docblock. update is POST, not PUT/PATCH, same multipart/$_FILES
        // reasoning as the reviews routes above.
        Route::get('ai-memory', [AiMemoryController::class, 'index']);
        Route::post('ai-memory', [AiMemoryController::class, 'store']);
        Route::post('ai-memory/{aiMemory}', [AiMemoryController::class, 'update'])->whereNumber('aiMemory');
        Route::delete('ai-memory/{aiMemory}', [AiMemoryController::class, 'destroy'])->whereNumber('aiMemory');

        // Storefront custom pages only — mirrors Tenant\WebsiteController's
        // page slice (storePage/updatePage/destroyPage), see PageController's
        // docblock.
        Route::get('settings/pages', [PageController::class, 'index']);
        Route::post('settings/pages', [PageController::class, 'store']);
        Route::patch('settings/pages/{page}', [PageController::class, 'update'])->whereNumber('page');
        Route::delete('settings/pages/{page}', [PageController::class, 'destroy'])->whereNumber('page');

        // Single Product Landing Page Builder — mobile counterpart of the
        // web panel's Tenant\LandingPageController (Phase 2, already live),
        // same LandingPage model/SectionDataService underneath. Ordering
        // itself stays web-only (see LandingPageController's docblock).
        Route::get('landing-pages', [LandingPageController::class, 'index']);
        Route::post('landing-pages', [LandingPageController::class, 'store']);
        Route::get('landing-pages/{landingPage}', [LandingPageController::class, 'show'])->whereNumber('landingPage');
        Route::patch('landing-pages/{landingPage}', [LandingPageController::class, 'update'])->whereNumber('landingPage');
        Route::delete('landing-pages/{landingPage}', [LandingPageController::class, 'destroy'])->whereNumber('landingPage');
        Route::post('landing-pages/{landingPage}/publish', [LandingPageController::class, 'publish'])->whereNumber('landingPage');
        Route::post('landing-pages/{landingPage}/unpublish', [LandingPageController::class, 'unpublish'])->whereNumber('landingPage');

        Route::post('landing-pages/{landingPage}/sections', [LandingPageController::class, 'addSection'])->whereNumber('landingPage');
        Route::post('landing-pages/{landingPage}/sections/reorder', [LandingPageController::class, 'reorderSections'])->whereNumber('landingPage');
        Route::post('landing-pages/{landingPage}/sections/{sectionId}', [LandingPageController::class, 'updateSection'])->whereNumber('landingPage');
        Route::delete('landing-pages/{landingPage}/sections/{sectionId}', [LandingPageController::class, 'destroySection'])->whereNumber('landingPage');
        Route::post('landing-pages/{landingPage}/sections/{sectionId}/duplicate', [LandingPageController::class, 'duplicateSection'])->whereNumber('landingPage');

        // Tenant Onboarding Wizard — mobile counterpart of the web wizard
        // (routes/web.php's tenant.onboarding.* group), same
        // TenantOnboardingService underneath. See Api\Mobile\
        // OnboardingController's docblock for why categories add/remove has
        // no endpoint here (reuses the existing categories endpoints below).
        Route::get('onboarding', [OnboardingController::class, 'status']);
        Route::post('onboarding/business-type', [OnboardingController::class, 'storeBusinessType']);
        Route::post('onboarding/business-info', [OnboardingController::class, 'storeBusinessInfo']);
        Route::post('onboarding/categories/continue', [OnboardingController::class, 'continueCategories']);
        Route::post('onboarding/store-settings', [OnboardingController::class, 'storeStoreSettings']);
        Route::post('onboarding/first-product', [OnboardingController::class, 'storeFirstProduct']);
        Route::post('onboarding/first-product/skip', [OnboardingController::class, 'skipFirstProduct']);
        Route::post('onboarding/describe-image', [OnboardingController::class, 'describeImage']);
        Route::post('onboarding/complete', [OnboardingController::class, 'complete']);

        Route::get('products', [ProductController::class, 'index']);

        // Real Products feature (list/detail/create/edit) — see
        // ProductCatalogController's docblock for why this is a separate
        // controller/path from the bare 'products' route above, and why
        // update is POST rather than PATCH.
        Route::get('product-catalog', [ProductCatalogController::class, 'index']);
        Route::get('product-catalog/{product}', [ProductCatalogController::class, 'show'])->whereNumber('product');
        Route::post('product-catalog', [ProductCatalogController::class, 'store']);
        Route::post('product-catalog/{product}', [ProductCatalogController::class, 'update'])->whereNumber('product');
        // Whole-product delete — mirrors Tenant\ProductController::destroy().
        Route::delete('product-catalog/{product}', [ProductCatalogController::class, 'destroy'])->whereNumber('product');
        // New, additive — single-variant CRUD independent of the whole-
        // product update above (see ProductCatalogController's docblock).
        Route::post('product-catalog/{product}/variants', [ProductCatalogController::class, 'storeVariant'])->whereNumber('product');
        Route::patch('product-catalog/{product}/variants/{variant}', [ProductCatalogController::class, 'updateVariant'])->whereNumber('product')->whereNumber('variant');
        Route::delete('product-catalog/{product}/variants/{variant}', [ProductCatalogController::class, 'destroyVariant'])->whereNumber('product')->whereNumber('variant');
        // Web/Flutter parity project — gallery images (POST accepts
        // multipart images[], up to 8 total).
        Route::post('product-catalog/{product}/images', [ProductCatalogController::class, 'storeImages'])->whereNumber('product');
        Route::delete('product-catalog/{product}/images/{image}', [ProductCatalogController::class, 'destroyImage'])->whereNumber('product')->whereNumber('image');
        Route::post('product-catalog/{product}/images/reorder', [ProductCatalogController::class, 'reorderImages'])->whereNumber('product');

        // Categories — mirrors Tenant\CategoryController's real capability
        // (list/create/update/delete, see CategoryController's docblock;
        // `update` is new — the web/mobile backend never had one before).
        // Consumed by the Product Form's category picker too.
        Route::get('categories', [CategoryController::class, 'index']);
        Route::post('categories', [CategoryController::class, 'store']);
        Route::patch('categories/{category}', [CategoryController::class, 'update'])->whereNumber('category');
        Route::delete('categories/{category}', [CategoryController::class, 'destroy'])->whereNumber('category');

        // Product Attributes — new, additive vocabulary layer (Color/Size/
        // Storage + their values) a tenant can reuse when building variants.
        // Does not touch product_variants.attributes JSON — see
        // ProductAttributeController's docblock.
        Route::get('attributes', [ProductAttributeController::class, 'index']);
        Route::post('attributes', [ProductAttributeController::class, 'store']);
        Route::patch('attributes/{attribute}', [ProductAttributeController::class, 'update'])->whereNumber('attribute');
        Route::delete('attributes/{attribute}', [ProductAttributeController::class, 'destroy'])->whereNumber('attribute');
        Route::post('attributes/{attribute}/values', [ProductAttributeController::class, 'addValue'])->whereNumber('attribute');
        Route::patch('attribute-values/{value}', [ProductAttributeController::class, 'updateValue'])->whereNumber('value');
        Route::delete('attribute-values/{value}', [ProductAttributeController::class, 'destroyValue'])->whereNumber('value');

        // Inventory — mirrors Tenant\InventoryController's real capability
        // (index/adjust; low-stock folded into index via ?low_stock=1, see
        // InventoryController's docblock).
        Route::get('inventory', [InventoryController::class, 'index']);
        Route::post('inventory/adjust', [InventoryController::class, 'adjust']);

        // Product Source — mirrors Tenant\ProductSourceController's real
        // capability exactly (browse the platform-wide sourcing catalog,
        // place an order against it, view own placed orders). See
        // ProductSourceController's docblock.
        Route::get('product-source/catalog', [ProductSourceController::class, 'index']);
        Route::get('product-source/catalog/{sourceProduct}', [ProductSourceController::class, 'show'])->whereNumber('sourceProduct');
        Route::post('product-source/catalog/{sourceProduct}/order', [ProductSourceController::class, 'order'])->whereNumber('sourceProduct');
        Route::get('product-source/orders', [ProductSourceController::class, 'myOrders']);

        // Notifications — the real, durable per-user NotificationLog table
        // (written by WebPushService::sendToUser() on real business
        // events), not a fabricated schema. See NotificationController's
        // docblock for why this is a different mechanism from the web
        // panel's session-based bell.
        Route::get('notifications', [NotificationController::class, 'index']);
        Route::post('notifications/seen', [NotificationController::class, 'markSeen']);

        // Per-category notification on/off — mirrors
        // Tenant\NotificationPreferenceController exactly (edit/update),
        // see NotificationController::preferences()'s docblock.
        Route::get('notifications/preferences', [NotificationController::class, 'preferences']);
        Route::post('notifications/preferences', [NotificationController::class, 'updatePreferences']);

        // Messenger — mirrors Tenant\MessengerInboxController's real
        // capability (list via the same UnifiedInboxService the web
        // unified inbox uses, show, reply [text/image/audio, see
        // MessengerController's docblock], status, resume-ai).
        Route::get('messenger/conversations', [MessengerController::class, 'index']);
        Route::get('messenger/{psid}', [MessengerController::class, 'show']);
        Route::post('messenger/{psid}/reply', [MessengerController::class, 'reply']);
        Route::patch('messenger/{psid}/status', [MessengerController::class, 'updateStatus']);
        Route::post('messenger/{psid}/resume-ai', [MessengerController::class, 'resumeAi']);

        // WhatsApp — mirrors Tenant\WhatsAppInboxController's real
        // capability, same shape as Messenger above.
        Route::get('whatsapp/conversations', [WhatsAppController::class, 'index']);
        // Two segments ('whatsapp/media/{id}'), so this never collides with
        // 'whatsapp/{waId}' below — same reasoning as the web route's own
        // comment (routes/web.php).
        Route::get('whatsapp/media/{id}', [WhatsAppController::class, 'media'])->whereNumber('id');
        Route::get('whatsapp/{waId}', [WhatsAppController::class, 'show']);
        Route::post('whatsapp/{waId}/reply', [WhatsAppController::class, 'reply']);
        Route::patch('whatsapp/{waId}/status', [WhatsAppController::class, 'updateStatus']);
        Route::post('whatsapp/{waId}/resume-ai', [WhatsAppController::class, 'resumeAi']);

        // AI Assistant — mirrors Tenant\AiChatController's real capability
        // (the staff tool-calling panel chat), reusing AiChatService/
        // AiPendingActionService unmodified. Genuinely separate system from
        // the customer-facing Messenger/WhatsApp auto-reply agent — see
        // AiChatController's docblock.
        Route::get('ai-chat/messages', [AiChatController::class, 'index']);
        Route::post('ai-chat/messages', [AiChatController::class, 'send']);
        Route::post('ai-chat/actions/{pendingAction}/confirm', [AiChatController::class, 'confirm'])->whereNumber('pendingAction');
        Route::post('ai-chat/actions/{pendingAction}/reject', [AiChatController::class, 'reject'])->whereNumber('pendingAction');

        // POS — mirrors Tenant\PosController's real capability exactly
        // (barcode/SKU scan, complete a sale). Same plan gate.
        Route::get('pos/scan/{code}', [PosController::class, 'scan']);
        Route::post('pos/sell', [PosController::class, 'sell']);

        // Expenses — mirrors Tenant\ExpenseController's real capability
        // exactly (date-range list + total, create, delete).
        Route::get('expenses', [ExpenseController::class, 'index']);
        Route::post('expenses', [ExpenseController::class, 'store']);
        Route::delete('expenses/{expense}', [ExpenseController::class, 'destroy'])->whereNumber('expense');

        // Reports — mirrors Tenant\ReportController's real capability
        // exactly (date-range sales/profit-loss/locations/top-products),
        // returning JSON instead of a Blade view.
        Route::get('reports/sales', [ReportController::class, 'sales']);
        Route::get('reports/profit-loss', [ReportController::class, 'profitLoss']);
        Route::get('reports/locations', [ReportController::class, 'locations']);
        Route::get('reports/products', [ReportController::class, 'products']);

        // Billing — mirrors Tenant\BillingController::index()'s real
        // capability (plan/subscription status, usage, plan comparison,
        // payment history); read-only, see BillingController's docblock
        // for why pay()/callback() aren't mirrored here.
        Route::get('billing', [BillingController::class, 'index']);

        // Advertising — mirrors Tenant\AdvertisingController's real
        // capability exactly (the Ad Billing wallet: balance/daily-budget/
        // billing-rate + payment/charge ledger). Read-only, gated by the
        // same AdvertisingBalanceService::isEnabled() check — see
        // AdvertisingController's docblock for why this is not a Meta Ads
        // Manager campaign integration.
        Route::get('advertising', [AdvertisingController::class, 'overview']);
        Route::get('advertising/ledger', [AdvertisingController::class, 'ledger']);

        // Remote Support: device registration/status only — runs under the
        // user's own login token, same as everything else in this group
        // (bind.tenant.token). Never reachable from any tenant-facing nav;
        // see docs/remote-support-architecture.md §Tenant-facing visibility
        // and RemoteSupportController's docblock on the Flutter side.
        Route::post('devices/register', [DeviceController::class, 'register']);
        Route::get('devices/status', [DeviceController::class, 'status']);
    });

    // Device-credential routes — a SEPARATE, long-lived token from the
    // user's login token above (see MobileDevice's docblock), gated by
    // Sanctum ability rather than bind.tenant.token so heartbeats keep
    // working after the human logs out of the app on that phone.
    Route::middleware(['auth:sanctum', 'ability:device:heartbeat'])->post('devices/heartbeat', [DeviceController::class, 'heartbeat']);

    Route::middleware(['auth:sanctum', 'ability:device:signal'])->group(function () {
        Route::post('devices/sessions/{sessionToken}/signal', [SignalController::class, 'send']);
        Route::get('devices/sessions/{sessionToken}/signal', [SignalController::class, 'poll']);
    });
});
