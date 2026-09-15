<?php

namespace Tests\Feature\Messenger;

use App\Models\Customer;
use App\Models\Inventory;
use App\Models\MarketingSetting;
use App\Models\Order;
use App\Models\Tenant;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithCommerceSchema;
use Tests\TestCase;

/**
 * Covers OrderController::complete() — the operator-facing step that
 * attaches product/variant/qty/price to an Order auto-created from
 * Messenger (status=pending, no items yet) and flips it to confirmed,
 * reusing the exact item-attach/inventory-decrement logic store() uses
 * for a brand new manual order (see OrderController::attachItems()).
 */
class OrderCompleteTest extends TestCase
{
    use InteractsWithCommerceSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCommerceSchema();
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    protected function panelUrl(Tenant $tenant, string $path): string
    {
        return '/shop/'.$tenant->subdomain.'/panel/'.$path;
    }

    protected function makePendingMessengerOrder(Tenant $tenant): Order
    {
        app()->instance('currentTenant', $tenant);

        $customer = Customer::create([
            'tenant_id' => $tenant->id,
            'name' => 'Rahim',
            'phone' => '01712345678',
        ]);

        return Order::create([
            'tenant_id' => $tenant->id,
            'source' => 'messenger',
            'channel' => 'facebook',
            'messenger_psid' => 'psid-1',
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'customer_phone' => $customer->phone,
            'status' => 'pending',
            'subtotal' => 0,
            'total' => 0,
        ]);
    }

    public function test_completing_a_pending_order_attaches_items_decrements_inventory_and_confirms(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $order = $this->makePendingMessengerOrder($tenant);
        $variant = $this->makeSellableVariant($tenant->id, ['selling_price' => 800]);

        $response = $this->actingAs($user, 'tenant')->post($this->panelUrl($tenant, 'orders/'.$order->id.'/complete'), [
            'payment_method' => 'cod',
            'discount' => 0,
            'variant_ids' => [$variant->id],
            'quantities' => [2],
        ]);

        $response->assertRedirect();

        $order->refresh();
        $this->assertSame('confirmed', $order->status);
        $this->assertNotNull($order->confirmed_at);
        $this->assertEquals(1600, (float) $order->subtotal);
        // No division_id on this order at all — DeliveryChargeService
        // treats that as "outside Dhaka" (its own documented default),
        // same as an unconfigured store_settings row falling back to 120.
        $this->assertEquals(120, (float) $order->delivery_charge);
        $this->assertEquals(1720, (float) $order->total);
        $this->assertSame(1, $order->items()->count());

        $inventory = Inventory::where('variant_id', $variant->id)->first();
        $this->assertSame(98, $inventory->quantity, 'stock must decrement by the ordered quantity exactly once');

        $customer = Customer::find($order->customer_id);
        $this->assertSame(1, $customer->total_orders);
    }

    /**
     * Regression test for this sprint's requirement: the confirm form has
     * no manual delivery-charge field at all — even if a request somehow
     * still submits one (a stale client, a crafted request), the server
     * must never use it. The stored charge must come from
     * DeliveryChargeService only.
     */
    public function test_a_submitted_delivery_charge_value_is_completely_ignored(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $order = $this->makePendingMessengerOrder($tenant);
        $variant = $this->makeSellableVariant($tenant->id, ['selling_price' => 800]);

        $this->actingAs($user, 'tenant')->post($this->panelUrl($tenant, 'orders/'.$order->id.'/complete'), [
            'payment_method' => 'cod',
            'delivery_charge' => 999999, // an attacker/stale-client-shaped value
            'variant_ids' => [$variant->id],
            'quantities' => [1],
        ])->assertRedirect();

        $order->refresh();
        $this->assertNotEquals(999999, (float) $order->delivery_charge);
        $this->assertEquals(120, (float) $order->delivery_charge, 'must fall back to the server-computed charge, never the submitted one');
    }

