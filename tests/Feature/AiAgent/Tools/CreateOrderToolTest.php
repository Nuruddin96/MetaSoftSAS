<?php

namespace Tests\Feature\AiAgent\Tools;

use App\Models\Customer;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Tenant;
use App\Models\Warehouse;
use App\Services\AI\Tools\CreateOrderTool;
use Tests\Concerns\InteractsWithCommerceSchema;
use Tests\TestCase;

class CreateOrderToolTest extends TestCase
{
    use InteractsWithCommerceSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCommerceSchema();
    }

    protected function makeProductWithStock(int $tenantId, array $productAttrs = [], array $variantAttrs = [], int $stock = 10): ProductVariant
    {
        app()->instance('currentTenant', Tenant::find($tenantId));

        $product = Product::create(array_merge([
            'tenant_id' => $tenantId, 'name' => 'Red Shirt', 'is_active' => 1,
        ], $productAttrs));

        $variant = ProductVariant::create(array_merge([
            'tenant_id' => $tenantId, 'product_id' => $product->id,
            'variant_name' => 'Default', 'selling_price' => 500, 'purchase_price' => 300,
        ], $variantAttrs));

        $warehouse = Warehouse::create(['tenant_id' => $tenantId, 'name' => 'Main', 'is_default' => 1]);

        Inventory::create(['tenant_id' => $tenantId, 'variant_id' => $variant->id, 'warehouse_id' => $warehouse->id, 'quantity' => $stock]);

        return $variant;
    }

    public function test_is_mutating(): void
    {
        $this->assertTrue((new CreateOrderTool)->isMutating());
    }

    public function test_preview_rejects_an_invalid_phone_number(): void
    {
        $tenant = $this->makeTenant();

        $preview = (new CreateOrderTool)->preview($tenant->id, [
            'customer_phone' => '123',
            'items' => [['product_name' => 'x', 'quantity' => 1]],
        ]);

        $this->assertArrayHasKey('error', $preview);
    }

    public function test_preview_rejects_an_unknown_product(): void
    {
        $tenant = $this->makeTenant();

        $preview = (new CreateOrderTool)->preview($tenant->id, [
            'customer_phone' => '01712345678',
            'items' => [['product_name' => 'Nonexistent Product', 'quantity' => 1]],
        ]);

        $this->assertArrayHasKey('error', $preview);
    }

    public function test_preview_rejects_insufficient_stock(): void
    {
        $tenant = $this->makeTenant();
        $this->makeProductWithStock($tenant->id, stock: 2);

        $preview = (new CreateOrderTool)->preview($tenant->id, [
            'customer_phone' => '01712345678',
            'items' => [['product_name' => 'Red Shirt', 'quantity' => 5]],
        ]);

        $this->assertArrayHasKey('error', $preview);
        $this->assertStringContainsString('স্টক নেই', $preview['error']);
    }

    public function test_preview_never_matches_another_tenants_product(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $this->makeProductWithStock($tenantB->id);

        $preview = (new CreateOrderTool)->preview($tenantA->id, [
            'customer_phone' => '01712345678',
            'items' => [['product_name' => 'Red Shirt', 'quantity' => 1]],
        ]);

        $this->assertArrayHasKey('error', $preview, "tenant A must never be able to order tenant B's product");
    }

    public function test_preview_succeeds_and_resolves_variant_id_and_totals(): void
    {
        $tenant = $this->makeTenant();
        $variant = $this->makeProductWithStock($tenant->id, variantAttrs: ['selling_price' => 500]);

        $preview = (new CreateOrderTool)->preview($tenant->id, [
            'customer_phone' => '01712345678',
            'customer_name' => 'Karim',
            'items' => [['product_name' => 'Red Shirt', 'quantity' => 2]],
        ]);

        $this->assertArrayHasKey('summary', $preview);
        $this->assertArrayHasKey('resolved_args', $preview);
        $this->assertSame($variant->id, $preview['resolved_args']['items'][0]['variant_id']);
        $this->assertSame(1000.0, $preview['resolved_args']['subtotal']);
        $this->assertStringContainsString('Karim', $preview['summary']);
    }

    public function test_handle_creates_the_order_and_decrements_stock(): void
    {
        $tenant = $this->makeTenant();
        $variant = $this->makeProductWithStock($tenant->id, stock: 10);
        $this->actingAsTenantUser($tenant->id);

        $preview = (new CreateOrderTool)->preview($tenant->id, [
            'customer_phone' => '01712345678',
            'customer_name' => 'Karim',
            'items' => [['product_name' => 'Red Shirt', 'quantity' => 3]],
        ]);

        $result = (new CreateOrderTool)->handle($tenant->id, $preview['resolved_args']);

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('order_number', $result);

        $order = Order::withoutGlobalScopes()->where('order_number', $result['order_number'])->first();
        $this->assertNotNull($order);
        $this->assertSame('confirmed', $order->status);
        $this->assertSame(1, $order->items()->count());

        $this->assertSame(7, (int) Inventory::withoutGlobalScopes()->where('variant_id', $variant->id)->value('quantity'));

        $customer = Customer::withoutGlobalScopes()->where('phone', '01712345678')->first();
        $this->assertNotNull($customer, 'a new customer must be created');
        $this->assertSame(1, $customer->total_orders);
    }

    public function test_handle_reuses_an_existing_customer_by_phone(): void
    {
        $tenant = $this->makeTenant();
        $this->makeProductWithStock($tenant->id, stock: 10);
        $this->actingAsTenantUser($tenant->id);
        $existing = Customer::create(['tenant_id' => $tenant->id, 'name' => 'Old Name', 'phone' => '01712345678', 'total_orders' => 2, 'total_spent' => 500]);

        $preview = (new CreateOrderTool)->preview($tenant->id, [
            'customer_phone' => '01712345678',
            'items' => [['product_name' => 'Red Shirt', 'quantity' => 1]],
        ]);

        (new CreateOrderTool)->handle($tenant->id, $preview['resolved_args']);

        $this->assertSame(1, Customer::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count(), 'must reuse the existing customer, not create a duplicate');
        $existing->refresh();
        $this->assertSame(3, $existing->total_orders);
    }

    public function test_handle_never_creates_the_order_for_another_tenant(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $variant = $this->makeProductWithStock($tenantA->id, stock: 10);
        $this->actingAsTenantUser($tenantA->id);

        // Directly construct resolved_args referencing tenant A's variant,
        // but execute as tenant B — the tool must not create an order
        // that references a different tenant's variant/product data.
        $result = (new CreateOrderTool)->handle($tenantB->id, [
            'customer_phone' => '01712345678',
            'customer_name' => 'Attacker',
            'items' => [[
                'variant_id' => $variant->id,
                'product_name' => 'Red Shirt',
                'variant_name' => 'Default',
                'sku' => $variant->sku,
                'unit_price' => 500,
                'purchase_price' => 300,
                'quantity' => 1,
                'line_total' => 500,
            ]],
            'subtotal' => 500,
            'total' => 500,
            'payment_method' => 'cod',
        ]);

        // The variant lookup is scoped to tenant B, which doesn't own it
        // — so it's correctly treated as "not found" and the mutation is
        // refused, not silently created against the wrong tenant's stock.
        $this->assertFalse($result['success']);
        $this->assertSame(0, Order::withoutGlobalScopes()->where('tenant_id', $tenantB->id)->count());
    }

    public function test_handle_refuses_if_stock_is_no_longer_sufficient_at_execution_time(): void
    {
        $tenant = $this->makeTenant();
        $variant = $this->makeProductWithStock($tenant->id, stock: 10);
        $this->actingAsTenantUser($tenant->id);

        $preview = (new CreateOrderTool)->preview($tenant->id, [
            'customer_phone' => '01712345678',
            'items' => [['product_name' => 'Red Shirt', 'quantity' => 5]],
        ]);

        // Stock sold through another channel between preview and confirm.
        Inventory::withoutGlobalScopes()->where('variant_id', $variant->id)->update(['quantity' => 1]);

        $result = (new CreateOrderTool)->handle($tenant->id, $preview['resolved_args']);

        $this->assertFalse($result['success']);
        $this->assertSame(0, Order::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count());
    }

    /** CreateOrderTool::handle() reads auth('tenant')->id() for StockMovement attribution. */
    protected function actingAsTenantUser(int $tenantId): void
    {
        $this->actingAs($this->makeUser($tenantId), 'tenant');
    }
}
