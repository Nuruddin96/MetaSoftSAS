<?php

namespace Tests\Feature\Api\Mobile;

use App\Models\SourceOrder;
use App\Models\SourceProduct;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithApiSchema;
use Tests\TestCase;

/**
 * Covers Api\Mobile\ProductSourceController — mirrors
 * Tenant\ProductSourceController's real capability (SourceProduct is a
 * platform-wide catalog with no tenant_id at all; SourceOrder is
 * tenant-scoped via manual filtering, no BelongsToTenant trait).
 */
class ProductSourceApiTest extends TestCase
{
    use InteractsWithApiSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpApiSchema();

        if (! \Illuminate\Support\Facades\Schema::hasTable('source_products')) {
            \Illuminate\Support\Facades\Schema::create('source_products', function ($table) {
                $table->id();
                $table->string('name', 200);
                $table->string('slug', 220)->unique();
                $table->string('image_path')->nullable();
                $table->text('description')->nullable();
                $table->decimal('unit_price', 12, 2)->default(0);
                $table->decimal('max_price', 12, 2)->nullable();
                $table->integer('min_order_qty')->default(1);
                $table->string('delivery_time_days', 50)->nullable();
                $table->decimal('shipping_cost', 10, 2)->default(0);
                $table->boolean('is_active')->default(true);
                $table->integer('sort_order')->default(0);
                $table->timestamps();
            });
        }

        if (! \Illuminate\Support\Facades\Schema::hasTable('source_orders')) {
            \Illuminate\Support\Facades\Schema::create('source_orders', function ($table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('source_product_id');
                $table->integer('quantity')->default(1);
                $table->text('note')->nullable();
                $table->string('contact_phone', 20)->nullable();
                $table->string('status', 20)->default('pending');
                $table->text('admin_note')->nullable();
                $table->timestamps();
            });
        }

        if (! \Illuminate\Support\Facades\Schema::hasTable('source_product_images')) {
            \Illuminate\Support\Facades\Schema::create('source_product_images', function ($table) {
                $table->id();
                $table->unsignedBigInteger('source_product_id');
                $table->string('image_path');
                $table->integer('sort_order')->default(0);
            });
        }
    }

    protected function makeCatalogProduct(array $attrs = []): SourceProduct
    {
        return SourceProduct::create(array_merge([
            'name' => 'Wholesale Widget',
            'unit_price' => 200,
            'min_order_qty' => 5,
            'is_active' => 1,
        ], $attrs));
    }

    public function test_catalog_index_only_lists_active_products(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $this->makeCatalogProduct(['name' => 'Active One']);
        $this->makeCatalogProduct(['name' => 'Inactive One', 'is_active' => 0]);

        Sanctum::actingAs($user);

        $names = collect($this->getJson('/api/mobile/v1/product-source/catalog')->json('data'))->pluck('name');
        $this->assertTrue($names->contains('Active One'));
        $this->assertFalse($names->contains('Inactive One'));
    }

    public function test_catalog_show_returns_detail_for_an_active_product(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $product = $this->makeCatalogProduct();

        Sanctum::actingAs($user);

        $this->getJson("/api/mobile/v1/product-source/catalog/{$product->id}")
            ->assertOk()
            ->assertJsonStructure(['id', 'name', 'unit_price', 'min_order_qty', 'description', 'images']);
    }

    public function test_catalog_show_404s_for_an_inactive_product(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $product = $this->makeCatalogProduct(['is_active' => 0]);

        Sanctum::actingAs($user);

        $this->getJson("/api/mobile/v1/product-source/catalog/{$product->id}")->assertNotFound();
    }

    public function test_order_creates_a_source_order_for_the_current_tenant(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $product = $this->makeCatalogProduct(['min_order_qty' => 5]);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/mobile/v1/product-source/catalog/{$product->id}/order", [
            'quantity' => 10,
            'contact_phone' => '01712345678',
        ]);

        $response->assertCreated()->assertJsonPath('quantity', 10)->assertJsonPath('status', 'pending');
        $this->assertDatabaseHas('source_orders', ['tenant_id' => $tenant->id, 'source_product_id' => $product->id, 'quantity' => 10]);
    }

    public function test_order_rejects_quantity_below_minimum(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $product = $this->makeCatalogProduct(['min_order_qty' => 5]);

        Sanctum::actingAs($user);

        $this->postJson("/api/mobile/v1/product-source/catalog/{$product->id}/order", [
            'quantity' => 2,
            'contact_phone' => '01712345678',
        ])->assertStatus(422)->assertJsonValidationErrors('quantity');
    }

    public function test_order_rejects_an_invalid_phone(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $product = $this->makeCatalogProduct();

        Sanctum::actingAs($user);

        $this->postJson("/api/mobile/v1/product-source/catalog/{$product->id}/order", [
            'quantity' => 5,
            'contact_phone' => '123',
        ])->assertStatus(422)->assertJsonValidationErrors('contact_phone');
    }

    public function test_my_orders_only_returns_the_current_tenants_orders(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $userA = $this->makeUser($tenantA->id);
        $product = $this->makeCatalogProduct();

        SourceOrder::create(['tenant_id' => $tenantA->id, 'source_product_id' => $product->id, 'quantity' => 5, 'contact_phone' => '01712345678', 'status' => 'pending']);
        SourceOrder::create(['tenant_id' => $tenantB->id, 'source_product_id' => $product->id, 'quantity' => 5, 'contact_phone' => '01712345678', 'status' => 'pending']);

        Sanctum::actingAs($userA);

        $response = $this->getJson('/api/mobile/v1/product-source/orders')->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/mobile/v1/product-source/catalog')->assertUnauthorized();
    }
}
