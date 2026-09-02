<?php

namespace Tests\Feature\WordPress;

use App\Models\Customer;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Warehouse;
use App\Models\WordPressConnection;
use App\Services\WordPress\WordPressConnectorService;
use Illuminate\Support\Facades\Auth;

class WordPressOrderWebhookTest extends WordPressFeatureTestCase
{
    /** @return array{0: WordPressConnection, 1: string} [connection, plaintext Sanctum api_token] */
    protected function connectTenant($tenant, $user): array
    {
        $service = new WordPressConnectorService;
        $state = $service->createConnectionToken($tenant, $user);

        [$connection, $apiToken] = $service->completeHandshake($state, [
            'site_url' => 'https://example-shop.com',
            'wp_rest_url' => 'https://example-shop.com/wp-json',
        ]);

        return [$connection->fresh(), $apiToken];
    }

    protected function makeSellableProduct($tenant, array $overrides = []): ProductVariant
    {
        $product = Product::create(['tenant_id' => $tenant->id, 'name' => 'Test Shirt', 'slug' => 'test-shirt-'.uniqid()]);
        $variant = ProductVariant::create(array_merge([
            'tenant_id' => $tenant->id,
            'product_id' => $product->id,
            'sku' => 'TS-1',
            'selling_price' => 500,
        ], $overrides));

        // One default warehouse per TENANT, not per variant — a second call
        // for the same tenant (multi-line-item orders) must land its stock
        // in the same warehouse lockStockOrFail()'s Warehouse::where('is_default', 1)->first() will pick.
        $warehouse = Warehouse::where('tenant_id', $tenant->id)->where('is_default', 1)->first()
            ?? Warehouse::create(['tenant_id' => $tenant->id, 'name' => 'Main', 'is_default' => true]);
        Inventory::create(['tenant_id' => $tenant->id, 'variant_id' => $variant->id, 'warehouse_id' => $warehouse->id, 'quantity' => 10]);

        return $variant->fresh();
    }

    protected function payload(int $variantId, array $overrides = []): array
    {
        return array_merge([
            'wc_order_id' => 501,
            'status' => 'processing',
            'payment_method' => 'cod',
            'customer' => [
                'name' => 'Rahim Uddin',
                'phone' => '01700000000',
                'address' => 'House 1, Road 2, Dhaka',
            ],
            'totals' => [
                'discount_total' => 0,
                'shipping_total' => 60,
                'fee_total' => 0,
            ],
            'note' => 'Please call before delivery',
            'line_items' => [
                ['metasoft_variant_id' => $variantId, 'quantity' => 2],
            ],
        ], $overrides);
    }

    // --- 1. valid order creates a MetaSoft order ------------------------------------

    public function test_a_valid_webhook_creates_a_metasoft_order(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        [, $apiToken] = $this->connectTenant($tenant, $user);
        $variant = $this->makeSellableProduct($tenant);

        $response = $this->withHeader('Authorization', 'Bearer '.$apiToken)
            ->postJson('/api/wordpress/v1/orders', $this->payload($variant->id));

        $response->assertCreated();
        $response->assertJsonStructure(['order_id', 'order_number', 'status']);

        $order = Order::withoutGlobalScopes()->find($response->json('order_id'));
        $this->assertNotNull($order);
        $this->assertSame($tenant->id, $order->tenant_id);
        $this->assertSame('wordpress', $order->source);
        $this->assertSame('wordpress', $order->channel);
        $this->assertSame(501, $order->wordpress_order_id);
        $this->assertSame('confirmed', $order->status); // 'processing' -> 'confirmed'
    }

    // --- 2/13. idempotency & retry-safety ------------------------------------------

    public function test_a_duplicate_webhook_does_not_create_a_duplicate_order(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        [, $apiToken] = $this->connectTenant($tenant, $user);
        $variant = $this->makeSellableProduct($tenant);

        $first = $this->withHeader('Authorization', 'Bearer '.$apiToken)
            ->postJson('/api/wordpress/v1/orders', $this->payload($variant->id));
        $second = $this->withHeader('Authorization', 'Bearer '.$apiToken)
            ->postJson('/api/wordpress/v1/orders', $this->payload($variant->id));

        $first->assertCreated();
        $second->assertOk(); // 200, not 201 — this was an update, not a create
        $this->assertSame($first->json('order_id'), $second->json('order_id'));

        $this->assertSame(1, Order::withoutGlobalScopes()->where('wordpress_order_id', 501)->count());

        // Stock decremented exactly once, not twice.
        $stock = Inventory::withoutGlobalScopes()->where('variant_id', $variant->id)->sum('quantity');
        $this->assertSame(8, $stock); // 10 - 2, not 10 - 4
    }

