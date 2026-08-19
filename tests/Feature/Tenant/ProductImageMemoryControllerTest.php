<?php

namespace Tests\Feature\Tenant;

use App\Models\Tenant;
use App\Models\TenantProductImage;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithAiAgentSchema;
use Tests\TestCase;

/**
 * "পণ্যের ছবি" (Product Image Memory) tenant-side CRUD (App\Http\
 * Controllers\Tenant\ProductImageMemoryController). Mirrors
 * AiMemoryControllerTest closely — isolation is enforced the same way,
 * by implicit route binding through App\Traits\BelongsToTenant::
 * resolveRouteBinding().
 */
class ProductImageMemoryControllerTest extends TestCase
{
    use InteractsWithAiAgentSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpAiAgentSchema();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        Storage::fake('public');
    }

    protected function panelUrl(Tenant $tenant, string $path): string
    {
        return '/shop/'.$tenant->subdomain.'/panel/'.$path;
    }

    public function test_a_tenant_can_save_a_product_image(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);

        $response = $this->actingAs($user, 'tenant')->post($this->panelUrl($tenant, 'product-image-memory'), [
            'product_name' => 'Brilliant Skin Rejuvenating Set',
            'image' => UploadedFile::fake()->image('product.jpg'),
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('tenant_product_images', [
            'tenant_id' => $tenant->id,
            'product_name' => 'Brilliant Skin Rejuvenating Set',
        ]);

        $path = TenantProductImage::withoutGlobalScopes()->first()->image_path;
        $this->assertStringStartsWith('product-image-memory/'.$tenant->id, $path);
        Storage::disk('public')->assertExists($path);
    }

    public function test_product_name_is_required(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);

        $response = $this->actingAs($user, 'tenant')->post($this->panelUrl($tenant, 'product-image-memory'), [
            'image' => UploadedFile::fake()->image('product.jpg'),
        ]);

        $response->assertSessionHasErrors('product_name');
        $this->assertSame(0, TenantProductImage::withoutGlobalScopes()->count());
    }

    public function test_image_is_required_on_create(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);

        $response = $this->actingAs($user, 'tenant')->post($this->panelUrl($tenant, 'product-image-memory'), [
            'product_name' => 'Brilliant Skin Rejuvenating Set',
        ]);

        $response->assertSessionHasErrors('image');
        $this->assertSame(0, TenantProductImage::withoutGlobalScopes()->count());
    }

    public function test_a_non_image_file_is_rejected(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);

        $response = $this->actingAs($user, 'tenant')->post($this->panelUrl($tenant, 'product-image-memory'), [
            'product_name' => 'Brilliant Skin Rejuvenating Set',
            'image' => UploadedFile::fake()->create('not-an-image.pdf', 100),
        ]);

        $response->assertSessionHasErrors('image');
    }

    public function test_a_tenant_can_see_only_their_own_product_images(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        app()->instance('currentTenant', $tenantA);
        $imageA = TenantProductImage::create(['tenant_id' => $tenantA->id, 'product_name' => 'A', 'image_path' => 'x/a.jpg']);
        TenantProductImage::create(['tenant_id' => $tenantB->id, 'product_name' => 'B', 'image_path' => 'x/b.jpg']);

        $visible = TenantProductImage::orderByDesc('id')->get();

        $this->assertCount(1, $visible);
        $this->assertSame($imageA->id, $visible->first()->id);
    }

    public function test_a_tenant_can_edit_the_product_name_without_replacing_the_image(): void
    {
        $tenant = $this->makeTenant();
        $image = TenantProductImage::create(['tenant_id' => $tenant->id, 'product_name' => 'Old Name', 'image_path' => 'product-image-memory/1/old.jpg']);
        $user = $this->makeUser($tenant->id);

        $response = $this->actingAs($user, 'tenant')->put($this->panelUrl($tenant, "product-image-memory/{$image->id}"), [
            'product_name' => 'New Name',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('tenant_product_images', [
            'id' => $image->id,
            'product_name' => 'New Name',
            'image_path' => 'product-image-memory/1/old.jpg',
        ]);
    }

    public function test_replacing_the_image_removes_the_old_file(): void
    {
        $tenant = $this->makeTenant();
        Storage::disk('public')->put('product-image-memory/1/old.jpg', 'fake-old-bytes');
        $image = TenantProductImage::create(['tenant_id' => $tenant->id, 'product_name' => 'Name', 'image_path' => 'product-image-memory/1/old.jpg']);
        $user = $this->makeUser($tenant->id);

        $this->actingAs($user, 'tenant')->put($this->panelUrl($tenant, "product-image-memory/{$image->id}"), [
            'product_name' => 'Name',
            'image' => UploadedFile::fake()->image('new.jpg'),
        ]);

        Storage::disk('public')->assertMissing('product-image-memory/1/old.jpg');
        $newPath = TenantProductImage::withoutGlobalScopes()->find($image->id)->image_path;
        $this->assertNotSame('product-image-memory/1/old.jpg', $newPath);
        Storage::disk('public')->assertExists($newPath);
    }

    public function test_deleting_a_product_image_removes_the_file(): void
    {
        $tenant = $this->makeTenant();
        Storage::disk('public')->put('product-image-memory/1/x.jpg', 'fake-bytes');
        $image = TenantProductImage::create(['tenant_id' => $tenant->id, 'product_name' => 'Name', 'image_path' => 'product-image-memory/1/x.jpg']);
        $user = $this->makeUser($tenant->id);

        $response = $this->actingAs($user, 'tenant')->delete($this->panelUrl($tenant, "product-image-memory/{$image->id}"));

        $response->assertRedirect();
        $this->assertDatabaseMissing('tenant_product_images', ['id' => $image->id]);
        Storage::disk('public')->assertMissing('product-image-memory/1/x.jpg');
    }

    public function test_a_tenant_cannot_edit_another_tenants_product_image(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $userB = $this->makeUser($tenantB->id);
        $imageA = TenantProductImage::create(['tenant_id' => $tenantA->id, 'product_name' => 'A Name', 'image_path' => 'x/a.jpg']);

        $response = $this->actingAs($userB, 'tenant')->put($this->panelUrl($tenantB, "product-image-memory/{$imageA->id}"), [
            'product_name' => 'Hijacked Name',
        ]);

        $response->assertNotFound();
        $this->assertDatabaseHas('tenant_product_images', ['id' => $imageA->id, 'product_name' => 'A Name']);
    }

    public function test_a_tenant_cannot_delete_another_tenants_product_image(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $userB = $this->makeUser($tenantB->id);
        $imageA = TenantProductImage::create(['tenant_id' => $tenantA->id, 'product_name' => 'A Name', 'image_path' => 'x/a.jpg']);

        $response = $this->actingAs($userB, 'tenant')->delete($this->panelUrl($tenantB, "product-image-memory/{$imageA->id}"));

        $response->assertNotFound();
        $this->assertDatabaseHas('tenant_product_images', ['id' => $imageA->id]);
    }
}
