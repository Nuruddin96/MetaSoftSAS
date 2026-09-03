<?php

namespace Tests\Feature\Api\Mobile;

use App\Models\TenantProductImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithAiAgentSchema;
use Tests\TestCase;

/**
 * Covers Api\Mobile\ProductImageMemoryController — mirrors
 * Tenant\ProductImageMemoryController exactly (see that controller's
 * docblock and tests/Feature/Tenant/ProductImageMemoryControllerTest.php
 * for the behavior this mirrors).
 */
class ProductImageMemoryApiTest extends TestCase
{
    use InteractsWithAiAgentSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAiAgentSchema();
        Storage::fake('public');
    }

    public function test_index_lists_only_this_tenants_images_newest_first(): void
    {
        $tenant = $this->makeTenant();
        $other = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        Sanctum::actingAs($user);
        app()->instance('currentTenant', $tenant);

        TenantProductImage::create(['tenant_id' => $tenant->id, 'product_name' => 'First', 'image_path' => 'x/1.jpg']);
        TenantProductImage::create(['tenant_id' => $tenant->id, 'product_name' => 'Second', 'image_path' => 'x/2.jpg']);
        TenantProductImage::create(['tenant_id' => $other->id, 'product_name' => 'Not mine', 'image_path' => 'x/3.jpg']);

        $response = $this->getJson('/api/mobile/v1/product-image-memory')->assertOk();

        $this->assertCount(2, $response->json('data'));
        $this->assertSame('Second', $response->json('data.0.product_name'));
        $this->assertSame('First', $response->json('data.1.product_name'));
    }

    public function test_store_saves_a_product_image(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        Sanctum::actingAs($user);
        app()->instance('currentTenant', $tenant);

        $response = $this->postJson('/api/mobile/v1/product-image-memory', [
            'product_name' => 'Brilliant Skin Rejuvenating Set',
            'image' => UploadedFile::fake()->image('product.jpg'),
        ]);

        $response->assertCreated()->assertJsonPath('product_name', 'Brilliant Skin Rejuvenating Set');
        $this->assertNotNull($response->json('image_url'));

        $path = TenantProductImage::withoutGlobalScopes()->first()->image_path;
        $this->assertStringStartsWith('product-image-memory/'.$tenant->id, $path);
        Storage::disk('public')->assertExists($path);
    }

    public function test_store_requires_a_product_name(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        Sanctum::actingAs($user);
        app()->instance('currentTenant', $tenant);

        $this->postJson('/api/mobile/v1/product-image-memory', ['image' => UploadedFile::fake()->image('product.jpg')])
            ->assertStatus(422)->assertJsonValidationErrors('product_name');
        $this->assertSame(0, TenantProductImage::withoutGlobalScopes()->count());
    }

    public function test_store_requires_an_image(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        Sanctum::actingAs($user);
        app()->instance('currentTenant', $tenant);

        $this->postJson('/api/mobile/v1/product-image-memory', ['product_name' => 'Name'])
            ->assertStatus(422)->assertJsonValidationErrors('image');
    }

    public function test_update_edits_the_product_name_without_replacing_the_image(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        Sanctum::actingAs($user);
        app()->instance('currentTenant', $tenant);
        $image = TenantProductImage::create(['tenant_id' => $tenant->id, 'product_name' => 'Old', 'image_path' => 'product-image-memory/'.$tenant->id.'/old.jpg']);

        $response = $this->postJson("/api/mobile/v1/product-image-memory/{$image->id}", ['product_name' => 'New']);

        $response->assertOk()->assertJsonPath('product_name', 'New');
        $this->assertDatabaseHas('tenant_product_images', ['id' => $image->id, 'image_path' => 'product-image-memory/'.$tenant->id.'/old.jpg']);
    }

    public function test_update_replacing_the_image_removes_the_old_file(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        Sanctum::actingAs($user);
        app()->instance('currentTenant', $tenant);
        Storage::disk('public')->put('product-image-memory/'.$tenant->id.'/old.jpg', 'fake-old-bytes');
        $image = TenantProductImage::create(['tenant_id' => $tenant->id, 'product_name' => 'Name', 'image_path' => 'product-image-memory/'.$tenant->id.'/old.jpg']);

        $this->postJson("/api/mobile/v1/product-image-memory/{$image->id}", [
            'product_name' => 'Name',
            'image' => UploadedFile::fake()->image('new.jpg'),
        ])->assertOk();

        Storage::disk('public')->assertMissing('product-image-memory/'.$tenant->id.'/old.jpg');
        $newPath = TenantProductImage::withoutGlobalScopes()->find($image->id)->image_path;
        $this->assertNotSame('product-image-memory/'.$tenant->id.'/old.jpg', $newPath);
        Storage::disk('public')->assertExists($newPath);
    }

    public function test_update_rejects_another_tenants_image(): void
    {
        $tenant = $this->makeTenant();
        $other = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        Sanctum::actingAs($user);
        app()->instance('currentTenant', $other);
        $image = TenantProductImage::create(['tenant_id' => $other->id, 'product_name' => 'A', 'image_path' => 'x/a.jpg']);
        app()->instance('currentTenant', $tenant);

        $this->postJson("/api/mobile/v1/product-image-memory/{$image->id}", ['product_name' => 'Hijack'])
            ->assertStatus(404);
    }

    public function test_destroy_removes_the_image_and_file(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        Sanctum::actingAs($user);
        app()->instance('currentTenant', $tenant);
        Storage::disk('public')->put('product-image-memory/'.$tenant->id.'/x.jpg', 'fake-bytes');
        $image = TenantProductImage::create(['tenant_id' => $tenant->id, 'product_name' => 'Name', 'image_path' => 'product-image-memory/'.$tenant->id.'/x.jpg']);

        $this->deleteJson("/api/mobile/v1/product-image-memory/{$image->id}")->assertOk()->assertJsonPath('ok', true);

        $this->assertDatabaseMissing('tenant_product_images', ['id' => $image->id]);
        Storage::disk('public')->assertMissing('product-image-memory/'.$tenant->id.'/x.jpg');
    }

    public function test_destroy_rejects_another_tenants_image(): void
    {
        $tenant = $this->makeTenant();
        $other = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        Sanctum::actingAs($user);
        app()->instance('currentTenant', $other);
        $image = TenantProductImage::create(['tenant_id' => $other->id, 'product_name' => 'A', 'image_path' => 'x/a.jpg']);
        app()->instance('currentTenant', $tenant);

        $this->deleteJson("/api/mobile/v1/product-image-memory/{$image->id}")->assertStatus(404);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/mobile/v1/product-image-memory')->assertUnauthorized();
    }
}