    // --- 3/4. tenant resolution & cross-tenant isolation ----------------------------

    public function test_tenant_is_resolved_from_the_authenticated_connection_never_the_payload(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        [, $apiToken] = $this->connectTenant($tenant, $user);
        $variant = $this->makeSellableProduct($tenant);

        // No tenant_id field exists in the validated payload shape at all
        // — nothing to smuggle even if a hostile plugin/site tried.
        $response = $this->withHeader('Authorization', 'Bearer '.$apiToken)
            ->postJson('/api/wordpress/v1/orders', $this->payload($variant->id));

        $order = Order::withoutGlobalScopes()->find($response->json('order_id'));
        $this->assertSame($tenant->id, $order->tenant_id);
    }

    public function test_two_tenants_can_use_the_same_wc_order_id_without_colliding(): void
    {
        $tenantA = $this->makeTenant();
        $userA = $this->makeUser($tenantA->id);
        [, $tokenA] = $this->connectTenant($tenantA, $userA);
        $variantA = $this->makeSellableProduct($tenantA);

        $tenantB = $this->makeTenant();
        $userB = $this->makeUser($tenantB->id);
        [, $tokenB] = $this->connectTenant($tenantB, $userB);
        $variantB = $this->makeSellableProduct($tenantB);

        $respA = $this->withHeader('Authorization', 'Bearer '.$tokenA)
            ->postJson('/api/wordpress/v1/orders', $this->payload($variantA->id, ['wc_order_id' => 999]));

        // Two leftovers from tenant A's request above must be cleared
        // before simulating tenant B's — a real webhook always arrives as
        // its own fresh HTTP process, so neither of these ever survives
        // between two genuine deliveries; only this test's two sequential
        // postJson() calls sharing one container hit them:
        //   - Illuminate\Auth\RequestGuard caches the user it resolved for
        //     the first Bearer token on ->user() (see its "if we've already
        //     retrieved the user... return it back immediately" early
        //     return), which setRequest() alone does not clear —
        //     Auth::forgetGuards() forces a fresh guard for tokenB.
        //   - BindTenantFromWordPressConnection left `currentTenant` bound
        //     to tenant A. Left in place, it would incorrectly scope
        //     WordPressConnection's own BelongsToTenant global scope during
        //     Sanctum's tokenable lookup for tenant B's token, resolving to
        //     null. Same "reset before switching simulated actor" pattern
        //     as app()->forgetInstance('currentTenant') elsewhere in this
        //     suite (e.g. tests/Feature/Api/Mobile/CategoryApiTest.php).
        Auth::forgetGuards();
        app()->forgetInstance('currentTenant');

        $respB = $this->withHeader('Authorization', 'Bearer '.$tokenB)
            ->postJson('/api/wordpress/v1/orders', $this->payload($variantB->id, ['wc_order_id' => 999]));

        $respA->assertCreated();
        $respB->assertCreated();
        $this->assertNotSame($respA->json('order_id'), $respB->json('order_id'));

        $orderA = Order::withoutGlobalScopes()->find($respA->json('order_id'));
        $orderB = Order::withoutGlobalScopes()->find($respB->json('order_id'));
        $this->assertSame($tenantA->id, $orderA->tenant_id);
        $this->assertSame($tenantB->id, $orderB->tenant_id);
    }

