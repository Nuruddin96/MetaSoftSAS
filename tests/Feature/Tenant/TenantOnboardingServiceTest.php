<?php

namespace Tests\Feature\Tenant;

use App\Models\BusinessType;
use App\Models\Category;
use App\Models\StoreSetting;
use App\Services\Tenant\TenantOnboardingService;
use Tests\Concerns\InteractsWithCommerceSchema;
use Tests\TestCase;

/**
 * Covers App\Services\Tenant\TenantOnboardingService directly — the wizard's
 * state machine and the idempotency guarantees the feature spec requires:
 * default categories are seeded at most once per tenant, existing tenants
 * (backfilled onboarding_completed_at) are never treated as needing the
 * wizard, and every step's data lands in the SAME tables the rest of the
 * panel reads (store_settings, categories, tenants.business_type_id) —
 * never a parallel draft store.
 */
class TenantOnboardingServiceTest extends TestCase
{
    use InteractsWithCommerceSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCommerceSchema();
    }

    protected function service(): TenantOnboardingService
    {
        return app(TenantOnboardingService::class);
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

    public function test_a_pre_existing_tenant_never_needs_onboarding(): void
    {
        // makeTenant() defaults onboarding_completed_at to "already done" —
        // this is the exact regression this test documents.
        $tenant = $this->makeTenant();

        $this->assertTrue($this->service()->isComplete($tenant));
        $this->assertFalse($tenant->needsOnboarding());
    }

    public function test_a_freshly_registered_tenant_needs_onboarding_at_the_first_step(): void
    {
        $tenant = $this->makeTenant(['onboarding_completed_at' => null]);

        $this->assertTrue($tenant->needsOnboarding());
        $this->assertSame('business_type', $this->service()->currentStep($tenant));
    }

    public function test_saving_business_type_seeds_default_categories_once(): void
    {
        $tenant = $this->makeTenant(['onboarding_completed_at' => null]);
        app()->instance('currentTenant', $tenant);

        $businessType = $this->makeBusinessType();
        $businessType->categories()->create(['name' => 'Face Care', 'sort_order' => 10]);
        $businessType->categories()->create(['name' => 'Body Care', 'sort_order' => 20]);

        $this->service()->saveBusinessType($tenant, $businessType->id, null);

        $this->assertSame($businessType->id, $tenant->fresh()->business_type_id);
        $this->assertSame('business_info', $tenant->fresh()->onboarding_step);
        $this->assertSame(2, Category::count());
        $this->assertTrue(Category::where('name', 'Face Care')->exists());

        app()->forgetInstance('currentTenant');
    }

    public function test_seeding_never_duplicates_or_overwrites_a_tenants_own_categories(): void
    {
        $tenant = $this->makeTenant(['onboarding_completed_at' => null]);
        app()->instance('currentTenant', $tenant);

        Category::create(['tenant_id' => $tenant->id, 'name' => 'My Own Category']);

        $businessType = $this->makeBusinessType();
        $businessType->categories()->create(['name' => 'Face Care', 'sort_order' => 10]);

        $created = $this->service()->seedDefaultCategories($tenant, $businessType);

        $this->assertSame(0, $created);
        $this->assertSame(1, Category::count());
        $this->assertSame('My Own Category', Category::first()->name);

        app()->forgetInstance('currentTenant');
    }

    /**
     * Catalog Architecture project: CategoryController::store()/update() now
     * accept parent_id (see Category model's parent()/children()), so this
     * gap (documented in the old test this replaces) is closed — a
     * subcategory row is created with parent_id resolved from its
     * top-level sibling by name, in the SAME seeding pass.
     */
    public function test_seeding_creates_subcategories_with_parent_id_resolved_by_name(): void
    {
        $tenant = $this->makeTenant(['onboarding_completed_at' => null]);
        app()->instance('currentTenant', $tenant);

        $businessType = $this->makeBusinessType();
        $businessType->categories()->create(['name' => 'Face Care', 'sort_order' => 10]);
        $businessType->categories()->create(['name' => 'Cleanser', 'parent_name' => 'Face Care', 'sort_order' => 11]);

        $created = $this->service()->seedDefaultCategories($tenant, $businessType);

        $this->assertSame(2, $created);
        $faceCare = Category::where('name', 'Face Care')->firstOrFail();
        $cleanser = Category::where('name', 'Cleanser')->firstOrFail();
        $this->assertNull($faceCare->parent_id);
        $this->assertSame($faceCare->id, $cleanser->parent_id);

        app()->forgetInstance('currentTenant');
    }

    /** A subcategory row whose named parent isn't in this business type's own top-level set is skipped, not created as an orphaned top-level category. */
    public function test_seeding_skips_a_subcategory_whose_named_parent_does_not_exist(): void
    {
        $tenant = $this->makeTenant(['onboarding_completed_at' => null]);
        app()->instance('currentTenant', $tenant);

        $businessType = $this->makeBusinessType();
        $businessType->categories()->create(['name' => 'Orphan Sub', 'parent_name' => 'Nonexistent Parent', 'sort_order' => 10]);

        $created = $this->service()->seedDefaultCategories($tenant, $businessType);

        $this->assertSame(0, $created);
        $this->assertFalse(Category::where('name', 'Orphan Sub')->exists());

        app()->forgetInstance('currentTenant');
    }

    public function test_saving_business_type_seeds_default_attributes_from_business_type_once(): void
    {
        $tenant = $this->makeTenant(['onboarding_completed_at' => null]);
        app()->instance('currentTenant', $tenant);

        $businessType = $this->makeBusinessType(['default_attributes' => ['Size', 'ML']]);

        $this->service()->saveBusinessType($tenant, $businessType->id, null);

        $this->assertSame(2, \App\Models\ProductAttribute::count());
        $this->assertTrue(\App\Models\ProductAttribute::where('name', 'Size')->exists());

        app()->forgetInstance('currentTenant');
    }

    public function test_seeding_attributes_never_duplicates_a_tenants_own_attributes(): void
    {
        $tenant = $this->makeTenant(['onboarding_completed_at' => null]);
        app()->instance('currentTenant', $tenant);

        \App\Models\ProductAttribute::create(['tenant_id' => $tenant->id, 'name' => 'My Own Attribute']);

        $businessType = $this->makeBusinessType(['default_attributes' => ['Size', 'ML']]);
        $created = $this->service()->seedDefaultAttributes($tenant, $businessType);

        $this->assertSame(0, $created);
        $this->assertSame(1, \App\Models\ProductAttribute::count());

        app()->forgetInstance('currentTenant');
    }

    public function test_business_type_theme_is_applied_only_when_tenant_is_still_on_default_theme(): void
    {
        config(['themes.skincare' => ['name' => 'Skincare']]);
        $tenant = $this->makeTenant(['onboarding_completed_at' => null, 'theme' => 'default']);
        app()->instance('currentTenant', $tenant);
        $businessType = $this->makeBusinessType(['slug' => 'skincare']);

        $this->service()->saveBusinessType($tenant, $businessType->id, null);

        $this->assertSame('skincare', $tenant->fresh()->theme);
        app()->forgetInstance('currentTenant');
    }

    public function test_business_type_theme_never_overrides_a_theme_the_tenant_already_chose(): void
    {
        config(['themes.skincare' => ['name' => 'Skincare']]);
        $tenant = $this->makeTenant(['onboarding_completed_at' => null, 'theme' => 'fashion']);
        app()->instance('currentTenant', $tenant);
        $businessType = $this->makeBusinessType(['slug' => 'skincare']);

        $this->service()->saveBusinessType($tenant, $businessType->id, null);

        $this->assertSame('fashion', $tenant->fresh()->theme);
        app()->forgetInstance('currentTenant');
    }

    public function test_business_info_step_only_saves_provided_optional_fields(): void
    {
        $tenant = $this->makeTenant(['onboarding_completed_at' => null]);
        app()->instance('currentTenant', $tenant);

        $this->service()->saveBusinessInfo($tenant, ['footer_address' => 'Dhaka', 'social_facebook' => null]);

        $this->assertSame('Dhaka', StoreSetting::where('key', 'footer_address')->value('value'));
        $this->assertNull(StoreSetting::where('key', 'social_facebook')->value('value'));
        $this->assertSame('categories', $tenant->fresh()->onboarding_step);

        app()->forgetInstance('currentTenant');
    }

    public function test_complete_sets_the_completion_timestamp_and_final_step(): void
    {
        $tenant = $this->makeTenant(['onboarding_completed_at' => null]);

        $this->service()->complete($tenant);

        $tenant->refresh();
        $this->assertNotNull($tenant->onboarding_completed_at);
        $this->assertSame('complete', $tenant->onboarding_step);
        $this->assertFalse($tenant->needsOnboarding());
    }
}
