<?php

namespace Tests\Feature\AiAgent;

use App\Services\AI\AiTenantKnowledgeService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\InteractsWithAiAgentSchema;
use Tests\TestCase;

/**
 * Covers App\Services\AI\AiTenantKnowledgeService — the "RELEVANT BUSINESS
 * KNOWLEDGE" layer (Phase 4). Deliberately only ever surfaces data that is
 * ALREADY authoritative elsewhere in the app (delivery charges, seeded by
 * Tenant::booted() and used by real order/checkout flow) — never a
 * tenant's own unverified free text (that's AiConversationStyleService's
 * sibling, config('ai.system_prompt')'s ai_custom_instructions handling).
 */
class AiTenantKnowledgeServiceTest extends TestCase
{
    use InteractsWithAiAgentSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpAiAgentSchema();
    }

    protected function seedDeliveryCharge(int $tenantId, string $key, string $value): void
    {
        DB::table('store_settings')->insert([
            'tenant_id' => $tenantId, 'key' => $key, 'value' => $value,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_returns_empty_string_when_no_settings_exist(): void
    {
        $tenant = $this->makeTenant();

        $knowledge = app(AiTenantKnowledgeService::class)->businessKnowledge($tenant->id);

        $this->assertSame('', $knowledge);
    }

    public function test_includes_the_real_delivery_charges(): void
    {
        $tenant = $this->makeTenant();
        $this->seedDeliveryCharge($tenant->id, 'delivery_charge_inside_dhaka', '80');
        $this->seedDeliveryCharge($tenant->id, 'delivery_charge_outside_dhaka', '150');
        $this->seedDeliveryCharge($tenant->id, 'currency', 'BDT');

        $knowledge = app(AiTenantKnowledgeService::class)->businessKnowledge($tenant->id);

        $this->assertStringContainsString('Delivery charge inside Dhaka: 80 BDT.', $knowledge);
        $this->assertStringContainsString('Delivery charge outside Dhaka: 150 BDT.', $knowledge);
    }

    public function test_defaults_to_bdt_when_no_currency_setting_exists(): void
    {
        $tenant = $this->makeTenant();
        $this->seedDeliveryCharge($tenant->id, 'delivery_charge_inside_dhaka', '60');

        $knowledge = app(AiTenantKnowledgeService::class)->businessKnowledge($tenant->id);

        $this->assertStringContainsString('60 BDT', $knowledge);
    }

    public function test_includes_only_the_charges_that_are_actually_set(): void
    {
        $tenant = $this->makeTenant();
        $this->seedDeliveryCharge($tenant->id, 'delivery_charge_inside_dhaka', '60');
        // outside-Dhaka deliberately not set.

        $knowledge = app(AiTenantKnowledgeService::class)->businessKnowledge($tenant->id);

        $this->assertStringContainsString('inside Dhaka', $knowledge);
        $this->assertStringNotContainsString('outside Dhaka', $knowledge);
    }

    public function test_never_leaks_tenant_as_delivery_charge_to_tenant_b(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $this->seedDeliveryCharge($tenantA->id, 'delivery_charge_inside_dhaka', '999');

        $knowledgeForB = app(AiTenantKnowledgeService::class)->businessKnowledge($tenantB->id);

        $this->assertSame('', $knowledgeForB);
        $this->assertStringNotContainsString('999', $knowledgeForB);
    }

    public function test_a_lookup_failure_degrades_to_empty_string_instead_of_throwing(): void
    {
        $tenant = $this->makeTenant();
        $this->seedDeliveryCharge($tenant->id, 'delivery_charge_inside_dhaka', '60');

        // A genuine underlying failure (table missing) rather than a mock
        // — this service queries StoreSetting via Eloquent, not DB::table()
        // directly, so this is the reliable way to force the real query to
        // throw and prove the try/catch actually degrades gracefully.
        Schema::dropIfExists('store_settings');

        $knowledge = app(AiTenantKnowledgeService::class)->businessKnowledge($tenant->id);

        $this->assertSame('', $knowledge);
    }
}