    public function test_tenant_a_cannot_see_tenant_bs_wordpress_order(): void
    {
        $tenantA = $this->makeTenant();
        $userA = $this->makeUser($tenantA->id);
        [, $tokenA] = $this->connectTenant($tenantA, $userA);
        $variantA = $this->makeSellableProduct($tenantA);

        $this->withHeader('Authorization', 'Bearer '.$tokenA)
            ->postJson('/api/wordpress/v1/orders', $this->payload($variantA->id, ['wc_order_id' => 777]));

        // BindTenantFromWordPressConnection leaves `currentTenant` bound to
        // tenant A in the container after that request — a real request
        // never continues past its own response, but this test method's
        // container is shared across every call below, so the leftover
        // binding must be cleared before touching tenant B's data (same
        // pattern as app()->forgetInstance('currentTenant') elsewhere in
        // this suite). See the sibling "two tenants" test for why
        // Auth::forgetGuards() is also needed before the second webhook
        // call.
        app()->forgetInstance('currentTenant');

        $tenantB = $this->makeTenant();
        $userB = $this->makeUser($tenantB->id);
        [, $tokenB] = $this->connectTenant($tenantB, $userB);
        $variantB = $this->makeSellableProduct($tenantB);

        Auth::forgetGuards();

        // Tenant B's own token/connection resolves its own scope only —
        // posting the same wc_order_id creates ITS OWN order, never
        // touches or returns tenant A's row.
        $response = $this->withHeader('Authorization', 'Bearer '.$tokenB)
            ->postJson('/api/wordpress/v1/orders', $this->payload($variantB->id, ['wc_order_id' => 777]));

        $order = Order::withoutGlobalScopes()->find($response->json('order_id'));
        $this->assertSame($tenantB->id, $order->tenant_id);
    }

    // --- 5/6. product & variant mapping ----------------------------------------------

    public function test_multiple_line_items_resolve_to_their_own_variants(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        [, $apiToken] = $this->connectTenant($tenant, $user);
        $variant1 = $this->makeSellableProduct($tenant, ['sku' => 'V-1', 'selling_price' => 300]);
        $variant2 = $this->makeSellableProduct($tenant, ['sku' => 'V-2', 'selling_price' => 700]);

        $response = $this->withHeader('Authorization', 'Bearer '.$apiToken)
            ->postJson('/api/wordpress/v1/orders', $this->payload($variant1->id, [
                'line_items' => [
                    ['metasoft_variant_id' => $variant1->id, 'quantity' => 1],
                    ['metasoft_variant_id' => $variant2->id, 'quantity' => 2],
                ],
            ]));

        $response->assertCreated();
        $order = Order::withoutGlobalScopes()->find($response->json('order_id'));
        $skus = $order->items()->pluck('sku')->sort()->values()->all();
        $this->assertSame(['V-1', 'V-2'], $skus);

        // subtotal computed from LIVE MetaSoftSAS prices: 300*1 + 700*2 = 1700
        $this->assertEquals(1700, $order->subtotal);
    }

    // --- 7. unknown product fails safely ----------------------------------------------

    public function test_an_unresolvable_variant_rejects_the_whole_order(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        [, $apiToken] = $this->connectTenant($tenant, $user);
        $variant = $this->makeSellableProduct($tenant);

        $response = $this->withHeader('Authorization', 'Bearer '.$apiToken)
            ->postJson('/api/wordpress/v1/orders', $this->payload($variant->id, [
                'line_items' => [
                    ['metasoft_variant_id' => $variant->id, 'quantity' => 1],
                    ['metasoft_variant_id' => 999999, 'quantity' => 1], // never synced from MetaSoftSAS
                ],
            ]));

        $response->assertStatus(422);
        $response->assertJson(['reason' => 'unresolvable_product']);
        $this->assertSame(0, Order::withoutGlobalScopes()->where('wordpress_order_id', 501)->count());
    }

    // --- 8. stock validation prevents overselling ---------------------------------------

    public function test_ordering_more_than_available_stock_is_rejected(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        [, $apiToken] = $this->connectTenant($tenant, $user);
        $variant = $this->makeSellableProduct($tenant); // 10 in stock

        $response = $this->withHeader('Authorization', 'Bearer '.$apiToken)
            ->postJson('/api/wordpress/v1/orders', $this->payload($variant->id, [
                'line_items' => [['metasoft_variant_id' => $variant->id, 'quantity' => 50]],
            ]));

        $response->assertStatus(409);
        $response->assertJson(['reason' => 'insufficient_stock']);
        $this->assertSame(0, Order::withoutGlobalScopes()->where('wordpress_order_id', 501)->count());

        // Stock must be untouched — the failed attempt never decremented anything.
        $this->assertSame(10, Inventory::withoutGlobalScopes()->where('variant_id', $variant->id)->sum('quantity'));
    }

    // --- 9. customer mapping -----------------------------------------------------------

