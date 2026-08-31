<?php

namespace Tests\Feature\Tenant;

use App\Models\LandingPage;
use App\Models\Product;
use App\Models\Tenant;
use App\Services\LandingPage\DesignResolver;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithCommerceSchema;
use Tests\TestCase;

/**
 * Landing Page Design System upgrade — global + section-level design
 * settings (Phase 1/2), templates (Phase 10), reorder/hide-show (Phase 8),
 * and the new section types (Phase 4). Backward compatibility (Phase 14) is
 * the load-bearing property here: every assertion about an OLD landing page
 * (created before this feature, `design` column null) must keep rendering
 * exactly as before.
 */
class LandingPageDesignTest extends TestCase
{
    use InteractsWithCommerceSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCommerceSchema();
        $this->withoutMiddleware(ValidateCsrfToken::class);

        DB::table('bd_divisions')->insert([
            ['id' => 1, 'name' => 'Barishal', 'bn_name' => 'বরিশাল'],
            ['id' => 3, 'name' => 'Dhaka', 'bn_name' => 'ঢাকা'],
        ]);
        DB::table('bd_districts')->insert(['id' => 1, 'division_id' => 3, 'name' => 'Dhaka', 'bn_name' => 'ঢাকা']);
    }

    protected function panelUrl(Tenant $tenant, string $path = ''): string
    {
        return '/shop/'.$tenant->subdomain.'/panel'.($path ? '/'.$path : '');
    }

    protected function storeUrl(Tenant $tenant, string $path = ''): string
    {
        return '/shop/'.$tenant->subdomain.($path ? '/'.$path : '');
    }

    // --- Backward compatibility ---------------------------------------------

    public function test_a_landing_page_with_no_design_column_value_still_renders(): void
    {
        $tenant = $this->makeTenant();
        app()->instance('currentTenant', $tenant);
        $product = Product::create(['tenant_id' => $tenant->id, 'name' => 'Old Page Product', 'is_active' => 1]);

        $lp = LandingPage::create([
            'tenant_id' => $tenant->id,
            'product_id' => $product->id,
            'title' => 'Pre-upgrade Page',
            'status' => 'published',
            'published_at' => now(),
            'sections' => LandingPage::defaultSections($product),
            // 'design' intentionally omitted — simulates a row created before this column existed.
        ]);

        $this->assertNull($lp->fresh()->design);

        $this->get($this->storeUrl($tenant, 'l/'.$lp->slug))
            ->assertOk()
            ->assertSee($product->name);
    }

    public function test_design_resolver_falls_back_to_tenant_brand_colors_when_no_override_is_set(): void
    {
        $tenant = $this->makeTenant(['primary_color' => '#123456', 'secondary_color' => '#654321']);
        $resolver = app(DesignResolver::class);

        $resolved = $resolver->resolveGlobal(null, $tenant);

        $this->assertSame('#123456', $resolved['brand']['primary_color']);
        $this->assertSame('#654321', $resolved['brand']['secondary_color']);
    }

    public function test_an_explicit_design_override_wins_over_the_tenant_brand_color(): void
    {
        $tenant = $this->makeTenant(['primary_color' => '#123456']);
        $resolver = app(DesignResolver::class);

        $resolved = $resolver->resolveGlobal(['brand' => ['primary_color' => '#abcdef']], $tenant);

        $this->assertSame('#abcdef', $resolved['brand']['primary_color']);
    }

    // --- Global design (tenant panel) ---------------------------------------

    public function test_tenant_can_save_global_design_settings(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        app()->instance('currentTenant', $tenant);
        $product = Product::create(['tenant_id' => $tenant->id, 'name' => 'Serum', 'is_active' => 1]);
        $lp = LandingPage::create([
            'tenant_id' => $tenant->id, 'product_id' => $product->id, 'title' => 'Offer', 'status' => 'draft',
            'sections' => LandingPage::defaultSections($product),
        ]);

        $this->actingAs($user, 'tenant')->put($this->panelUrl($tenant, "landing-pages/{$lp->id}/design"), [
            'design' => [
                'brand' => ['primary_color_enabled' => '1', 'primary_color' => '#ff0000'],
                'typography' => ['heading_font' => 'modern', 'font_size' => 'lg', 'font_weight' => 'bold', 'line_height' => 'relaxed'],
                'buttons' => ['style' => 'outline', 'radius' => 'full', 'size' => 'lg'],
                'global' => ['container_width' => 'wide', 'section_spacing' => 'spacious', 'border_radius' => 'lg', 'shadow' => 'md'],
            ],
        ])->assertRedirect();

        $lp->refresh();
        $this->assertSame('#ff0000', $lp->design['brand']['primary_color']);
        $this->assertSame('modern', $lp->design['typography']['heading_font']);
        $this->assertSame('outline', $lp->design['buttons']['style']);
        $this->assertSame('wide', $lp->design['global']['container_width']);
    }

    public function test_unchecking_a_brand_color_clears_the_override_back_to_inherited(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        app()->instance('currentTenant', $tenant);
        $product = Product::create(['tenant_id' => $tenant->id, 'name' => 'Serum', 'is_active' => 1]);
        $lp = LandingPage::create([
            'tenant_id' => $tenant->id, 'product_id' => $product->id, 'title' => 'Offer', 'status' => 'draft',
            'sections' => LandingPage::defaultSections($product),
            'design' => ['brand' => ['primary_color' => '#ff0000']],
        ]);

        // primary_color_enabled omitted entirely — same as an unchecked checkbox.
        $this->actingAs($user, 'tenant')->put($this->panelUrl($tenant, "landing-pages/{$lp->id}/design"), [
            'design' => ['brand' => ['primary_color' => '#ff0000']],
        ])->assertRedirect();

        $this->assertNull($lp->fresh()->design['brand']['primary_color']);
    }

    public function test_global_design_page_requires_tenant_login(): void
    {
        $tenant = $this->makeTenant();
        app()->instance('currentTenant', $tenant);
        $product = Product::create(['tenant_id' => $tenant->id, 'name' => 'Serum', 'is_active' => 1]);
        $lp = LandingPage::create([
            'tenant_id' => $tenant->id, 'product_id' => $product->id, 'title' => 'Offer', 'status' => 'draft',
            'sections' => LandingPage::defaultSections($product),
        ]);

        $this->get($this->panelUrl($tenant, "landing-pages/{$lp->id}/design"))->assertRedirect();
    }

    // --- Section-level design override --------------------------------------

    public function test_a_section_design_override_changes_the_rendered_background_style(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        app()->instance('currentTenant', $tenant);
        $product = Product::create(['tenant_id' => $tenant->id, 'name' => 'Serum', 'is_active' => 1]);
        $lp = LandingPage::create([
            'tenant_id' => $tenant->id, 'product_id' => $product->id, 'title' => 'Offer', 'status' => 'published',
            'published_at' => now(),
            'sections' => [['id' => 'sec1', 'type' => 'cta', 'hidden' => false, 'data' => ['heading' => 'Buy now', 'button_text' => 'Order']]],
        ]);

        $this->actingAs($user, 'tenant')->put($this->panelUrl($tenant, "landing-pages/{$lp->id}/sections/sec1"), [
            'data' => [
                'heading' => 'Buy now',
                'design' => ['background' => ['type' => 'color'], 'colors' => ['bg' => '#00ff00']],
            ],
        ])->assertRedirect();

        $lp->refresh();
        $this->assertSame('#00ff00', $lp->sections[0]['data']['design']['colors']['bg']);

        $this->get($this->storeUrl($tenant, 'l/'.$lp->slug))
            ->assertOk()
            ->assertSee('background-color: #00ff00', false);
    }

    // --- Reorder / hide-show -------------------------------------------------

    public function test_toggle_section_hides_it_from_the_public_page(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        app()->instance('currentTenant', $tenant);
        $product = Product::create(['tenant_id' => $tenant->id, 'name' => 'Serum', 'is_active' => 1]);
        $lp = LandingPage::create([
            'tenant_id' => $tenant->id, 'product_id' => $product->id, 'title' => 'Offer', 'status' => 'published',
            'published_at' => now(),
            'sections' => [['id' => 'sec1', 'type' => 'rich_text', 'hidden' => false, 'data' => ['heading' => 'Our Story', 'body' => 'Once upon a time']]],
        ]);

        $this->get($this->storeUrl($tenant, 'l/'.$lp->slug))->assertOk()->assertSee('Our Story');

        $this->actingAs($user, 'tenant')->post($this->panelUrl($tenant, "landing-pages/{$lp->id}/sections/sec1/toggle"))->assertRedirect();
        $this->assertTrue($lp->fresh()->sections[0]['hidden']);

        $this->get($this->storeUrl($tenant, 'l/'.$lp->slug))->assertOk()->assertDontSee('Our Story');
    }

    // --- Templates -----------------------------------------------------------

    public function test_creating_a_landing_page_with_a_template_applies_its_section_list_and_design_preset(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        app()->instance('currentTenant', $tenant);
        $product = Product::create(['tenant_id' => $tenant->id, 'name' => 'Lipstick', 'is_active' => 1]);

        $this->actingAs($user, 'tenant')->post($this->panelUrl($tenant, 'landing-pages'), [
            'title' => 'Skincare Launch',
            'product_id' => $product->id,
            'template' => 'skincare',
        ])->assertRedirect();

        $lp = LandingPage::where('title', 'Skincare Launch')->firstOrFail();
        $this->assertContains('trust_badges', array_column($lp->sections, 'type'));
        $this->assertSame('full', $lp->design['buttons']['radius']);
    }

    public function test_default_template_reproduces_the_original_nine_section_layout(): void
    {
        $tenant = $this->makeTenant();
        app()->instance('currentTenant', $tenant);
        $product = Product::create(['tenant_id' => $tenant->id, 'name' => 'Cream', 'is_active' => 1]);

        $sections = LandingPage::defaultSections($product);

        $this->assertSame(
            ['hero', 'media', 'benefits', 'features', 'image_text', 'reviews', 'faq', 'cta', 'checkout'],
            array_column($sections, 'type')
        );
    }

    // --- New section types ----------------------------------------------------

    public function test_new_section_types_render_on_the_public_page(): void
    {
        $tenant = $this->makeTenant();
        app()->instance('currentTenant', $tenant);
        $product = Product::create(['tenant_id' => $tenant->id, 'name' => 'Widget', 'is_active' => 1]);

        $lp = LandingPage::create([
            'tenant_id' => $tenant->id, 'product_id' => $product->id, 'title' => 'Offer', 'status' => 'published',
            'published_at' => now(),
            'sections' => [
                ['id' => 's1', 'type' => 'announcement_bar', 'hidden' => false, 'data' => ['text' => 'Free delivery today', 'dismissible' => true]],
                ['id' => 's2', 'type' => 'trust_badges', 'hidden' => false, 'data' => ['items' => [['icon' => '✅', 'label' => 'Cash on delivery']]]],
                ['id' => 's3', 'type' => 'delivery_info', 'hidden' => false, 'data' => ['heading' => 'Delivery Info', 'eta_text' => '24-48 hours']],
                ['id' => 's4', 'type' => 'rich_text', 'hidden' => false, 'data' => ['heading' => 'Our Story', 'body' => 'Founded in 2020']],
            ],
        ]);

        $response = $this->get($this->storeUrl($tenant, 'l/'.$lp->slug));

        $response->assertOk()
            ->assertSee('Free delivery today')
            ->assertSee('Cash on delivery')
            ->assertSee('24-48 hours')
            ->assertSee('Our Story')
            ->assertSee('Founded in 2020');
    }

    public function test_countdown_section_renders_with_an_end_at_and_carries_it_to_the_client_timer(): void
    {
        $tenant = $this->makeTenant();
        app()->instance('currentTenant', $tenant);
        $product = Product::create(['tenant_id' => $tenant->id, 'name' => 'Widget', 'is_active' => 1]);
        $endAt = now()->addDays(3)->format('Y-m-d\TH:i');

        $lp = LandingPage::create([
            'tenant_id' => $tenant->id, 'product_id' => $product->id, 'title' => 'Offer', 'status' => 'published',
            'published_at' => now(),
            'sections' => [
                ['id' => 's1', 'type' => 'countdown', 'hidden' => false, 'data' => [
                    'heading' => 'অফারটি শেষ হচ্ছে', 'end_at' => $endAt, 'expired_text' => 'অফারটি শেষ হয়ে গেছে',
                ]],
            ],
        ]);

        $response = $this->get($this->storeUrl($tenant, 'l/'.$lp->slug));

        $response->assertOk()
            ->assertSee('অফারটি শেষ হচ্ছে')
            ->assertSee('data-end="'.$endAt.'"', false);
    }

    /** The section's own @if($data['end_at']) guard means a countdown with no end_at set is silently omitted, not rendered broken. */
    public function test_countdown_section_without_an_end_at_does_not_render(): void
    {
        $tenant = $this->makeTenant();
        app()->instance('currentTenant', $tenant);
        $product = Product::create(['tenant_id' => $tenant->id, 'name' => 'Widget', 'is_active' => 1]);

        $lp = LandingPage::create([
            'tenant_id' => $tenant->id, 'product_id' => $product->id, 'title' => 'Offer', 'status' => 'published',
            'published_at' => now(),
            'sections' => [
                ['id' => 's1', 'type' => 'countdown', 'hidden' => false, 'data' => ['heading' => 'অফারটি শেষ হচ্ছে', 'end_at' => '']],
            ],
        ]);

        $response = $this->get($this->storeUrl($tenant, 'l/'.$lp->slug));

        $response->assertOk()->assertDontSee('অফারটি শেষ হচ্ছে');
    }
}
