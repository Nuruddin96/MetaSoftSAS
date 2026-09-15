<?php

namespace Tests\Feature\Api\Mobile;

use App\Models\Inventory;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\Warehouse;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithApiSchema;
use Tests\TestCase;

/**
 * Covers Api\Mobile\InventoryController — reuses
 * Tenant\InventoryController's exact increment-by-delta adjust() business
 * rule (Inventory::firstOrCreate()->increment() + a StockMovement audit
 * row), not a reimplementation.
 */
class InventoryApiTest extends TestCase
{
    use InteractsWithApiSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpApiSchema();
    }

    protected function makeVariantWithStock(int $tenantId, array $variantAttrs = [], int $stock = 10): array
    {
        app()->instance('currentTenant', \App\Models\Tenant::find($tenantId));

        $product = Product::create(['tenant_id' => $tenantId, 'name' => 'Test Product', 'is_active' => 1]);
        $variant = ProductVariant::create(array_merge([
            'tenant_id' => $tenantId,
            'product_id' => $product->id,
            'variant_name' => 'Default',
            'selling_price' => 100,
        ], $variantAttrs));
        $warehouse = Warehouse::create(['tenant_id' => $tenantId, 'name' => 'Main', 'is_default' => 1]);
        Inventory::create(['tenant_id' => $tenantId, 'variant_id' => $variant->id, 'warehouse_id' => $warehouse->id, 'quantity' => $stock]);

        return [$product, $variant, $warehouse];
    }

    public function test_list_returns_variant_rows_with_warehouse_ids(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        [$product, $variant, $warehouse] = $this->makeVariantWithStock($tenant->id, [], 12);
        app()->forgetInstance('currentTenant');

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/mobile/v1/inventory')->assertOk();

        $response->assertJsonStructure([
            'data' => [['variant_id', 'product_id', 'product_name', 'variant_name', 'sku', 'total_stock', 'is_low_stock', 'warehouse_stock' => [['warehouse_id', 'warehouse_name', 'quantity']]]],
            'meta' => ['current_page', 'last_page', 'per_page', 'total'],
        ]);

        $row = $response->json('data.0');
        $this->assertSame($variant->id, $row['variant_id']);
        $this->assertSame($product->id, $row['product_id']);
        $this->assertSame(12, $row['total_stock']);
        $this->assertSame($warehouse->id, $row['warehouse_stock'][0]['warehouse_id']);
    }

    public function test_a_multi_variant_product_produces_one_row_per_variant(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        app()->instance('currentTenant', $tenant);
        $product = Product::create(['tenant_id' => $tenant->id, 'name' => 'Multi', 'is_active' => 1, 'has_variants' => 1]);
        ProductVariant::create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'variant_name' => 'Small', 'selling_price' => 100]);
        ProductVariant::create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'variant_name' => 'Large', 'selling_price' => 150]);
        app()->forgetInstance('currentTenant');

        Sanctum::actingAs($user);

        $names = collect($this->getJson('/api/mobile/v1/inventory')->json('data'))->pluck('variant_name');
        $this->assertTrue($names->contains('Small'));
        $this->assertTrue($names->contains('Large'));
    }

    public function test_search_filters_by_product_name(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $this->makeVariantWithStock($tenant->id, [], 5);
        app()->instance('currentTenant', $tenant);
        $other = Product::create(['tenant_id' => $tenant->id, 'name' => 'Findable Widget', 'is_active' => 1]);
        ProductVariant::create(['tenant_id' => $tenant->id, 'product_id' => $other->id, 'variant_name' => 'Default', 'selling_price' => 50]);
        app()->forgetInstance('currentTenant');

        Sanctum::actingAs($user);

        $names = collect($this->getJson('/api/mobile/v1/inventory?q=Findable')->json('data'))->pluck('product_name');
        $this->assertTrue($names->contains('Findable Widget'));
        $this->assertFalse($names->contains('Test Product'));
    }

    public function test_low_stock_filter_uses_the_real_per_variant_threshold(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $this->makeVariantWithStock($tenant->id, ['low_stock_threshold' => 5], 2); // low
        app()->instance('currentTenant', $tenant);
        $healthy = Product::create(['tenant_id' => $tenant->id, 'name' => 'Healthy Stock Item', 'is_active' => 1]);
        $healthyVariant = ProductVariant::create(['tenant_id' => $tenant->id, 'product_id' => $healthy->id, 'variant_name' => 'Default', 'selling_price' => 50, 'low_stock_threshold' => 5]);
        $warehouse = Warehouse::create(['tenant_id' => $tenant->id, 'name' => 'Main', 'is_default' => 1]);
        Inventory::create(['tenant_id' => $tenant->id, 'variant_id' => $healthyVariant->id, 'warehouse_id' => $warehouse->id, 'quantity' => 50]);
        app()->forgetInstance('currentTenant');

        Sanctum::actingAs($user);

        $names = collect($this->getJson('/api/mobile/v1/inventory?low_stock=1')->json('data'))->pluck('product_name');
        $this->assertTrue($names->contains('Test Product'));
        $this->assertFalse($names->contains('Healthy Stock Item'));
    }

    public function test_adjust_increments_existing_stock_by_delta_not_a_set(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        [, $variant, $warehouse] = $this->makeVariantWithStock($tenant->id, [], 10);
        app()->forgetInstance('currentTenant');

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/mobile/v1/inventory/adjust', [
            'variant_id' => $variant->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 5,
            'note' => 'Restock',
        ]);

        $response->assertOk()->assertJsonPath('total_stock', 15);

        $this->assertDatabaseHas('inventory', ['variant_id' => $variant->id, 'warehouse_id' => $warehouse->id, 'quantity' => 15]);
        $this->assertDatabaseHas('stock_movements', [
            'variant_id' => $variant->id, 'warehouse_id' => $warehouse->id, 'type' => 'adjustment', 'quantity' => 5, 'note' => 'Restock',
        ]);
    }

    public function test_adjust_accepts_a_negative_delta_to_decrement(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        [, $variant, $warehouse] = $this->makeVariantWithStock($tenant->id, [], 10);
        app()->forgetInstance('currentTenant');

        Sanctum::actingAs($user);

        $this->postJson('/api/mobile/v1/inventory/adjust', [
            'variant_id' => $variant->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => -4,
        ])->assertOk()->assertJsonPath('total_stock', 6);
    }

    public function test_adjust_creates_a_new_inventory_row_for_a_warehouse_the_variant_has_no_stock_in_yet(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        [, $variant] = $this->makeVariantWithStock($tenant->id, [], 10);
        app()->instance('currentTenant', $tenant);
        $secondWarehouse = Warehouse::create(['tenant_id' => $tenant->id, 'name' => 'Branch']);
        app()->forgetInstance('currentTenant');

        Sanctum::actingAs($user);

        $this->postJson('/api/mobile/v1/inventory/adjust', [
            'variant_id' => $variant->id,
            'warehouse_id' => $secondWarehouse->id,
            'quantity' => 3,
        ])->assertOk()->assertJsonPath('total_stock', 13);

        $this->assertDatabaseHas('inventory', ['variant_id' => $variant->id, 'warehouse_id' => $secondWarehouse->id, 'quantity' => 3]);
    }

    public function test_adjust_rejects_a_variant_belonging_to_another_tenant(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $userB = $this->makeUser($tenantB->id);
        [, $variantA, $warehouseA] = $this->makeVariantWithStock($tenantA->id, [], 10);
        app()->forgetInstance('currentTenant');

        Sanctum::actingAs($userB);

        $this->postJson('/api/mobile/v1/inventory/adjust', [
            'variant_id' => $variantA->id,
            'warehouse_id' => $warehouseA->id,
            'quantity' => 5,
        ])->assertStatus(422)->assertJsonValidationErrors('variant_id');
    }

    public function test_adjust_rejects_a_warehouse_belonging_to_another_tenant(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $userA = $this->makeUser($tenantA->id);
        [, $variantA] = $this->makeVariantWithStock($tenantA->id, [], 10);
        app()->instance('currentTenant', $tenantB);
        $warehouseB = Warehouse::create(['tenant_id' => $tenantB->id, 'name' => 'Other Tenant Warehouse']);
        app()->forgetInstance('currentTenant');

        Sanctum::actingAs($userA);

        $this->postJson('/api/mobile/v1/inventory/adjust', [
            'variant_id' => $variantA->id,
            'warehouse_id' => $warehouseB->id,
            'quantity' => 5,
        ])->assertStatus(422)->assertJsonValidationErrors('warehouse_id');
    }

    public function test_adjust_rejects_missing_required_fields(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        Sanctum::actingAs($user);

        $this->postJson('/api/mobile/v1/inventory/adjust', [])
            ->assertStatus(422)->assertJsonValidationErrors(['variant_id', 'warehouse_id', 'quantity']);
    }

    public function test_tenant_inventory_list_never_includes_other_tenants_variants(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $userA = $this->makeUser($tenantA->id);
        $this->makeVariantWithStock($tenantA->id, [], 5);
        $this->makeVariantWithStock($tenantB->id, [], 5);
        app()->forgetInstance('currentTenant');

        Sanctum::actingAs($userA);

        $productIds = collect($this->getJson('/api/mobile/v1/inventory')->json('data'))->pluck('product_id');
        $tenantBProductIds = \App\Models\Product::withoutGlobalScopes()->where('tenant_id', $tenantB->id)->pluck('id');
        $this->assertEmpty($productIds->intersect($tenantBProductIds));
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/mobile/v1/inventory')->assertUnauthorized();
    }
}
