<?php

namespace Tests\Feature\Tenant;

use App\Models\Review;
use App\Models\Tenant;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Tests\Concerns\InteractsWithCommerceSchema;
use Tests\TestCase;

/**
 * Customer Reviews (Tenant\WebsiteController::storeReview()/updateReview()/
 * destroyReview(), App\Models\Review — mirrors Banner exactly). Isolation
 * comes from implicit route binding through App\Traits\BelongsToTenant::
 * resolveRouteBinding(), same mechanism every other tenant-owned model
 * uses.
 */
class ReviewControllerTest extends TestCase
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

    public function test_a_tenant_can_add_a_review(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);

        $response = $this->actingAs($user, 'tenant')->post($this->panelUrl($tenant, 'website/review'), [
            'customer_name' => 'রহিম উদ্দিন',
            'review_text' => 'দারুণ সার্ভিস!',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('reviews', [
            'tenant_id' => $tenant->id,
            'customer_name' => 'রহিম উদ্দিন',
            'review_text' => 'দারুণ সার্ভিস!',
        ]);
    }

    public function test_customer_name_is_required(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);

        $response = $this->actingAs($user, 'tenant')->post($this->panelUrl($tenant, 'website/review'), []);

        $response->assertSessionHasErrors('customer_name');
        $this->assertSame(0, Review::withoutGlobalScopes()->count());
    }

    public function test_a_tenant_can_delete_their_own_review(): void
    {
        $tenant = $this->makeTenant();
        $review = Review::create(['tenant_id' => $tenant->id, 'customer_name' => 'Test', 'sort_order' => 1]);
        $user = $this->makeUser($tenant->id);

        $response = $this->actingAs($user, 'tenant')->delete($this->panelUrl($tenant, "website/review/{$review->id}"));

        $response->assertRedirect();
        $this->assertDatabaseMissing('reviews', ['id' => $review->id]);
    }

    public function test_a_tenant_cannot_delete_another_tenants_review(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $userB = $this->makeUser($tenantB->id);
        $reviewA = Review::create(['tenant_id' => $tenantA->id, 'customer_name' => 'A Customer', 'sort_order' => 1]);

        $response = $this->actingAs($userB, 'tenant')->delete($this->panelUrl($tenantB, "website/review/{$reviewA->id}"));

        $response->assertNotFound();
        $this->assertDatabaseHas('reviews', ['id' => $reviewA->id]);
    }

    public function test_storefront_shows_only_this_tenants_active_reviews_up_to_four(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        foreach (range(1, 5) as $i) {
            Review::create(['tenant_id' => $tenantA->id, 'customer_name' => "Customer $i", 'sort_order' => $i, 'is_active' => 1]);
        }
        Review::create(['tenant_id' => $tenantB->id, 'customer_name' => 'Tenant B Secret Customer', 'sort_order' => 1, 'is_active' => 1]);

        $response = $this->get('/shop/'.$tenantA->subdomain);

        $response->assertOk();
        $response->assertSee('কাস্টমার রিভিউ');
        $response->assertDontSee('Tenant B Secret Customer');
        $this->assertSame(4, substr_count($response->getContent(), 'Customer '));
    }
}
