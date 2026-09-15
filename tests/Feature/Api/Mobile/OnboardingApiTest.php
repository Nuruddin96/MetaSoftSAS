<?php

namespace Tests\Feature\Api\Mobile;

use App\Models\BusinessType;
use App\Models\Category;
use App\Models\StoreSetting;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithApiSchema;
use Tests\TestCase;

/**
 * Covers Api\Mobile\OnboardingController — the mobile mirror of the web
 * tenant.onboarding.* routes, same App\Services\Tenant\TenantOnboardingService
 * underneath. See that service's docblock for the idempotency guarantees
 * asserted here (seeding only once, resumable step).
 */
class OnboardingApiTest extends TestCase
{
    use InteractsWithApiSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpApiSchema();
    }

    protected function makeBusinessType(array $attrs = []): BusinessType
    {
        return BusinessType::create(array_merge([
            'slug' => 'skincare',
            'name_bn' => 'স্কিনকেয়ার',
            'name_en' => 'Skin Care',
            'icon' => '💄',
            'default_attributes' => ['Size', 'ML'],
            'sort_order' => 10,
            'is_active' => 1,
        ], $attrs));
    }

    public function test_status_reports_needs_onboarding_for_a_fresh_tenant(): void
    {
        $tenant = $this->makeTenant(['onboarding_completed_at' => null]);
        $user = $this->makeUser($tenant->id);
        $this->makeBusinessType();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/mobile/v1/onboarding')->assertOk();

        $response->assertJsonPath('needs_onboarding', true)
            ->assertJsonPath('step', 'business_type');
        $this->assertNotEmpty($response->json('business_types'));
    }

    public function test_status_reports_no_onboarding_needed_for_an_existing_tenant(): void
    {
        // makeTenant() defaults onboarding_completed_at to "already done" —
        // this is the regression guard for every pre-existing tenant.
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        Sanctum::actingAs($user);

        $this->getJson('/api/mobile/v1/onboarding')->assertOk()
            ->assertJsonPath('needs_onboarding', false);
    }

    public function test_saving_business_type_seeds_default_categories_and_advances_step(): void
    {
        $tenant = $this->makeTenant(['onboarding_completed_at' => null]);
        $user = $this->makeUser($tenant->id);
        $businessType = $this->makeBusinessType();
        $businessType->categories()->create(['name' => 'Face Care', 'sort_order' => 10]);
        $businessType->categories()->create(['name' => 'Body Care', 'sort_order' => 20]);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/mobile/v1/onboarding/business-type', [
            'business_type_id' => $businessType->id,
        ])->assertOk();

        $response->assertJsonPath('step', 'business_info');
        $this->assertDatabaseHas('tenants', ['id' => $tenant->id, 'business_type_id' => $businessType->id]);

        app()->instance('currentTenant', $tenant->fresh());
        $this->assertSame(2, Category::count());
        $this->assertTrue(Category::where('name', 'Face Care')->exists());
        app()->forgetInstance('currentTenant');
    }

    public function test_saving_business_type_never_reseeds_categories_the_tenant_already_has(): void
    {
        $tenant = $this->makeTenant(['onboarding_completed_at' => null]);
        $user = $this->makeUser($tenant->id);
        $businessType = $this->makeBusinessType();
        $businessType->categories()->create(['name' => 'Face Care', 'sort_order' => 10]);

        app()->instance('currentTenant', $tenant);
        Category::create(['tenant_id' => $tenant->id, 'name' => 'My Own Category']);
        app()->forgetInstance('currentTenant');

        Sanctum::actingAs($user);

        $this->postJson('/api/mobile/v1/onboarding/business-type', [
            'business_type_id' => $businessType->id,
        ])->assertOk();

        app()->instance('currentTenant', $tenant->fresh());
        $this->assertSame(1, Category::count());
        $this->assertSame('My Own Category', Category::first()->name);
        app()->forgetInstance('currentTenant');
    }

    public function test_business_info_step_saves_only_provided_optional_fields(): void
    {
        $tenant = $this->makeTenant(['onboarding_completed_at' => null]);
        $user = $this->makeUser($tenant->id);
        Sanctum::actingAs($user);

        $this->postJson('/api/mobile/v1/onboarding/business-info', [
            'footer_address' => 'House 12, Road 5, Dhaka',
        ])->assertOk()->assertJsonPath('step', 'categories');

        app()->instance('currentTenant', $tenant);
        $this->assertSame('House 12, Road 5, Dhaka', StoreSetting::where('key', 'footer_address')->value('value'));
        $this->assertNull(StoreSetting::where('key', 'social_facebook')->value('value'));
        app()->forgetInstance('currentTenant');
    }

    public function test_first_product_step_creates_a_minimal_product_and_completes_the_wizard(): void
    {
        $tenant = $this->makeTenant(['onboarding_completed_at' => null, 'onboarding_step' => 'first_product']);
        $user = $this->makeUser($tenant->id);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/mobile/v1/onboarding/first-product', [
            'name' => 'Test Lipstick',
            'selling_price' => 350,
        ])->assertOk();

        $response->assertJsonPath('step', 'complete');
        $this->assertDatabaseHas('products', ['tenant_id' => $tenant->id, 'name' => 'Test Lipstick']);
        $this->assertDatabaseHas('product_variants', ['tenant_id' => $tenant->id, 'selling_price' => 350]);
    }

    public function test_skip_first_product_advances_without_creating_a_product(): void
    {
        $tenant = $this->makeTenant(['onboarding_completed_at' => null, 'onboarding_step' => 'first_product']);
        $user = $this->makeUser($tenant->id);
        Sanctum::actingAs($user);

        $this->postJson('/api/mobile/v1/onboarding/first-product/skip')
            ->assertOk()->assertJsonPath('step', 'complete');

        $this->assertDatabaseCount('products', 0);
    }

    public function test_complete_marks_the_tenant_as_no_longer_needing_onboarding(): void
    {
        $tenant = $this->makeTenant(['onboarding_completed_at' => null, 'onboarding_step' => 'complete']);
        $user = $this->makeUser($tenant->id);
        Sanctum::actingAs($user);

        $this->postJson('/api/mobile/v1/onboarding/complete')->assertOk()->assertJsonPath('completed', true);

        $this->assertFalse($tenant->fresh()->needsOnboarding());
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/mobile/v1/onboarding')->assertUnauthorized();
    }
}
