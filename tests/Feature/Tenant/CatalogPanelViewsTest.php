<?php

namespace Tests\Feature\Tenant;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\Tenant;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Tests\Concerns\InteractsWithCommerceSchema;
use Tests\TestCase;

/**
 * Catalog Architecture project: Blade view smoke tests — a syntax error in
 * a .blade.php file only surfaces at render time, not at `php -l` (which
 * only sees compiled PHP embedded in raw HTML/directives), so these
 * actually GET the panel pages the new subcategory/attribute UI lives on.
 */
class CatalogPanelViewsTest extends TestCase
{
    use InteractsWithCommerceSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCommerceSchema();
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    protected function panelUrl(Tenant $tenant, string $path): string
    {
        return '/shop/'.$tenant->subdomain.'/panel/'.$path;
    }

    public function test_categories_page_renders_with_top_level_and_subcategories(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        app()->instance('currentTenant', $tenant);
        $parent = Category::create(['tenant_id' => $tenant->id, 'name' => 'Face Care']);
        Category::create(['tenant_id' => $tenant->id, 'name' => 'Cleanser', 'parent_id' => $parent->id]);

        $response = $this->actingAs($user, 'tenant')->get($this->panelUrl($tenant, 'categories'));

        $response->assertOk();
        $response->assertSee('Face Care');
        $response->assertSee('Cleanser');
    }

    public function test_categories_page_renders_with_zero_categories(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);

        $this->actingAs($user, 'tenant')->get($this->panelUrl($tenant, 'categories'))->assertOk();
    }

    public function test_attributes_page_renders_with_values(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $attribute = ProductAttribute::create(['tenant_id' => $tenant->id, 'name' => 'Color']);
        $attribute->values()->create(['value' => 'Red']);

        $response = $this->actingAs($user, 'tenant')->get($this->panelUrl($tenant, 'attributes'));

        $response->assertOk();
        $response->assertSee('Color');
        $response->assertSee('Red');
    }

    public function test_attributes_page_renders_with_zero_attributes(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);

        $this->actingAs($user, 'tenant')->get($this->panelUrl($tenant, 'attributes'))->assertOk();
    }

    public function test_categories_index_never_exposes_another_tenants_category(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $otherTenant = $this->makeTenant();
        Category::create(['tenant_id' => $otherTenant->id, 'name' => 'Not Yours']);

        $response = $this->actingAs($user, 'tenant')->get($this->panelUrl($tenant, 'categories'));

        $response->assertOk()->assertDontSee('Not Yours');
    }

    public function test_product_form_page_still_renders_after_category_model_change(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);

        $this->actingAs($user, 'tenant')->get($this->panelUrl($tenant, 'products/create'))->assertOk();
    }

    /**
     * Catalog Architecture project — the edit form's variant row now
     * renders a real delete form (products.variants.destroy) for any row
     * with an existing id, instead of the old JS-only "remove from DOM"
     * button; this only fully compiles/renders when a product actually
     * has a saved variant, which no prior test exercised.
     */
    public function test_product_edit_form_renders_with_an_existing_variant(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        app()->instance('currentTenant', $tenant);
        $product = Product::create(['tenant_id' => $tenant->id, 'name' => 'Test Shirt']);
        $product->variants()->create([
            'tenant_id' => $tenant->id, 'variant_name' => 'M', 'selling_price' => 500,
            'attributes' => ['Size' => 'M', 'Color' => 'Blue'],
        ]);

        $response = $this->actingAs($user, 'tenant')->get($this->panelUrl($tenant, "products/{$product->id}/edit"));

        $response->assertOk()->assertSee('M');
    }
}
