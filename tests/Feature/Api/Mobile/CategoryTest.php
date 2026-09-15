<?php

namespace Tests\Feature\Api\Mobile;

use App\Models\Category;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithApiSchema;
use Tests\TestCase;

/**
 * Catalog Architecture project: categories.parent_id/is_active existed as
 * DB columns before this but were never read/written anywhere (see
 * Api\Mobile\CategoryController's docblock) — this covers the newly wired
 * subcategory support (store/update with parent_id, the two-level cap,
 * tenant isolation) plus the brand-new update() endpoint.
 */
class CategoryTest extends TestCase
{
    use InteractsWithApiSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpApiSchema();
    }

    private function actingAsTenantUser(): \App\Models\Tenant
    {
        $tenant = $this->makeTenant();
        Sanctum::actingAs($this->makeUser($tenant->id));

        return $tenant;
    }

    public function test_store_creates_a_top_level_category_by_default(): void
    {
        $this->actingAsTenantUser();

        $response = $this->postJson('/api/mobile/v1/categories', ['name' => 'Face Care']);

        $response->assertCreated()->assertJsonPath('name', 'Face Care')->assertJsonPath('parent_id', null);
    }

    public function test_store_accepts_a_parent_id_to_create_a_subcategory(): void
    {
        $tenant = $this->actingAsTenantUser();
        $parent = Category::create(['tenant_id' => $tenant->id, 'name' => 'Face Care']);

        $response = $this->postJson('/api/mobile/v1/categories', ['name' => 'Cleanser', 'parent_id' => $parent->id]);

        $response->assertCreated()->assertJsonPath('parent_id', $parent->id);
    }

    public function test_store_rejects_a_parent_id_belonging_to_another_tenant(): void
    {
        $this->actingAsTenantUser();
        $otherTenant = $this->makeTenant();
        $foreignParent = Category::create(['tenant_id' => $otherTenant->id, 'name' => 'Foreign Category']);

        $this->postJson('/api/mobile/v1/categories', ['name' => 'Cleanser', 'parent_id' => $foreignParent->id])
            ->assertStatus(422)->assertJsonValidationErrors('parent_id');
    }

    public function test_store_rejects_a_parent_id_that_is_itself_a_subcategory(): void
    {
        $tenant = $this->actingAsTenantUser();
        $topLevel = Category::create(['tenant_id' => $tenant->id, 'name' => 'Face Care']);
        $sub = Category::create(['tenant_id' => $tenant->id, 'name' => 'Cleanser', 'parent_id' => $topLevel->id]);

        // Two-level cap: a subcategory can never itself be a parent.
        $this->postJson('/api/mobile/v1/categories', ['name' => 'Toner', 'parent_id' => $sub->id])
            ->assertStatus(422)->assertJsonValidationErrors('parent_id');
    }

    public function test_update_renames_a_category(): void
    {
        $tenant = $this->actingAsTenantUser();
        $category = Category::create(['tenant_id' => $tenant->id, 'name' => 'Old Name']);

        $this->patchJson("/api/mobile/v1/categories/{$category->id}", ['name' => 'New Name'])
            ->assertOk()->assertJsonPath('name', 'New Name');
    }

    public function test_update_can_convert_a_top_level_category_into_a_subcategory(): void
    {
        $tenant = $this->actingAsTenantUser();
        $parent = Category::create(['tenant_id' => $tenant->id, 'name' => 'Face Care']);
        $category = Category::create(['tenant_id' => $tenant->id, 'name' => 'Cleanser']);

        $this->patchJson("/api/mobile/v1/categories/{$category->id}", ['parent_id' => $parent->id])
            ->assertOk()->assertJsonPath('parent_id', $parent->id);
    }

    public function test_update_rejects_a_category_becoming_its_own_parent(): void
    {
        $tenant = $this->actingAsTenantUser();
        $category = Category::create(['tenant_id' => $tenant->id, 'name' => 'Face Care']);

        $this->patchJson("/api/mobile/v1/categories/{$category->id}", ['parent_id' => $category->id])
            ->assertStatus(422)->assertJsonValidationErrors('parent_id');
    }

    public function test_update_rejects_giving_a_parent_to_a_category_that_already_has_children(): void
    {
        $tenant = $this->actingAsTenantUser();
        $parentA = Category::create(['tenant_id' => $tenant->id, 'name' => 'Face Care']);
        Category::create(['tenant_id' => $tenant->id, 'name' => 'Cleanser', 'parent_id' => $parentA->id]);
        $parentB = Category::create(['tenant_id' => $tenant->id, 'name' => 'Body Care']);

        // Face Care already has a child (Cleanser) — it must not also become a subcategory of Body Care.
        $this->patchJson("/api/mobile/v1/categories/{$parentA->id}", ['parent_id' => $parentB->id])
            ->assertStatus(422)->assertJsonValidationErrors('parent_id');
    }

    public function test_update_rejects_editing_another_tenants_category(): void
    {
        $this->actingAsTenantUser();
        $otherTenant = $this->makeTenant();
        $foreign = Category::create(['tenant_id' => $otherTenant->id, 'name' => 'Not Yours']);

        $this->patchJson("/api/mobile/v1/categories/{$foreign->id}", ['name' => 'Hacked'])->assertNotFound();
    }

    public function test_index_never_exposes_another_tenants_categories(): void
    {
        $this->actingAsTenantUser();
        $otherTenant = $this->makeTenant();
        Category::create(['tenant_id' => $otherTenant->id, 'name' => 'Other Tenant Category']);

        $response = $this->getJson('/api/mobile/v1/categories')->assertOk();
        $names = collect($response->json('data'))->pluck('name');

        $this->assertFalse($names->contains('Other Tenant Category'));
    }

    /**
     * Existing behavior, must survive untouched: deleting a category
     * orphans (does not cascade-delete) its products — see
     * CategoryController's docblock and products.category_id's
     * ON DELETE SET NULL in schema.sql.
     */
    public function test_destroy_orphans_products_instead_of_deleting_them(): void
    {
        $tenant = $this->actingAsTenantUser();
        $category = Category::create(['tenant_id' => $tenant->id, 'name' => 'Face Care']);
        $product = \App\Models\Product::create(['tenant_id' => $tenant->id, 'name' => 'Cream', 'category_id' => $category->id]);

        $this->deleteJson("/api/mobile/v1/categories/{$category->id}")->assertOk();

        $this->assertDatabaseHas('products', ['id' => $product->id]);
    }
}
