<?php

namespace Tests\Feature\Storefront;

use App\Models\Category;
use App\Models\Tenant;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Tests\Concerns\InteractsWithCommerceSchema;
use Tests\TestCase;

/**
 * Covers the reported bug: "delete default categories, create new ones —
 * the deleted ones may still show, the new ones may not." Storefront
 * category queries were already correctly filtering `is_active = 1` and
 * `Category::destroy()` was already a real hard delete (no soft-delete
 * column exists), so a genuinely deleted category could never reappear —
 * but `HomeController::index()`'s category query had a `LIMIT 12` with NO
 * `ORDER BY`, which MySQL does not guarantee any particular row order for;
 * a tenant with more than 12 active categories could see a newly created
 * one silently excluded (or the visible set shuffle between requests).
 * Fixed with an explicit `whereNull('parent_id')->orderBy('id')`.
 */
class StorefrontCategoryVisibilityTest extends TestCase
{
    use InteractsWithCommerceSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCommerceSchema();
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    protected function storeUrl(Tenant $tenant, string $path = ''): string
    {
        return '/shop/'.$tenant->subdomain.($path ? '/'.$path : '');
    }

    public function test_a_deleted_category_never_appears_on_the_homepage(): void
    {
        $tenant = $this->makeTenant();
        app()->instance('currentTenant', $tenant);
        $category = Category::create(['tenant_id' => $tenant->id, 'name' => 'Discontinued Line', 'is_active' => 1]);
        $category->delete();
        app()->forgetInstance('currentTenant');

        $response = $this->get($this->storeUrl($tenant));

        $response->assertOk();
        $response->assertDontSee('Discontinued Line');
    }

    public function test_a_newly_created_category_appears_on_the_homepage_immediately(): void
    {
        $tenant = $this->makeTenant();
        app()->instance('currentTenant', $tenant);
        Category::create(['tenant_id' => $tenant->id, 'name' => 'Brand New Category', 'is_active' => 1]);
        app()->forgetInstance('currentTenant');

        $response = $this->get($this->storeUrl($tenant));

        $response->assertOk();
        $response->assertSee('Brand New Category');
    }

    /** The 12-category homepage cap must reliably include the 12 lowest-id (oldest) active categories on every request, not a nondeterministic subset. */
    public function test_homepage_category_nav_is_deterministic_across_repeated_requests_when_over_the_cap(): void
    {
        $tenant = $this->makeTenant();
        app()->instance('currentTenant', $tenant);
        for ($i = 1; $i <= 15; $i++) {
            Category::create(['tenant_id' => $tenant->id, 'name' => "Category {$i}", 'is_active' => 1]);
        }
        app()->forgetInstance('currentTenant');

        $first = $this->get($this->storeUrl($tenant))->getContent();
        $second = $this->get($this->storeUrl($tenant))->getContent();

        $this->assertSame($first, $second);
        // Deterministic low-id-first order means the 13th-15th (newest)
        // categories are the ones excluded from the capped nav, not an
        // arbitrary rotating subset.
        $this->assertStringContainsString('Category 1<', $first);
        $this->assertStringContainsString('Category 12<', $first);
        $this->assertStringNotContainsString('Category 13<', $first);
    }

    /** An inactive category must never show, even before the 12-item cap is reached. */
    public function test_inactive_category_never_appears_on_the_homepage(): void
    {
        $tenant = $this->makeTenant();
        app()->instance('currentTenant', $tenant);
        Category::create(['tenant_id' => $tenant->id, 'name' => 'Hidden Category', 'is_active' => 0]);
        app()->forgetInstance('currentTenant');

        $response = $this->get($this->storeUrl($tenant));

        $response->assertOk();
        $response->assertDontSee('Hidden Category');
    }

    /** A subcategory belongs under its parent, not in this flat top-level nav. */
    public function test_a_subcategory_does_not_appear_in_the_flat_homepage_nav(): void
    {
        $tenant = $this->makeTenant();
        app()->instance('currentTenant', $tenant);
        $parent = Category::create(['tenant_id' => $tenant->id, 'name' => 'Skincare', 'is_active' => 1]);
        Category::create(['tenant_id' => $tenant->id, 'name' => 'Moisturizers', 'parent_id' => $parent->id, 'is_active' => 1]);
        app()->forgetInstance('currentTenant');

        $response = $this->get($this->storeUrl($tenant));

        $response->assertOk();
        $response->assertSee('Skincare');
        $response->assertDontSee('Moisturizers');
    }

    /** Tenant isolation on the storefront's category nav. */
    public function test_another_tenants_category_never_appears(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        app()->instance('currentTenant', $tenantA);
        Category::create(['tenant_id' => $tenantA->id, 'name' => 'Tenant A Category', 'is_active' => 1]);
        app()->forgetInstance('currentTenant');

        $response = $this->get($this->storeUrl($tenantB));

        $response->assertOk();
        $response->assertDontSee('Tenant A Category');
    }
}
