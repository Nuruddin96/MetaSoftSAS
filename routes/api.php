<?php

use App\Http\Controllers\Api\Mobile\AdvertisingController;
use App\Http\Controllers\Api\Mobile\AiChatController;
use App\Http\Controllers\Api\Mobile\AuthController;
use App\Http\Controllers\Api\Mobile\BillingController;
use App\Http\Controllers\Api\Mobile\CategoryController;
use App\Http\Controllers\Api\Mobile\CustomerController;
use App\Http\Controllers\Api\Mobile\DashboardController;
use App\Http\Controllers\Api\Mobile\DeviceController;
use App\Http\Controllers\Api\Mobile\ExpenseController;
use App\Http\Controllers\Api\Mobile\IncompleteOrderController;
use App\Http\Controllers\Api\Mobile\InventoryController;
use App\Http\Controllers\Api\Mobile\MessengerController;
use App\Http\Controllers\Api\Mobile\NotificationController;
use App\Http\Controllers\Api\Mobile\OnboardingController;
use App\Http\Controllers\Api\Mobile\OrderController;
use App\Http\Controllers\Api\Mobile\PosController;
use App\Http\Controllers\Api\Mobile\ProductCatalogController;
use App\Http\Controllers\Api\Mobile\ProductController;
use App\Http\Controllers\Api\Mobile\ProductSourceController;
use App\Http\Controllers\Api\Mobile\ReferenceDataController;
use App\Http\Controllers\Api\Mobile\ReportController;
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

    Route::middleware(['auth:sanctum', 'bind.tenant.token'])->group(function () {
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::post('auth/logout', [AuthController::class, 'logout']);

        Route::get('dashboard', [DashboardController::class, 'index']);

        Route::get('orders', [OrderController::class, 'index']);
        Route::post('orders', [OrderController::class, 'store']);
        Route::get('orders/{order}', [OrderController::class, 'show'])->whereNumber('order');
        Route::patch('orders/{order}/status', [OrderController::class, 'updateStatus'])->whereNumber('order');
        Route::post('orders/{order}/courier', [OrderController::class, 'courier'])->whereNumber('order');
        Route::post('orders/{order}/courier/refresh', [OrderController::class, 'refreshCourierStatus'])->whereNumber('order');

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

        // Categories — mirrors Tenant\CategoryController's real capability
        // exactly (list/create/delete only, see CategoryController's
        // docblock). Consumed by the Product Form's category picker too.
        Route::get('categories', [CategoryController::class, 'index']);
        Route::post('categories', [CategoryController::class, 'store']);
        Route::delete('categories/{category}', [CategoryController::class, 'destroy'])->whereNumber('category');

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

        // Messenger — mirrors Tenant\MessengerInboxController's real
        // capability (list via the same UnifiedInboxService the web
        // unified inbox uses, show, reply [text-only, see
        // MessengerController's docblock], status, resume-ai).
        Route::get('messenger/conversations', [MessengerController::class, 'index']);
        Route::get('messenger/{psid}', [MessengerController::class, 'show']);
        Route::post('messenger/{psid}/reply', [MessengerController::class, 'reply']);
        Route::patch('messenger/{psid}/status', [MessengerController::class, 'updateStatus']);
        Route::post('messenger/{psid}/resume-ai', [MessengerController::class, 'resumeAi']);

        // WhatsApp — mirrors Tenant\WhatsAppInboxController's real
        // capability, same shape as Messenger above.
        Route::get('whatsapp/conversations', [WhatsAppController::class, 'index']);
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