    public function test_repeat_orders_from_the_same_phone_reuse_the_same_customer(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        [, $apiToken] = $this->connectTenant($tenant, $user);
        $variant = $this->makeSellableProduct($tenant, ['sku' => 'REPEAT-1']);

        $this->withHeader('Authorization', 'Bearer '.$apiToken)
            ->postJson('/api/wordpress/v1/orders', $this->payload($variant->id, ['wc_order_id' => 601]));
        $this->withHeader('Authorization', 'Bearer '.$apiToken)
            ->postJson('/api/wordpress/v1/orders', $this->payload($variant->id, ['wc_order_id' => 602]));

        $this->assertSame(1, Customer::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('phone', '01700000000')->count());

        $customer = Customer::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('phone', '01700000000')->first();
        $this->assertSame(2, $customer->total_orders);
    }

    // --- 10. pricing security ------------------------------------------------------------

    public function test_subtotal_is_always_computed_from_live_metasoft_prices(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        [, $apiToken] = $this->connectTenant($tenant, $user);
        $variant = $this->makeSellableProduct($tenant, ['selling_price' => 500]);

        $response = $this->withHeader('Authorization', 'Bearer '.$apiToken)
            ->postJson('/api/wordpress/v1/orders', $this->payload($variant->id)); // qty=2

        $order = Order::withoutGlobalScopes()->find($response->json('order_id'));
        $this->assertEquals(1000, $order->subtotal); // 500 * 2, from DB — the payload never even carries a price
    }

    public function test_a_discount_larger_than_subtotal_is_clamped_not_trusted(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        [, $apiToken] = $this->connectTenant($tenant, $user);
        $variant = $this->makeSellableProduct($tenant, ['selling_price' => 500]); // subtotal will be 1000 (qty 2)

        $response = $this->withHeader('Authorization', 'Bearer '.$apiToken)
            ->postJson('/api/wordpress/v1/orders', $this->payload($variant->id, [
                'totals' => ['discount_total' => 999999, 'shipping_total' => 0, 'fee_total' => 0],
            ]));

        $order = Order::withoutGlobalScopes()->find($response->json('order_id'));
        $this->assertEquals(1000, $order->discount); // clamped to subtotal, never negative total
        $this->assertEquals(0, $order->total); // 1000 - 1000 + 0 + 0
    }

    // --- 11. status mapping on creation ---------------------------------------------------

    public function test_completed_woocommerce_status_maps_to_delivered(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        [, $apiToken] = $this->connectTenant($tenant, $user);
        $variant = $this->makeSellableProduct($tenant);

        $response = $this->withHeader('Authorization', 'Bearer '.$apiToken)
            ->postJson('/api/wordpress/v1/orders', $this->payload($variant->id, ['status' => 'completed']));

        $order = Order::withoutGlobalScopes()->find($response->json('order_id'));
        $this->assertSame('delivered', $order->status);
        $this->assertNotNull($order->delivered_at);
    }

    public function test_a_later_status_changed_webhook_updates_the_existing_order_without_touching_items_again(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        [, $apiToken] = $this->connectTenant($tenant, $user);
        $variant = $this->makeSellableProduct($tenant);

        $created = $this->withHeader('Authorization', 'Bearer '.$apiToken)
            ->postJson('/api/wordpress/v1/orders', $this->payload($variant->id, ['status' => 'processing']));

        $updated = $this->withHeader('Authorization', 'Bearer '.$apiToken)
            ->postJson('/api/wordpress/v1/orders', $this->payload($variant->id, ['status' => 'completed']));

        $updated->assertOk();
        $this->assertSame($created->json('order_id'), $updated->json('order_id'));

        $order = Order::withoutGlobalScopes()->find($updated->json('order_id'));
        $this->assertSame('delivered', $order->status);
        $this->assertSame(1, $order->items()->count()); // never re-attached

        // Stock only ever decremented on the original creation.
        $this->assertSame(8, Inventory::withoutGlobalScopes()->where('variant_id', $variant->id)->sum('quantity'));
    }

    // --- 12. webhook authentication -----------------------------------------------------

    public function test_a_request_without_a_token_is_rejected(): void
    {
        $tenant = $this->makeTenant();
        $variant = $this->makeSellableProduct($tenant);

        $this->postJson('/api/wordpress/v1/orders', $this->payload($variant->id))->assertStatus(401);
    }

    public function test_a_disconnected_connections_token_is_rejected(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        [$connection, $apiToken] = $this->connectTenant($tenant, $user);
        $variant = $this->makeSellableProduct($tenant);

        (new WordPressConnectorService)->disconnect($connection);

        $this->withHeader('Authorization', 'Bearer '.$apiToken)
            ->postJson('/api/wordpress/v1/orders', $this->payload($variant->id))
            ->assertStatus(401);
    }
}
