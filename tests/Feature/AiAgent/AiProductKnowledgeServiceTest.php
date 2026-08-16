<?php

namespace Tests\Feature\AiAgent;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Tenant;
use App\Services\AI\AiProductKnowledgeService;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\InteractsWithAiAgentSchema;
use Tests\TestCase;

/**
 * Covers App\Services\AI\AiProductKnowledgeService — the "RELEVANT PRODUCT
 * DATA" layer (Phase 5) that directly fixes the production example this
 * whole phase was built for: "COSRX Snail Cream টার দাম কত?" previously
 * got "কোন প্রোডাক্টের কথা বলছেন?" because the AI had no product data
 * access at all.
 *
 * The single most important property under test is that purchase_price
 * (wholesale cost) NEVER appears in the output, even though the
 * underlying lookup_products tool result carries it — see that service's
 * class docblock.
 */
class AiProductKnowledgeServiceTest extends TestCase
{
    use InteractsWithAiAgentSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpAiAgentSchema();
    }

    protected function makeProduct(int $tenantId, string $name, float $sellingPrice, float $purchasePrice = 200, int $stock = 5, bool $active = true): Product
    {
        app()->instance('currentTenant', Tenant::find($tenantId));

        $product = Product::create([
            'tenant_id' => $tenantId, 'name' => $name, 'is_active' => $active,
        ]);

        ProductVariant::create([
            'tenant_id' => $tenantId, 'product_id' => $product->id, 'variant_name' => 'Default',
            'selling_price' => $sellingPrice, 'purchase_price' => $purchasePrice,
        ]);

        return $product;
    }

    public function test_returns_empty_string_when_the_conversation_mentions_no_product(): void
    {
        $tenant = $this->makeTenant();
        $this->makeProduct($tenant->id, 'COSRX Snail Cream', 850);

        $knowledge = app(AiProductKnowledgeService::class)->relevantProducts($tenant->id, ['দাম কত?']);

        $this->assertSame('', $knowledge);
    }

    public function test_returns_empty_string_when_there_is_no_conversation_text_at_all(): void
    {
        $tenant = $this->makeTenant();
        $this->makeProduct($tenant->id, 'COSRX Snail Cream', 850);

        $knowledge = app(AiProductKnowledgeService::class)->relevantProducts($tenant->id, []);

        $this->assertSame('', $knowledge);
    }

    public function test_finds_the_real_price_and_stock_for_a_product_named_in_the_conversation(): void
    {
        // The exact regression scenario this phase exists for.
        $tenant = $this->makeTenant();
        $this->makeProduct($tenant->id, 'COSRX Snail Cream', 850, purchasePrice: 400);

        $knowledge = app(AiProductKnowledgeService::class)->relevantProducts(
            $tenant->id,
            ['COSRX Snail Cream টা দেখান', 'এইটার দাম কত?']
        );

        $this->assertStringContainsString('COSRX Snail Cream', $knowledge);
        $this->assertStringContainsString('850', $knowledge);
    }

    public function test_never_includes_the_wholesale_purchase_price(): void
    {
        // The critical security property — see class docblock.
        $tenant = $this->makeTenant();
        $this->makeProduct($tenant->id, 'COSRX Snail Cream', 850, purchasePrice: 400);

        $knowledge = app(AiProductKnowledgeService::class)->relevantProducts(
            $tenant->id,
            ['COSRX Snail Cream টা দেখান']
        );

        $this->assertStringNotContainsString('400', $knowledge, 'purchase_price (wholesale cost) must never reach a customer-facing prompt');
    }

    public function test_reports_out_of_stock_when_stock_is_zero(): void
    {
        $tenant = $this->makeTenant();
        $product = $this->makeProduct($tenant->id, 'COSRX Snail Cream', 850);
        // makeProduct() creates no inventory rows at all, so totalStock() is 0.

        $knowledge = app(AiProductKnowledgeService::class)->relevantProducts($tenant->id, ['COSRX Snail Cream']);

        $this->assertStringContainsString('out of stock', $knowledge);
    }

    public function test_inactive_products_are_not_matched(): void
    {
        $tenant = $this->makeTenant();
        $this->makeProduct($tenant->id, 'COSRX Snail Cream', 850, active: false);

        $knowledge = app(AiProductKnowledgeService::class)->relevantProducts($tenant->id, ['COSRX Snail Cream']);

        $this->assertSame('', $knowledge);
    }

    public function test_respects_the_configured_max_matched_products(): void
    {
        config(['ai.product_match_max' => 1]);
        $tenant = $this->makeTenant();
        $this->makeProduct($tenant->id, 'Product Alpha', 100);
        $this->makeProduct($tenant->id, 'Product Beta', 200);

        $knowledge = app(AiProductKnowledgeService::class)->relevantProducts(
            $tenant->id,
            ['Product Alpha and Product Beta both please']
        );

        $matchCount = substr_count($knowledge, 'Product ');
        $this->assertSame(1, $matchCount);
    }

    public function test_never_leaks_tenant_as_product_to_tenant_b(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $this->makeProduct($tenantA->id, 'Tenant A Secret Product', 999);

        $knowledgeForB = app(AiProductKnowledgeService::class)->relevantProducts(
            $tenantB->id,
            ['Tenant A Secret Product']
        );

        $this->assertSame('', $knowledgeForB);
    }

    public function test_a_lookup_failure_degrades_to_empty_string_instead_of_throwing(): void
    {
        $tenant = $this->makeTenant();
        $this->makeProduct($tenant->id, 'COSRX Snail Cream', 850);

        Schema::dropIfExists('products');

        $knowledge = app(AiProductKnowledgeService::class)->relevantProducts($tenant->id, ['COSRX Snail Cream']);

        $this->assertSame('', $knowledge);
    }
}
