<?php

namespace Tests\Feature\Api\Mobile;

use App\Models\LandingPage;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Tenant;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithApiSchema;
use Tests\TestCase;

/**
 * Mobile counterpart of the web panel's LandingPageController (Phase 2,
 * already live) — same LandingPage model/SectionDataService underneath, so
 * this only re-verifies the mobile-specific surface: auth/tenant isolation,
 * the JSON shapes, and section CRUD/reorder, not variant resolution or
 * order placement (both already covered by the web LandingPageTest suite
 * and untouched here).
 */
class LandingPageApiTest extends TestCase
{
    use InteractsWithApiSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpApiSchema();
    }

    protected function makeTenantWithProduct(): array
    {
        $tenant = $this->makeTenant();
        // Bind currentTenant BEFORE makeUser(): User also uses
        // BelongsToTenant, and makeUser() reads its own row back via
        // User::find() right after inserting it — if currentTenant is still
        // bound to a PREVIOUS tenant from an earlier call in the same test,
        // that global scope filters the just-inserted row out and find()
        // returns null.
        app()->instance('currentTenant', $tenant);
        $user = $this->makeUser($tenant->id);
        $product = Product::create(['tenant_id' => $tenant->id, 'name' => 'Brilliant Skin Set', 'is_active' => 1]);

        return [$tenant, $user, $product];
    }

    public function test_a_tenant_can_create_a_landing_page_bound_to_their_product(): void
    {
        [$tenant, $user, $product] = $this->makeTenantWithProduct();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/mobile/v1/landing-pages', [
            'title' => 'Brilliant Skin Offer',
            'product_id' => $product->id,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('title', 'Brilliant Skin Offer');
        $response->assertJsonPath('status', 'draft');
        $response->assertJsonPath('product.id', $product->id);
        $this->assertNotEmpty($response->json('sections'));
        $this->assertContains('checkout', array_column($response->json('sections'), 'type'));
    }

    public function test_a_tenant_cannot_bind_a_landing_page_to_another_tenants_product(): void
    {
        [$tenant, $user] = $this->makeTenantWithProduct();
        [, , $otherProduct] = $this->makeTenantWithProduct();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/mobile/v1/landing-pages', [
            'title' => 'X', 'product_id' => $otherProduct->id,
        ]);

        $response->assertNotFound();
    }

    public function test_index_and_show_never_leak_another_tenants_landing_page(): void
    {
        [$tenantA, $userA, $productA] = $this->makeTenantWithProduct();
        [$tenantB, $userB] = $this->makeTenantWithProduct();

        app()->instance('currentTenant', $tenantA);
        $lpA = LandingPage::create([
            'title' => 'A page', 'product_id' => $productA->id, 'status' => 'draft',
            'sections' => LandingPage::defaultSections($productA),
        ]);

        Sanctum::actingAs($userB);
        $this->getJson('/api/mobile/v1/landing-pages')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/mobile/v1/landing-pages/{$lpA->id}")->assertNotFound();
        $this->patchJson("/api/mobile/v1/landing-pages/{$lpA->id}", ['title' => 'Hacked'])->assertNotFound();
        $this->postJson("/api/mobile/v1/landing-pages/{$lpA->id}/publish")->assertNotFound();
        $this->deleteJson("/api/mobile/v1/landing-pages/{$lpA->id}")->assertNotFound();

        $lpA->refresh();
        $this->assertSame('A page', $lpA->title);
        $this->assertSame('draft', $lpA->status);
    }

    public function test_publish_and_unpublish_toggle_status(): void
    {
        [$tenant, $user, $product] = $this->makeTenantWithProduct();
        $lp = LandingPage::create([
            'title' => 'Offer', 'product_id' => $product->id, 'status' => 'draft',
            'sections' => LandingPage::defaultSections($product),
        ]);
        Sanctum::actingAs($user);

        $this->postJson("/api/mobile/v1/landing-pages/{$lp->id}/publish")
            ->assertOk()->assertJsonPath('status', 'published')->assertJsonPath('is_published', true);

        $this->postJson("/api/mobile/v1/landing-pages/{$lp->id}/unpublish")
            ->assertOk()->assertJsonPath('status', 'draft')->assertJsonPath('is_published', false);
    }

    public function test_section_add_update_and_delete(): void
    {
        [$tenant, $user, $product] = $this->makeTenantWithProduct();
        $lp = LandingPage::create([
            'title' => 'Offer', 'product_id' => $product->id, 'status' => 'draft', 'sections' => [],
        ]);
        Sanctum::actingAs($user);

        $add = $this->postJson("/api/mobile/v1/landing-pages/{$lp->id}/sections", ['type' => 'faq'])->assertCreated();
        $sectionId = $add->json('id');

        $this->postJson("/api/mobile/v1/landing-pages/{$lp->id}/sections/{$sectionId}", [
            'data' => ['heading' => 'প্রশ্নোত্তর', 'items' => [['question' => 'ডেলিভারি কতদিনে?', 'answer' => '৩-৫ দিন']]],
        ])->assertOk()->assertJsonPath('data.heading', 'প্রশ্নোত্তর')
            ->assertJsonPath('data.items.0.question', 'ডেলিভারি কতদিনে?');

        $lp->refresh();
        $this->assertCount(1, $lp->sections);

        $this->deleteJson("/api/mobile/v1/landing-pages/{$lp->id}/sections/{$sectionId}")->assertOk();
        $lp->refresh();
        $this->assertCount(0, $lp->sections);
    }

    public function test_reorder_sections_by_full_id_order(): void
    {
        [$tenant, $user, $product] = $this->makeTenantWithProduct();
        $lp = LandingPage::create([
            'title' => 'Offer', 'product_id' => $product->id, 'status' => 'draft',
            'sections' => [
                ['id' => 'aaa', 'type' => 'hero', 'data' => []],
                ['id' => 'bbb', 'type' => 'faq', 'data' => []],
            ],
        ]);
        Sanctum::actingAs($user);

        $this->postJson("/api/mobile/v1/landing-pages/{$lp->id}/sections/reorder", ['order' => ['bbb', 'aaa']])
            ->assertOk();

        $lp->refresh();
        $this->assertSame(['bbb', 'aaa'], array_column($lp->sections, 'id'));
    }

    public function test_reorder_rejects_a_set_that_does_not_match_existing_sections(): void
    {
        [$tenant, $user, $product] = $this->makeTenantWithProduct();
        $lp = LandingPage::create([
            'title' => 'Offer', 'product_id' => $product->id, 'status' => 'draft',
            'sections' => [['id' => 'aaa', 'type' => 'hero', 'data' => []]],
        ]);
        Sanctum::actingAs($user);

        $this->postJson("/api/mobile/v1/landing-pages/{$lp->id}/sections/reorder", ['order' => ['zzz']])
            ->assertStatus(422);
    }

    public function test_duplicate_section_inserts_a_copy_right_after_the_original(): void
    {
        [$tenant, $user, $product] = $this->makeTenantWithProduct();
        $lp = LandingPage::create([
            'title' => 'Offer', 'product_id' => $product->id, 'status' => 'draft',
            'sections' => [['id' => 'aaa', 'type' => 'faq', 'data' => ['heading' => 'Q']]],
        ]);
        Sanctum::actingAs($user);

        $this->postJson("/api/mobile/v1/landing-pages/{$lp->id}/sections/aaa/duplicate")->assertCreated();

        $lp->refresh();
        $this->assertCount(2, $lp->sections);
        $this->assertSame('faq', $lp->sections[1]['type']);
        $this->assertNotSame('aaa', $lp->sections[1]['id']);
    }

    public function test_show_returns_the_bound_products_variants_for_checkout_display(): void
    {
        [$tenant, $user, $product] = $this->makeTenantWithProduct();
        app()->instance('currentTenant', $tenant);
        ProductVariant::create([
            'tenant_id' => $tenant->id, 'product_id' => $product->id,
            'variant_name' => 'Default', 'selling_price' => 1200,
        ]);
        $lp = LandingPage::create([
            'title' => 'Offer', 'product_id' => $product->id, 'status' => 'draft',
            'sections' => LandingPage::defaultSections($product),
        ]);
        Sanctum::actingAs($user);

        $response = $this->getJson("/api/mobile/v1/landing-pages/{$lp->id}")->assertOk();
        $this->assertEquals(1200, $response->json('product.variants.0.selling_price'));
    }
}
