<?php

namespace Tests\Feature\Api\Mobile;

use App\Models\Inventory;
use App\Models\Plan;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Warehouse;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithApiSchema;
use Tests\TestCase;

/**
 * Covers Api\Mobile\PosController — reuses Tenant\PosController's exact
 * sell() business rule (real Order + Inventory decrement + StockMovement +
 * Customer due update), not a reimplementation.
 */
class PosApiTest extends TestCase
{
    use InteractsWithApiSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpApiSchema();

        if (! Schema::hasColumn('plans', 'allow_pos')) {
            Schema::table('plans', fn (Blueprint $table) => $table->boolean('allow_pos')->default(true));
        }
    }

    protected function makeTenantWithPos(bool $allowPos = true): array
    {
        $plan = Plan::create(['name' => 'Test', 'slug' => 'test-'.uniqid(), 'allow_pos' => $allowPos]);
        $tenant = $this->makeTenant(['plan_id' => $plan->id]);
        $user = $this->makeUser($tenant->id);

        return [$tenant, $user];
    }

    protected function makeVariantWithStock(int $tenantId, int $stock = 10): array
    {
        app()->instance('currentTenant', \App\Models\Tenant::find($tenantId));
        $product = Product::create(['tenant_id' => $tenantId, 'name' => 'Widget', 'is_active' => 1]);
        $variant = ProductVariant::create([
            'tenant_id' => $tenantId, 'product_id' => $product->id, 'variant_name' => 'Default',
            'selling_price' => 100, 'sku' => 'WIDGET-1', 'barcode' => '1234567890123',
        ]);
        $warehouse = Warehouse::create(['tenant_id' => $tenantId, 'name' => 'Main', 'is_default' => 1]);
        Inventory::create(['tenant_id' => $tenantId, 'variant_id' => $variant->id, 'warehouse_id' => $warehouse->id, 'quantity' => $stock]);
        app()->forgetInstance('currentTenant');

        return [$variant, $warehouse];
    }

    public function test_scan_finds_a_variant_by_barcode(): void
    {
        [$tenant, $user] = $this->makeTenantWithPos();
        [$variant] = $this->makeVariantWithStock($tenant->id, 5);

        Sanctum::actingAs($user);

        $this->getJson('/api/mobile/v1/pos/scan/1234567890123')
            ->assertOk()->assertJsonPath('found', true)->assertJsonPath('id', $variant->id);
    }

    public function test_scan_finds_a_variant_by_sku(): void
    {
        [$tenant, $user] = $this->makeTenantWithPos();
        $this->makeVariantWithStock($tenant->id, 5);

        Sanctum::actingAs($user);

        $this->getJson('/api/mobile/v1/pos/scan/WIDGET-1')->assertOk()->assertJsonPath('found', true);
    }

    public function test_scan_returns_found_false_for_an_unknown_code(): void
    {
        [, $user] = $this->makeTenantWithPos();

        Sanctum::actingAs($user);

        $this->getJson('/api/mobile/v1/pos/scan/does-not-exist')->assertOk()->assertJsonPath('found', false);
    }

    public function test_sell_creates_a_pos_order_decrements_stock_and_charges_cash(): void
    {
        [$tenant, $user] = $this->makeTenantWithPos();
        [$variant, $warehouse] = $this->makeVariantWithStock($tenant->id, 10);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/mobile/v1/pos/sell', [
            'items' => [['variant_id' => $variant->id, 'qty' => 3]],
            'payment_method' => 'cash',
        ]);

        $response->assertCreated()->assertJsonPath('total', 300)->assertJsonPath('due_amount', 0);
        $this->assertDatabaseHas('orders', ['source' => 'pos', 'payment_status' => 'paid']);
        $this->assertDatabaseHas('inventory', ['variant_id' => $variant->id, 'warehouse_id' => $warehouse->id, 'quantity' => 7]);
        $this->assertDatabaseHas('stock_movements', ['variant_id' => $variant->id, 'type' => 'pos_sale', 'quantity' => -3]);
    }

    public function test_sell_on_due_creates_a_customer_and_due_ledger_entry(): void
    {
        [$tenant, $user] = $this->makeTenantWithPos();
        [$variant] = $this->makeVariantWithStock($tenant->id, 10);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/mobile/v1/pos/sell', [
            'items' => [['variant_id' => $variant->id, 'qty' => 2]],
            'payment_method' => 'due',
            'paid_amount' => 50,
            'customer_name' => 'Karim',
            'customer_phone' => '01712345678',
        ]);

        $response->assertCreated()->assertJsonPath('due_amount', 150);
        $this->assertDatabaseHas('customers', ['tenant_id' => $tenant->id, 'phone' => '01712345678', 'due_balance' => 150]);
        $this->assertDatabaseHas('due_ledger', ['amount' => 150]);
    }

    public function test_sell_rejects_due_without_a_customer_phone(): void
    {
        [$tenant, $user] = $this->makeTenantWithPos();
        [$variant] = $this->makeVariantWithStock($tenant->id, 10);

        Sanctum::actingAs($user);

        $this->postJson('/api/mobile/v1/pos/sell', [
            'items' => [['variant_id' => $variant->id, 'qty' => 1]],
            'payment_method' => 'due',
        ])->assertStatus(422)->assertJsonValidationErrors('customer_phone');
    }

    public function test_sell_rejects_when_plan_does_not_allow_pos(): void
    {
        [$tenant, $user] = $this->makeTenantWithPos(allowPos: false);
        [$variant] = $this->makeVariantWithStock($tenant->id, 10);

        Sanctum::actingAs($user);

        $this->postJson('/api/mobile/v1/pos/sell', [
            'items' => [['variant_id' => $variant->id, 'qty' => 1]],
            'payment_method' => 'cash',
        ])->assertForbidden();
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/mobile/v1/pos/scan/x')->assertUnauthorized();
    }
}
