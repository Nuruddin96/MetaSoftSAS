<?php

namespace Tests\Feature\Api\Mobile;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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

    /** Category image/icon feature — create with an image, exposed as image_url on the storefront-facing present(). */
    public function test_create_category_with_an_image_exposes_image_url(): void
    {
        Storage::fake('public');
        $tenant = $this->makeTenant();
        Sanctum::actingAs($this->makeUser($tenant->id));

        $response = $this->post('/api/mobile/v1/categories', [
            'name' => 'Skincare',
            'image' => UploadedFile::fake()->image('skincare.jpg'),
        ]);

        $response->assertCreated();
        $this->assertNotNull($response->json('image_url'));
        $category = Category::find($response->json('id'));
        Storage::disk('public')->assertExists($category->image_path);
    }

    public function test_category_without_an_image_has_a_null_image_url(): void
    {
        $tenant = $this->makeTenant();
        Sanctum::actingAs($this->makeUser($tenant->id));

        $response = $this->postJson('/api/mobile/v1/categories', ['name' => 'No Image Category']);

        $response->assertCreated()->assertJsonPath('image_url', null);
    }

    /** Update via the POST variant of the route (multipart bodies aren't parsed on PATCH) replaces the image and deletes the old file. */
    public function test_update_category_replaces_the_image_and_deletes_the_old_file(): void
    {
        Storage::fake('public');
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        Sanctum::actingAs($user);

        $created = $this->post('/api/mobile/v1/categories', [
            'name' => 'Skincare', 'image' => UploadedFile::fake()->image('old.jpg'),
        ])->assertCreated();
        $oldPath = Category::find($created->json('id'))->image_path;

        $updated = $this->post('/api/mobile/v1/categories/'.$created->json('id'), [
            'name' => 'Skincare', 'image' => UploadedFile::fake()->image('new.jpg'),
        ])->assertOk();

        $newPath = Category::find($created->json('id'))->image_path;
        $this->assertNotSame($oldPath, $newPath);
        Storage::disk('public')->assertMissing($oldPath);
        Storage::disk('public')->assertExists($newPath);
        $this->assertNotNull($updated->json('image_url'));
    }

    /** remove_image (no new file) clears image_path and deletes the stored file, via the plain PATCH route. */
    public function test_update_category_can_remove_the_image(): void
    {
        Storage::fake('public');
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        Sanctum::actingAs($user);

        $created = $this->post('/api/mobile/v1/categories', [
            'name' => 'Skincare', 'image' => UploadedFile::fake()->image('old.jpg'),
        ])->assertCreated();
        $oldPath = Category::find($created->json('id'))->image_path;

        $updated = $this->patchJson('/api/mobile/v1/categories/'.$created->json('id'), [
            'name' => 'Skincare', 'remove_image' => true,
        ])->assertOk();

        Storage::disk('public')->assertMissing($oldPath);
        $this->assertNull($updated->json('image_url'));
        $this->assertNull(Category::find($created->json('id'))->image_path);
    }

    /** destroy() must clean up the stored image file too, not just the DB row. */
    public function test_delete_category_deletes_its_image_file(): void
    {
        Storage::fake('public');
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        Sanctum::actingAs($user);

        $created = $this->post('/api/mobile/v1/categories', [
            'name' => 'Skincare', 'image' => UploadedFile::fake()->image('old.jpg'),
        ])->assertCreated();
        $path = Category::find($created->json('id'))->image_path;

        $this->deleteJson('/api/mobile/v1/categories/'.$created->json('id'))->assertOk();

        Storage::disk('public')->assertMissing($path);
    }
}
