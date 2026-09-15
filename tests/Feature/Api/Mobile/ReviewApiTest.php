<?php

namespace Tests\Feature\Api\Mobile;

use App\Models\Review;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithApiSchema;
use Tests\TestCase;

class ReviewApiTest extends TestCase
{
    use InteractsWithApiSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpApiSchema();
    }

    public function test_index_lists_only_this_tenants_reviews_ordered_by_sort_order(): void
    {
        $tenant = $this->makeTenant();
        $other = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        Sanctum::actingAs($user);
        app()->instance('currentTenant', $tenant);

        Review::create(['tenant_id' => $tenant->id, 'customer_name' => 'Second', 'sort_order' => 2, 'is_active' => 1]);
        Review::create(['tenant_id' => $tenant->id, 'customer_name' => 'First', 'sort_order' => 1, 'is_active' => 1]);
        Review::create(['tenant_id' => $other->id, 'customer_name' => 'Not mine', 'sort_order' => 0, 'is_active' => 1]);

        $response = $this->getJson('/api/mobile/v1/settings/reviews');

        $response->assertOk()->assertJsonCount(2, 'data');
        $this->assertSame('First', $response->json('data.0.customer_name'));
        $this->assertSame('Second', $response->json('data.1.customer_name'));
    }

    public function test_store_saves_a_review_without_a_photo(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        Sanctum::actingAs($user);
        app()->instance('currentTenant', $tenant);

        $response = $this->postJson('/api/mobile/v1/settings/reviews', [
            'customer_name' => 'Karim',
            'review_text' => 'Great service!',
        ]);

        $response->assertCreated()
            ->assertJsonPath('customer_name', 'Karim')
            ->assertJsonPath('review_text', 'Great service!')
            ->assertJsonPath('photo_url', null);
        $this->assertDatabaseHas('reviews', ['tenant_id' => $tenant->id, 'customer_name' => 'Karim']);
    }

    public function test_store_uploads_an_optional_photo(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        Sanctum::actingAs($user);
        app()->instance('currentTenant', $tenant);
        \Illuminate\Support\Facades\Storage::fake('public');

        $response = $this->postJson('/api/mobile/v1/settings/reviews', [
            'customer_name' => 'Karim',
            'photo' => UploadedFile::fake()->image('customer.jpg'),
        ]);

        $response->assertCreated();
        $this->assertNotNull($response->json('photo_url'));
    }

    public function test_store_requires_a_customer_name(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        Sanctum::actingAs($user);
        app()->instance('currentTenant', $tenant);

        $this->postJson('/api/mobile/v1/settings/reviews', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('customer_name');
    }

    public function test_update_edits_text_and_replaces_photo(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        Sanctum::actingAs($user);
        app()->instance('currentTenant', $tenant);
        \Illuminate\Support\Facades\Storage::fake('public');

        $review = Review::create([
            'tenant_id' => $tenant->id,
            'customer_name' => 'Karim',
            'photo_path' => 'reviews/'.$tenant->id.'/old.jpg',
            'sort_order' => 0,
            'is_active' => 1,
        ]);

        $response = $this->postJson("/api/mobile/v1/settings/reviews/{$review->id}", [
            'customer_name' => 'Karim Updated',
            'photo' => UploadedFile::fake()->image('new.jpg'),
        ]);

        $response->assertOk()->assertJsonPath('customer_name', 'Karim Updated');
        $this->assertNotNull($response->json('photo_url'));
    }

    public function test_update_rejects_another_tenants_review(): void
    {
        $tenant = $this->makeTenant();
        $other = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        Sanctum::actingAs($user);
        app()->instance('currentTenant', $tenant);

        $review = Review::create(['tenant_id' => $other->id, 'customer_name' => 'X', 'sort_order' => 0, 'is_active' => 1]);

        $this->postJson("/api/mobile/v1/settings/reviews/{$review->id}", ['customer_name' => 'Hijack'])
            ->assertStatus(404);
    }

    public function test_destroy_removes_the_review_and_its_photo(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        Sanctum::actingAs($user);
        app()->instance('currentTenant', $tenant);
        \Illuminate\Support\Facades\Storage::fake('public');

        $review = Review::create([
            'tenant_id' => $tenant->id,
            'customer_name' => 'Karim',
            'photo_path' => 'reviews/'.$tenant->id.'/x.jpg',
            'sort_order' => 0,
            'is_active' => 1,
        ]);

        $this->deleteJson("/api/mobile/v1/settings/reviews/{$review->id}")
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertDatabaseMissing('reviews', ['id' => $review->id]);
    }

    public function test_destroy_rejects_another_tenants_review(): void
    {
        $tenant = $this->makeTenant();
        $other = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        Sanctum::actingAs($user);
        app()->instance('currentTenant', $tenant);

        $review = Review::create(['tenant_id' => $other->id, 'customer_name' => 'X', 'sort_order' => 0, 'is_active' => 1]);

        $this->deleteJson("/api/mobile/v1/settings/reviews/{$review->id}")->assertStatus(404);
    }
}
