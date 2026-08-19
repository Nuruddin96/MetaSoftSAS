<?php

namespace Tests\Feature\AiAgent;

use App\Models\Tenant;
use App\Models\TenantProductImage;
use App\Services\AI\AiProductImageMemoryService;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\InteractsWithAiAgentSchema;
use Tests\TestCase;

/**
 * Covers App\Services\AI\AiProductImageMemoryService ("পণ্যের ছবি") — the
 * deterministic, zero-AI-cost image-request resolver. Matching is a cheap
 * keyword-overlap + conversation-relevance score (see that service's
 * docblock), never an OpenAI call — these tests cover request detection,
 * explicit product-name matching, conversation-relevance ranking when no
 * product is named, ambiguity handling, and tenant isolation.
 */
class AiProductImageMemoryServiceTest extends TestCase
{
    use InteractsWithAiAgentSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpAiAgentSchema();
    }

    protected function makeImage(int $tenantId, string $productName, string $path = 'product-image-memory/1/x.jpg'): TenantProductImage
    {
        app()->instance('currentTenant', Tenant::find($tenantId));

        return TenantProductImage::create(['tenant_id' => $tenantId, 'product_name' => $productName, 'image_path' => $path]);
    }

    // --- imageRequested() detection -------------------------------------------------

    public function test_detects_a_bare_bengali_image_request(): void
    {
        $service = app(AiProductImageMemoryService::class);

        $this->assertTrue($service->imageRequested('ছবি দেন'));
        $this->assertTrue($service->imageRequested('ছবি'));
        $this->assertTrue($service->imageRequested('pic den'));
        $this->assertTrue($service->imageRequested('photo?'));
        $this->assertTrue($service->imageRequested('cosrx snail এর ছবি দেন'));
    }

    public function test_does_not_detect_an_unrelated_mention_of_a_photo(): void
    {
        $service = app(AiProductImageMemoryService::class);

        $this->assertFalse($service->imageRequested('আমি ছবি দেখেছিলাম কিন্তু বুঝি নাই'));
        $this->assertFalse($service->imageRequested('দাম কত?'));
        $this->assertFalse($service->imageRequested(null));
        $this->assertFalse($service->imageRequested(''));
    }

    // --- resolve(): no interception cases --------------------------------------------

    public function test_returns_none_when_the_message_is_not_an_image_request(): void
    {
        $tenant = $this->makeTenant();
        $this->makeImage($tenant->id, 'Rose Serum');

        $result = app(AiProductImageMemoryService::class)->resolve($tenant->id, 'দাম কত?', []);

        $this->assertTrue($result->isNone());
    }

    public function test_returns_none_when_the_tenant_has_no_saved_images(): void
    {
        $tenant = $this->makeTenant();

        $result = app(AiProductImageMemoryService::class)->resolve($tenant->id, 'ছবি দেন', []);

        $this->assertTrue($result->isNone());
    }

    public function test_returns_none_when_nothing_in_history_relates_to_any_saved_image(): void
    {
        $tenant = $this->makeTenant();
        $this->makeImage($tenant->id, 'Mint Cream');

        $result = app(AiProductImageMemoryService::class)->resolve(
            $tenant->id,
            'ছবি দেন',
            ['Rose Serum এর দাম কত?']
        );

        $this->assertTrue($result->isNone());
    }

    // --- resolve(): explicit product name in the current message --------------------

    public function test_a_confident_explicit_name_match_resolves_to_send_and_stop(): void
    {
        $tenant = $this->makeTenant();
        $image = $this->makeImage($tenant->id, 'COSRX Snail Cream');
        $this->makeImage($tenant->id, 'Face Wash');

        $result = app(AiProductImageMemoryService::class)->resolve(
            $tenant->id,
            'cosrx snail cream এর ছবি দেন',
            []
        );

        $this->assertTrue($result->isSendAndStop());
        $this->assertSame($image->id, $result->image->id);
    }

    public function test_an_explicit_match_with_an_additional_question_resolves_to_send_and_continue(): void
    {
        $tenant = $this->makeTenant();
        $image = $this->makeImage($tenant->id, 'COSRX Snail Cream');

        $result = app(AiProductImageMemoryService::class)->resolve(
            $tenant->id,
            'COSRX Snail Cream এর দাম কত আর ছবি দেন',
            []
        );

        $this->assertTrue($result->isSendAndContinue());
        $this->assertSame($image->id, $result->image->id);
    }

    public function test_two_similarly_named_products_in_the_current_message_resolve_to_clarify(): void
    {
        $tenant = $this->makeTenant();
        $this->makeImage($tenant->id, 'Snail Cream');
        $this->makeImage($tenant->id, 'Snail Serum');

        $result = app(AiProductImageMemoryService::class)->resolve(
            $tenant->id,
            'snail এর ছবি দেন',
            []
        );

        $this->assertTrue($result->isClarify());
    }

    // --- resolve(): no product named — conversation-relevance ranking --------------

    public function test_the_most_extensively_and_recently_discussed_product_wins_with_no_name_given(): void
    {
        $tenant = $this->makeTenant();
        $this->makeImage($tenant->id, 'Rose Serum');
        $this->makeImage($tenant->id, 'Mint Cream');
        $winner = $this->makeImage($tenant->id, 'Coconut Oil Set');

        $history = [
            'Rose Serum এর দাম কত?',
            'Mint Cream নিয়ে জিজ্ঞেস করছি',
            'Mint Cream এর স্টক আছে?',
            'Coconut Oil Set এর দাম কত?',
            'Coconut Oil Set কিভাবে ব্যবহার করব?',
            'Coconut Oil Set স্টক আছে?',
        ];

        $result = app(AiProductImageMemoryService::class)->resolve($tenant->id, 'ছবি দেন', $history);

        $this->assertTrue($result->isSendAndStop());
        $this->assertSame($winner->id, $result->image->id);
    }

    public function test_two_comparably_relevant_products_resolve_to_clarify_rather_than_guessing(): void
    {
        $tenant = $this->makeTenant();
        $this->makeImage($tenant->id, 'Lotion Max');
        $this->makeImage($tenant->id, 'Lotion Mini');

        $history = [
            'Lotion Max এর দাম কত?',
            'Lotion Mini এর দাম কত?',
        ];

        $result = app(AiProductImageMemoryService::class)->resolve($tenant->id, 'ছবি দেন', $history);

        $this->assertTrue($result->isClarify());
    }

    public function test_a_relevance_match_with_an_additional_question_in_the_current_message_resolves_to_send_and_continue(): void
    {
        $tenant = $this->makeTenant();
        $winner = $this->makeImage($tenant->id, 'Coconut Oil Set');

        $result = app(AiProductImageMemoryService::class)->resolve(
            $tenant->id,
            'ছবি দেন, আর ডেলিভারি চার্জ কত?',
            ['Coconut Oil Set এর দাম কত?']
        );

        $this->assertTrue($result->isSendAndContinue());
        $this->assertSame($winner->id, $result->image->id);
    }

    // --- Tenant isolation -------------------------------------------------------------

    public function test_never_matches_another_tenants_saved_image(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $this->makeImage($tenantB->id, 'Rose Serum');

        $result = app(AiProductImageMemoryService::class)->resolve(
            $tenantA->id,
            'Rose Serum এর ছবি দেন',
            []
        );

        $this->assertTrue($result->isNone());
    }

    // --- Failure handling ---------------------------------------------------------------

    public function test_a_lookup_failure_degrades_to_none_instead_of_throwing(): void
    {
        $tenant = $this->makeTenant();
        $this->makeImage($tenant->id, 'Rose Serum');

        Schema::dropIfExists('tenant_product_images');

        $result = app(AiProductImageMemoryService::class)->resolve($tenant->id, 'ছবি দেন', []);

        $this->assertTrue($result->isNone());
    }
}
