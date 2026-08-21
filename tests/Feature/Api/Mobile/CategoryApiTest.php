<?php

namespace Tests\Feature\Api\Mobile;

use App\Models\Category;
use App\Models\Product;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithApiSchema;
use Tests\TestCase;

/**
 * Covers Api\Mobile\CategoryController — the mobile mirror of
 * Tenant\CategoryController, which only supports index/store/destroy (no
 * update, no hierarchy, no active-toggle). See that controller's docblock.
 */
class CategoryApiTest extends TestCase
{
    use InteractsWithApiSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpApiSchema();
    }

    public function test_list_returns_categories_with_product_counts(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        app()->instance('currentTenant', $tenant);
        $category = Category::create(['tenant_id' => $tenant->id, 'name' => 'Snacks']);
        Product::create(['tenant_id' => $tenant->id, 'name' => 'Chips', 'category_id' => $category->id, 'is_active' => 1]);
        Product::create(['tenant_id' => $tenant->id, 'name' => 'Cola', 'category_id' => $category->id, 'is_active' => 1]);
        Category::create(['tenant_id' => $tenant->id, 'name' => 'Empty Category']);
        app()->forgetInstance('currentTenant');

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/mobile/v1/categories')->assertOk();

        $response->assertJsonStructure(['data' => [['id', 'name', 'product_count']]]);
        $names = collect($response->json('data'))->keyBy('name');
        $this->assertSame(2, $names['Snacks']['product_count']);
        $this->assertSame(0, $names['Empty Category']['product_count']);
    }

    public function test_create_category_with_valid_name(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/mobile/v1/categories', ['name' => 'Beauty']);

        $response->assertCreated()
            ->assertJsonPath('name', 'Beauty')
            ->assertJsonPath('product_count', 0);
        $this->assertIsInt($response->json('id'));

        $this->assertDatabaseHas('categories', ['tenant_id' => $tenant->id, 'name' => 'Beauty']);
    }

    public function test_create_category_rejects_blank_name(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        Sanctum::actingAs($user);

        $this->postJson('/api/mobile/v1/categories', ['name' => ''])
            ->assertStatus(422)->assertJsonValidationErrors('name');
    }

    public function test_create_category_rejects_name_over_100_characters(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        Sanctum::actingAs($user);

        $this->postJson('/api/mobile/v1/categories', ['name' => str_repeat('a', 101)])
            ->assertStatus(422)->assertJsonValidationErrors('name');
    }

    public function test_delete_category_removes_it_and_orphans_its_products_category_id(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        app()->instance('currentTenant', $tenant);
        $category = Category::create(['tenant_id' => $tenant->id, 'name' => 'To Delete']);
        $product = Product::create(['tenant_id' => $tenant->id, 'name' => 'Orphan Candidate', 'category_id' => $category->id, 'is_active' => 1]);
        app()->forgetInstance('currentTenant');

        Sanctum::actingAs($user);

        $this->deleteJson("/api/mobile/v1/categories/{$category->id}")->assertOk()->assertJsonPath('ok', true);

        $this->assertDatabaseMissing('categories', ['id' => $category->id]);
        // No FK cascade/guard in the real schema (ON DELETE SET NULL) — the
        // product itself must survive, just without a category anymore.
        $this->assertDatabaseHas('products', ['id' => $product->id]);
    }

    public function test_tenant_cannot_delete_another_tenants_category(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $userB = $this->makeUser($tenantB->id);
        app()->instance('currentTenant', $tenantA);
        $categoryA = Category::create(['tenant_id' => $tenantA->id, 'name' => 'Tenant A Category']);
        app()->forgetInstance('currentTenant');

        Sanctum::actingAs($userB);

        $this->deleteJson("/api/mobile/v1/categories/{$categoryA->id}")->assertNotFound();
        $this->assertDatabaseHas('categories', ['id' => $categoryA->id]);
    }

    public function test_tenant_category_list_never_includes_other_tenants_categories(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $userA = $this->makeUser($tenantA->id);
        app()->instance('currentTenant', $tenantA);
        Category::create(['tenant_id' => $tenantA->id, 'name' => 'Mine']);
        app()->forgetInstance('currentTenant');
        app()->instance('currentTenant', $tenantB);
        Category::create(['tenant_id' => $tenantB->id, 'name' => 'Not Mine']);
        app()->forgetInstance('currentTenant');

        Sanctum::actingAs($userA);

        $names = collect($this->getJson('/api/mobile/v1/categories')->json('data'))->pluck('name');
        $this->assertTrue($names->contains('Mine'));
        $this->assertFalse($names->contains('Not Mine'));
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/mobile/v1/categories')->assertUnauthorized();
    }
}