    public function test_completing_an_already_completed_order_is_rejected(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $order = $this->makePendingMessengerOrder($tenant);
        $variant = $this->makeSellableVariant($tenant->id);

        $payload = [
            'payment_method' => 'cod',
            'variant_ids' => [$variant->id],
            'quantities' => [1],
        ];

        $this->actingAs($user, 'tenant')->post($this->panelUrl($tenant, 'orders/'.$order->id.'/complete'), $payload)
            ->assertRedirect();

        // second attempt against the same now-confirmed order must not attach a second set of items
        $this->actingAs($user, 'tenant')->post($this->panelUrl($tenant, 'orders/'.$order->id.'/complete'), $payload)
            ->assertStatus(409);

        $order->refresh();
        $this->assertSame(1, $order->items()->count());
    }

    public function test_tenant_cannot_complete_another_tenants_order(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $userB = $this->makeUser($tenantB->id);
        $orderA = $this->makePendingMessengerOrder($tenantA);
        $variant = $this->makeSellableVariant($tenantA->id);

        // Tenant B's own panel URL/slug, but referencing tenant A's order
        // id — Order's BelongsToTenant global scope (keyed off the
        // URL-resolved currentTenant, i.e. tenant B here) must make
        // route-model-binding fail to find it, 404ing rather than ever
        // letting tenant B touch tenant A's order.
        $response = $this->actingAs($userB, 'tenant')->post($this->panelUrl($tenantB, 'orders/'.$orderA->id.'/complete'), [
            'payment_method' => 'cod',
            'variant_ids' => [$variant->id],
            'quantities' => [1],
        ]);

        $response->assertStatus(404);

        $orderA->refresh();
        $this->assertSame('pending', $orderA->status, "tenant A's order must remain untouched");
    }

    /**
     * Root-cause regression test: prior to this fix, OrderController never
     * called MetaCapiService at all, so a Purchase confirmed through the
     * admin panel (as opposed to storefront checkout) silently never
     * reached Meta. Covers the complete() call site.
     */
    public function test_completing_an_order_sends_capi_purchase_using_the_orders_fb_event_id(): void
    {
        Http::fake(['*/events*' => Http::response(['events_received' => 1])]);

        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $order = $this->makePendingMessengerOrder($tenant);
        $variant = $this->makeSellableVariant($tenant->id, ['selling_price' => 800]);

        MarketingSetting::create([
            'tenant_id' => $tenant->id,
            'fb_pixel_id' => 'pixel-123',
            'fb_capi_token' => 'capi-token-abc',
            'capi_test_mode' => false,
        ]);

        $this->actingAs($user, 'tenant')->post($this->panelUrl($tenant, 'orders/'.$order->id.'/complete'), [
            'payment_method' => 'cod',
            'variant_ids' => [$variant->id],
            'quantities' => [1],
        ])->assertRedirect();

        $order->refresh();
        $this->assertNotNull($order->fb_event_id);

        Http::assertSent(function ($request) use ($order) {
            $body = $request->data();

            return $body['data'][0]['event_id'] === $order->fb_event_id
                && ! array_key_exists('test_event_code', $body); // Test Mode off
        });

        $mk = MarketingSetting::where('tenant_id', $tenant->id)->first();
        $this->assertSame('success', $mk->capi_last_status);
        $this->assertSame(200, $mk->capi_last_http_status);
        $this->assertNotNull($mk->capi_last_event_at);
    }

    /** Same gap, second call site: the order status dropdown (pending -> confirmed). */
    public function test_updating_order_status_to_confirmed_sends_capi_purchase(): void
    {
        Http::fake(['*/events*' => Http::response(['events_received' => 1])]);

        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $order = $this->makePendingMessengerOrder($tenant);
        $order->update(['fb_event_id' => 'evt-status-test']);

        MarketingSetting::create([
            'tenant_id' => $tenant->id,
            'fb_pixel_id' => 'pixel-123',
            'fb_capi_token' => 'capi-token-abc',
            'capi_test_mode' => true,
            'fb_test_event_code' => 'TEST999',
        ]);

        $this->actingAs($user, 'tenant')->post($this->panelUrl($tenant, 'orders/'.$order->id.'/status'), [
            'status' => 'confirmed',
        ])->assertRedirect();

        Http::assertSent(function ($request) {
            $body = $request->data();

            // Test Mode on this tenant -> the saved code must be included.
            return $body['data'][0]['event_id'] === 'evt-status-test'
                && $body['test_event_code'] === 'TEST999';
        });
    }

