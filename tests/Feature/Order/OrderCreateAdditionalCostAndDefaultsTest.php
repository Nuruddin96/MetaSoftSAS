<?php

namespace Tests\Feature\Order;

use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Tenant;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Tests\Concerns\InteractsWithCommerceSchema;
use Tests\TestCase;

/**
 * Covers two New Order gaps found during the Web/Mobile parity pass:
 *
 * 1. `additional_amount` ("অতিরিক্ত খরচ") already existed as a column and
 *    was already accepted/totaled by the mobile app (chunk55.sql,
 *    OrderCreationService) — the web New Order form had no equivalent
 *    field at all, so it always saved as 0 for a web-created order. Fixed
 *    in Tenant\OrderController::store() + create.blade.php.
 * 2. The channel `<select>` now defaults to "facebook" on a fresh page
 *    load (no `?channel=` query param) instead of falling back to
 *    whichever `<option>` happened to be listed first ("call").
 */
class OrderCreateAdditionalCostAndDefaultsTest extends TestCase
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

    private function makeVariant(Tenant $tenant, float $price = 500): ProductVariant
    {
        $product = Product::create(['tenant_id' => $tenant->id, 'name' => 'Test Product', 'is_active' => 1]);

        return ProductVariant::create([
            'tenant_id' => $tenant->id, 'product_id' => $product->id,
            'variant_name' => 'Default', 'selling_price' => $price, 'purchase_price' => 0,
        ]);
    }

    public function test_additional_amount_is_saved_and_included_in_the_order_total(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        app()->instance('currentTenant', $tenant);
        $variant = $this->makeVariant($tenant, 500);
        app()->forgetInstance('currentTenant');

        $response = $this->actingAs($user, 'tenant')->post($this->panelUrl($tenant, 'orders'), [
            'customer_name' => 'Karim', 'customer_phone' => '01712345678',
            'channel' => 'facebook', 'payment_method' => 'cod',
            'additional_amount' => 75,
            'variant_ids' => [$variant->id], 'quantities' => [1],
        ]);

        $response->assertRedirect();
        $order = Order::where('tenant_id', $tenant->id)->latest()->first();
        $this->assertNotNull($order);
        $this->assertEquals(75.0, (float) $order->additional_amount);
        // subtotal (500) - discount (0) + additional_amount (75) + delivery_charge.
        $this->assertEquals(500 + 75 + (float) $order->delivery_charge, (float) $order->total);
    }

    public function test_additional_amount_defaults_to_zero_when_omitted(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        app()->instance('currentTenant', $tenant);
        $variant = $this->makeVariant($tenant, 300);
        app()->forgetInstance('currentTenant');

        $this->actingAs($user, 'tenant')->post($this->panelUrl($tenant, 'orders'), [
            'customer_name' => 'Karim', 'customer_phone' => '01712345678',
            'channel' => 'call', 'payment_method' => 'cod',
            'variant_ids' => [$variant->id], 'quantities' => [1],
        ])->assertRedirect();

        $order = Order::where('tenant_id', $tenant->id)->latest()->first();
        $this->assertEquals(0.0, (float) $order->additional_amount);
    }

    public function test_new_order_page_defaults_the_channel_select_to_facebook(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);

        $response = $this->actingAs($user, 'tenant')->get($this->panelUrl($tenant, 'orders/create'));

        $response->assertOk();
        $response->assertSee('<option value="facebook" selected', false);
    }

    public function test_new_order_page_still_honors_an_explicit_channel_query_param(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);

        $response = $this->actingAs($user, 'tenant')->get($this->panelUrl($tenant, 'orders/create').'?channel=whatsapp');

        $response->assertOk();
        $response->assertSee('<option value="whatsapp" selected', false);
        $response->assertDontSee('<option value="facebook" selected', false);
    }

    /** New Order page shows the new "অতিরিক্ত খরচ" field. */
    public function test_new_order_page_shows_the_additional_cost_field(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);

        $response = $this->actingAs($user, 'tenant')->get($this->panelUrl($tenant, 'orders/create'));

        $response->assertOk();
        $response->assertSee('additional_amount', false);
        $response->assertSee('অতিরিক্ত খরচ', false);
    }

    /** Order detail page shows the additional cost line only when it's actually non-zero. */
    public function test_order_detail_page_shows_additional_cost_when_present(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        app()->instance('currentTenant', $tenant);
        $variant = $this->makeVariant($tenant, 500);
        app()->forgetInstance('currentTenant');

        $this->actingAs($user, 'tenant')->post($this->panelUrl($tenant, 'orders'), [
            'customer_name' => 'Karim', 'customer_phone' => '01712345678',
            'channel' => 'facebook', 'payment_method' => 'cod', 'additional_amount' => 120,
            'variant_ids' => [$variant->id], 'quantities' => [1],
        ])->assertRedirect();

        $order = Order::where('tenant_id', $tenant->id)->latest()->first();
        $response = $this->actingAs($user, 'tenant')->get($this->panelUrl($tenant, 'orders/'.$order->id));

        $response->assertOk();
        $response->assertSee('অতিরিক্ত খরচ', false);
        $response->assertSee('120', false);
    }
}
