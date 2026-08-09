<?php

namespace Tests\Feature\Security;

use App\Models\Banner;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\IncompleteOrder;
use App\Models\Order;
use App\Models\Page;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Tenant;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Tests\Concerns\InteractsWithCommerceSchema;
use Tests\TestCase;

/**
 * Regression suite for the implicit-route-model-binding tenant bypass.
 *
 * Root cause: Laravel's SubstituteBindings middleware (part of the
 * framework's 'web' group, applied to every route in routes/web.php) runs
 * BEFORE this app's own resolve.tenant middleware for tenant-prefixed
 * routes, so app()->bound('currentTenant') is still false at the moment
 * `{order}`/`{product}`/etc. are resolved — App\Traits\BelongsToTenant's
 * global scope silently adds no filter, and ANY tenant's row with that id
 * resolves. Fixed centrally by overriding resolveRouteBinding() in that
 * same trait (see its docblock) rather than touching any controller.
 *
 * Each test below covers one of the 9 affected models: proves tenant B
 * cannot reach tenant A's row via tenant B's own panel URL (404, row
 * untouched), and that tenant A can still reach its own row normally
 * (guards against the fix regressing into "404s everything").
 */
class CrossTenantRouteBindingTest extends TestCase
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

    /**
     * Runs $make() with currentTenant bound to $tenantId (so BelongsToTenant's
     * creating-hook auto-fill / global scope behave normally), then unbinds
     * it again — a real incoming request always starts with nothing bound,
     * and leaving this bound would make resolveRouteBinding()'s
     * app()->bound('currentTenant') check see a stale value from fixture
     * setup instead of exercising its TenantResolver::fromRequest()
     * fallback, defeating the point of these tests.
     */
    protected function asTenant(int $tenantId, \Closure $make)
    {
        app()->instance('currentTenant', Tenant::find($tenantId));

        try {
            return $make();
        } finally {
            app()->forgetInstance('currentTenant');
        }
    }

    public function test_order_show_is_tenant_isolated(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $userA = $this->makeUser($tenantA->id);
        $userB = $this->makeUser($tenantB->id);

        $orderA = $this->asTenant($tenantA->id, fn () => Order::create([
            'tenant_id' => $tenantA->id, 'source' => 'manual', 'channel' => 'website',
            'customer_name' => 'A cust', 'customer_phone' => '01711111111',
            'status' => 'pending', 'subtotal' => 0, 'total' => 0,
        ]));

        $this->actingAs($userB, 'tenant')->get($this->panelUrl($tenantB, 'orders/'.$orderA->id))
            ->assertStatus(404);

        $this->actingAs($userA, 'tenant')->get($this->panelUrl($tenantA, 'orders/'.$orderA->id))
            ->assertOk();
    }

    public function test_product_edit_is_tenant_isolated(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $userA = $this->makeUser($tenantA->id);
        $userB = $this->makeUser($tenantB->id);

        $productA = $this->asTenant($tenantA->id, fn () => Product::create([
            'tenant_id' => $tenantA->id, 'name' => 'A product', 'is_active' => 1,
        ]));

        $this->actingAs($userB, 'tenant')->get($this->panelUrl($tenantB, 'products/'.$productA->id.'/edit'))
            ->assertStatus(404);

        $this->actingAs($userA, 'tenant')->get($this->panelUrl($tenantA, 'products/'.$productA->id.'/edit'))
            ->assertOk();
    }

    public function test_product_image_destroy_is_tenant_isolated(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $userA = $this->makeUser($tenantA->id);
        $userB = $this->makeUser($tenantB->id);

        $productA = $this->asTenant($tenantA->id, fn () => Product::create([
            'tenant_id' => $tenantA->id, 'name' => 'A product', 'is_active' => 1,
        ]));
        // Inserted directly (not via ProductImage::create()) — the real
        // product_images table has no timestamp columns, but the model sets
        // $timestamps = true (a pre-existing, unrelated mismatch); this
        // sidesteps it rather than masking it inside a security test.
        $imageId = \Illuminate\Support\Facades\DB::table('product_images')->insertGetId([
            'tenant_id' => $tenantA->id, 'product_id' => $productA->id, 'image_path' => 'a.jpg',
        ]);
        $imageA = ProductImage::withoutGlobalScopes()->find($imageId);

        $this->actingAs($userB, 'tenant')->delete($this->panelUrl($tenantB, 'products/images/'.$imageA->id))
            ->assertStatus(404);
        $this->assertNotNull(ProductImage::withoutGlobalScopes()->find($imageA->id), "tenant A's image must survive tenant B's attempt");

        $this->actingAs($userA, 'tenant')->delete($this->panelUrl($tenantA, 'products/images/'.$imageA->id))
            ->assertRedirect();
        $this->assertNull(ProductImage::withoutGlobalScopes()->find($imageA->id));
    }

    public function test_category_destroy_is_tenant_isolated(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $userA = $this->makeUser($tenantA->id);
        $userB = $this->makeUser($tenantB->id);

        $categoryA = $this->asTenant($tenantA->id, fn () => Category::create([
            'tenant_id' => $tenantA->id, 'name' => 'A category', 'slug' => 'a-category',
        ]));

        $this->actingAs($userB, 'tenant')->delete($this->panelUrl($tenantB, 'categories/'.$categoryA->id))
            ->assertStatus(404);
        $this->assertNotNull(Category::withoutGlobalScopes()->find($categoryA->id));

        $this->actingAs($userA, 'tenant')->delete($this->panelUrl($tenantA, 'categories/'.$categoryA->id))
            ->assertRedirect();
        $this->assertNull(Category::withoutGlobalScopes()->find($categoryA->id));
    }

    public function test_customer_show_is_tenant_isolated(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $userA = $this->makeUser($tenantA->id);
        $userB = $this->makeUser($tenantB->id);

        $customerA = $this->asTenant($tenantA->id, fn () => Customer::create([
            'tenant_id' => $tenantA->id, 'name' => 'A cust', 'phone' => '01711111111',
        ]));

        $this->actingAs($userB, 'tenant')->get($this->panelUrl($tenantB, 'customers/'.$customerA->id))
            ->assertStatus(404);

        $this->actingAs($userA, 'tenant')->get($this->panelUrl($tenantA, 'customers/'.$customerA->id))
            ->assertOk();
    }

    public function test_expense_destroy_is_tenant_isolated(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $userA = $this->makeUser($tenantA->id);
        $userB = $this->makeUser($tenantB->id);

        $expenseA = $this->asTenant($tenantA->id, fn () => Expense::create([
            'tenant_id' => $tenantA->id, 'title' => 'A expense', 'amount' => 500, 'expense_date' => now()->toDateString(),
        ]));

        $this->actingAs($userB, 'tenant')->delete($this->panelUrl($tenantB, 'expenses/'.$expenseA->id))
            ->assertStatus(404);
        $this->assertNotNull(Expense::withoutGlobalScopes()->find($expenseA->id));

        $this->actingAs($userA, 'tenant')->delete($this->panelUrl($tenantA, 'expenses/'.$expenseA->id))
            ->assertRedirect();
        $this->assertNull(Expense::withoutGlobalScopes()->find($expenseA->id));
    }

    public function test_incomplete_order_status_update_is_tenant_isolated(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $userA = $this->makeUser($tenantA->id);
        $userB = $this->makeUser($tenantB->id);

        $incompleteA = $this->asTenant($tenantA->id, fn () => IncompleteOrder::create([
            'tenant_id' => $tenantA->id, 'status' => 'abandoned',
        ]));

        $this->actingAs($userB, 'tenant')
            ->post($this->panelUrl($tenantB, 'incomplete-orders/'.$incompleteA->id.'/status'), ['status' => 'discarded'])
            ->assertStatus(404);
        $this->assertSame('abandoned', IncompleteOrder::withoutGlobalScopes()->find($incompleteA->id)->status);

        $this->actingAs($userA, 'tenant')
            ->post($this->panelUrl($tenantA, 'incomplete-orders/'.$incompleteA->id.'/status'), ['status' => 'discarded'])
            ->assertRedirect();
        $this->assertSame('discarded', IncompleteOrder::withoutGlobalScopes()->find($incompleteA->id)->status);
    }

    public function test_banner_destroy_is_tenant_isolated(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $userA = $this->makeUser($tenantA->id);
        $userB = $this->makeUser($tenantB->id);

        $bannerA = $this->asTenant($tenantA->id, fn () => Banner::create([
            'tenant_id' => $tenantA->id, 'title' => 'A banner', 'image_path' => null, 'is_active' => 1,
        ]));

        $this->actingAs($userB, 'tenant')->delete($this->panelUrl($tenantB, 'website/banner/'.$bannerA->id))
            ->assertStatus(404);
        $this->assertNotNull(Banner::withoutGlobalScopes()->find($bannerA->id));

        $this->actingAs($userA, 'tenant')->delete($this->panelUrl($tenantA, 'website/banner/'.$bannerA->id))
            ->assertRedirect();
        $this->assertNull(Banner::withoutGlobalScopes()->find($bannerA->id));
    }

    public function test_website_page_edit_is_tenant_isolated(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $userA = $this->makeUser($tenantA->id);
        $userB = $this->makeUser($tenantB->id);

        $pageA = $this->asTenant($tenantA->id, fn () => Page::create([
            'tenant_id' => $tenantA->id, 'title' => 'A page', 'is_active' => 1,
        ]));

        $this->actingAs($userB, 'tenant')->get($this->panelUrl($tenantB, 'website/page/'.$pageA->id.'/edit'))
            ->assertStatus(404);

        $this->actingAs($userA, 'tenant')->get($this->panelUrl($tenantA, 'website/page/'.$pageA->id.'/edit'))
            ->assertOk();
    }
}