    /** Re-saving an order that is already confirmed must never re-send Purchase. */
    public function test_updating_an_already_confirmed_orders_status_does_not_resend_capi_purchase(): void
    {
        Http::fake(['*/events*' => Http::response(['events_received' => 1])]);

        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $order = $this->makePendingMessengerOrder($tenant);
        $order->update(['status' => 'confirmed', 'fb_event_id' => 'evt-already-confirmed']);

        MarketingSetting::create([
            'tenant_id' => $tenant->id,
            'fb_pixel_id' => 'pixel-123',
            'fb_capi_token' => 'capi-token-abc',
        ]);

        // Already confirmed -> confirmed again (e.g. re-saving the same form).
        $this->actingAs($user, 'tenant')->post($this->panelUrl($tenant, 'orders/'.$order->id.'/status'), [
            'status' => 'confirmed',
        ])->assertRedirect();

        Http::assertNothingSent();
    }

    /** A Meta API failure must never surface as a failed order confirmation. */
    public function test_capi_failure_does_not_prevent_order_confirmation(): void
    {
        Http::fake(['*/events*' => Http::response([
            'error' => ['message' => 'Invalid parameter', 'type' => 'GraphMethodException', 'code' => 100],
        ], 400)]);

        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $order = $this->makePendingMessengerOrder($tenant);
        $variant = $this->makeSellableVariant($tenant->id);

        MarketingSetting::create([
            'tenant_id' => $tenant->id,
            'fb_pixel_id' => 'pixel-123',
            'fb_capi_token' => 'capi-token-abc',
        ]);

        $response = $this->actingAs($user, 'tenant')->post($this->panelUrl($tenant, 'orders/'.$order->id.'/complete'), [
            'payment_method' => 'cod',
            'variant_ids' => [$variant->id],
            'quantities' => [1],
        ]);

        // The order confirmation itself must succeed regardless of Meta's response.
        $response->assertRedirect();
        $order->refresh();
        $this->assertSame('confirmed', $order->status);

        $mk = MarketingSetting::where('tenant_id', $tenant->id)->first();
        $this->assertSame('failed', $mk->capi_last_status);
        $this->assertSame(400, $mk->capi_last_http_status);
        $this->assertSame('Invalid parameter', $mk->capi_last_error);
    }

    /** Tenant isolation: confirming tenant A's order must only ever use tenant A's own MarketingSetting/token. */
    public function test_capi_uses_the_confirming_tenants_own_marketing_setting(): void
    {
        Http::fake(['*/events*' => Http::response(['events_received' => 1])]);

        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $userA = $this->makeUser($tenantA->id);
        $orderA = $this->makePendingMessengerOrder($tenantA);
        $variant = $this->makeSellableVariant($tenantA->id);

        MarketingSetting::create([
            'tenant_id' => $tenantA->id,
            'fb_pixel_id' => 'pixel-tenant-A',
            'fb_capi_token' => 'token-tenant-A',
        ]);
        MarketingSetting::create([
            'tenant_id' => $tenantB->id,
            'fb_pixel_id' => 'pixel-tenant-B',
            'fb_capi_token' => 'token-tenant-B',
        ]);

        $this->actingAs($userA, 'tenant')->post($this->panelUrl($tenantA, 'orders/'.$orderA->id.'/complete'), [
            'payment_method' => 'cod',
            'variant_ids' => [$variant->id],
            'quantities' => [1],
        ])->assertRedirect();

        Http::assertSent(function ($request) {
            $url = (string) $request->url();
            $token = $request->data()['access_token'] ?? null;

            return str_contains($url, 'pixel-tenant-A') && $token === 'token-tenant-A';
        });
        Http::assertNotSent(fn ($request) => str_contains((string) $request->url(), 'pixel-tenant-B'));

        // withoutGlobalScopes(): BelongsToTenant scopes this query to
        // whichever tenant is currently bound (A, at this point in the
        // test) — reading tenant B's row at all requires stepping outside
        // that scope, same as the rest of this codebase's cross-tenant
        // admin/verification reads do.
        $mkB = MarketingSetting::withoutGlobalScopes()->where('tenant_id', $tenantB->id)->first();
        $this->assertNull($mkB->capi_last_status, "tenant B's settings must be untouched by tenant A's confirmation");
    }
}
