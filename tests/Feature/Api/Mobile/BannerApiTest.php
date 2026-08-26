<?php

namespace Tests\Feature\Api\Mobile;

use App\Models\Banner;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithApiSchema;
use Tests\TestCase;

class BannerApiTest extends TestCase
{
    use InteractsWithApiSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpApiSchema();
    }

    public function test_index_lists_only_this_tenants_banners_ordered_by_sort_order(): void
    {
        $tenant = $this->makeTenant();
        $other = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        Sanctum::actingAs($user);
        app()->instance('currentTenant', $tenant);

        Banner::create(['tenant_id' => $tenant->id, 'title' => 'Second', 'sort_order' => 2, 'is_active' => 1]);
        Banner::create(['tenant_id' => $tenant->id, 'title' => 'First', 'sort_order' => 1, 'is_active' => 1]);
        Banner::create(['tenant_id' => $other->id, 'title' => 'Not mine', 'sort_order' => 0, 'is_active' => 1]);

        $response = $this->getJson('/api/mobile/v1/settings/banners');

        $response->assertOk()->assertJsonCount(2, 'data');
        $this->assertSame('First', $response->json('data.0.title'));
        $this->assertSame('Second', $response->json('data.1.title'));
    }

    public function test_store_uploads_image_and_saves_optional_fields(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        Sanctum::actingAs($user);
        app()->instance('currentTenant', $tenant);
        \Illuminate\Support\Facades\Storage::fake('public');

        $response = $this->postJson('/api/mobile/v1/settings/banners', [
            'image' => UploadedFile::fake()->image('banner.jpg', 1600, 600),
            'title' => 'Eid Sale',
            'button_text' => 'কিনুন',
            'button_link' => '/products',
        ]);

        $response->assertCreated()
            ->assertJsonPath('title', 'Eid Sale')
            ->assertJsonPath('button_text', 'কিনুন');
        $this->assertNotNull($response->json('image_url'));
        $this->assertDatabaseHas('banners', ['tenant_id' => $tenant->id, 'title' => 'Eid Sale']);
    }

    public function test_store_requires_an_image(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        Sanctum::actingAs($user);
        app()->instance('currentTenant', $tenant);

        $this->postJson('/api/mobile/v1/settings/banners', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('image');
    }

    public function test_destroy_removes_the_banner_and_its_image(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        Sanctum::actingAs($user);
        app()->instance('currentTenant', $tenant);
        \Illuminate\Support\Facades\Storage::fake('public');

        $banner = Banner::create([
            'tenant_id' => $tenant->id,
            'image_path' => 'banners/'.$tenant->id.'/x.jpg',
            'sort_order' => 0,
            'is_active' => 1,
        ]);

        $this->deleteJson("/api/mobile/v1/settings/banners/{$banner->id}")
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertDatabaseMissing('banners', ['id' => $banner->id]);
    }

    public function test_destroy_rejects_another_tenants_banner(): void
    {
        $tenant = $this->makeTenant();
        $other = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        Sanctum::actingAs($user);
        app()->instance('currentTenant', $tenant);

        $banner = Banner::create(['tenant_id' => $other->id, 'sort_order' => 0, 'is_active' => 1]);

        $this->deleteJson("/api/mobile/v1/settings/banners/{$banner->id}")->assertStatus(404);
    }
}
