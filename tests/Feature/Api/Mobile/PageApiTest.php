<?php

namespace Tests\Feature\Api\Mobile;

use App\Models\Page;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithApiSchema;
use Tests\TestCase;

class PageApiTest extends TestCase
{
    use InteractsWithApiSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpApiSchema();
    }

    public function test_index_lists_only_this_tenants_pages_ordered_by_sort_order(): void
    {
        $tenant = $this->makeTenant();
        $other = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        Sanctum::actingAs($user);
        app()->instance('currentTenant', $tenant);

        Page::create(['tenant_id' => $tenant->id, 'title' => 'Second', 'slug' => 'second', 'sort_order' => 2]);
        Page::create(['tenant_id' => $tenant->id, 'title' => 'First', 'slug' => 'first', 'sort_order' => 1]);
        Page::create(['tenant_id' => $other->id, 'title' => 'Not mine', 'slug' => 'not-mine', 'sort_order' => 0]);

        $response = $this->getJson('/api/mobile/v1/settings/pages');

        $response->assertOk()->assertJsonCount(2, 'data');
        $this->assertSame('First', $response->json('data.0.title'));
        $this->assertSame('Second', $response->json('data.1.title'));
    }

    public function test_store_creates_a_page_with_an_auto_generated_slug(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        Sanctum::actingAs($user);
        app()->instance('currentTenant', $tenant);

        $response = $this->postJson('/api/mobile/v1/settings/pages', [
            'title' => 'প্রাইভেসি পলিসি',
            'content' => 'আমরা আপনার তথ্য সুরক্ষিত রাখি।',
            'show_in_footer' => true,
        ]);

        $response->assertCreated()
            ->assertJsonPath('title', 'প্রাইভেসি পলিসি')
            ->assertJsonPath('show_in_footer', true)
            ->assertJsonPath('is_active', true);
        $this->assertNotNull($response->json('slug'));
        $this->assertDatabaseHas('pages', ['tenant_id' => $tenant->id, 'title' => 'প্রাইভেসি পলিসি']);
    }

    public function test_store_requires_a_title(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        Sanctum::actingAs($user);
        app()->instance('currentTenant', $tenant);

        $this->postJson('/api/mobile/v1/settings/pages', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('title');
    }

    public function test_update_edits_an_existing_page(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        Sanctum::actingAs($user);
        app()->instance('currentTenant', $tenant);

        $page = Page::create(['tenant_id' => $tenant->id, 'title' => 'About', 'slug' => 'about', 'sort_order' => 0]);

        $response = $this->patchJson("/api/mobile/v1/settings/pages/{$page->id}", [
            'title' => 'About Us',
            'is_active' => false,
        ]);

        $response->assertOk()
            ->assertJsonPath('title', 'About Us')
            ->assertJsonPath('is_active', false);
    }

    public function test_update_rejects_another_tenants_page(): void
    {
        $tenant = $this->makeTenant();
        $other = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        Sanctum::actingAs($user);
        app()->instance('currentTenant', $tenant);

        $page = Page::create(['tenant_id' => $other->id, 'title' => 'About', 'slug' => 'about', 'sort_order' => 0]);

        $this->patchJson("/api/mobile/v1/settings/pages/{$page->id}", ['title' => 'Hijack'])
            ->assertStatus(404);
    }

    public function test_destroy_removes_the_page(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        Sanctum::actingAs($user);
        app()->instance('currentTenant', $tenant);

        $page = Page::create(['tenant_id' => $tenant->id, 'title' => 'About', 'slug' => 'about', 'sort_order' => 0]);

        $this->deleteJson("/api/mobile/v1/settings/pages/{$page->id}")
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertDatabaseMissing('pages', ['id' => $page->id]);
    }

    public function test_destroy_rejects_another_tenants_page(): void
    {
        $tenant = $this->makeTenant();
        $other = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        Sanctum::actingAs($user);
        app()->instance('currentTenant', $tenant);

        $page = Page::create(['tenant_id' => $other->id, 'title' => 'About', 'slug' => 'about', 'sort_order' => 0]);

        $this->deleteJson("/api/mobile/v1/settings/pages/{$page->id}")->assertStatus(404);
    }
}
