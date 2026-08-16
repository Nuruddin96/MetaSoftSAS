<?php

namespace Tests\Feature\AiAgent;

use App\Models\Order;
use App\Services\AI\AiCustomerMemoryService;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\InteractsWithAiAgentSchema;
use Tests\TestCase;

/**
 * Covers App\Services\AI\AiCustomerMemoryService — the Phase 6 "customer
 * memory" layer. The single most important property under test, mirrored
 * across both channels, is that every lookup keys off an identifier the
 * channel itself verified (messenger_psid / a normalized wa_id matched
 * against customer_phone) — never a phone number or name the current
 * conversation text supplied — see the service's own class docblock for
 * why that distinction is a security boundary, not just a style choice.
 */
class AiCustomerMemoryServiceTest extends TestCase
{
    use InteractsWithAiAgentSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpAiAgentSchema();
    }

    protected function makeOrder(int $tenantId, array $attrs = []): Order
    {
        return Order::withoutGlobalScopes()->create(array_merge([
            'tenant_id' => $tenantId,
            'order_number' => 'ORD-000001',
            'customer_name' => 'Test Customer',
            'customer_phone' => '01700000000',
            'status' => 'pending',
        ], $attrs));
    }

    // --- Messenger ------------------------------------------------------------------------

    public function test_messenger_returns_empty_string_when_this_psid_has_no_order(): void
    {
        $tenant = $this->makeTenant();

        $knowledge = app(AiCustomerMemoryService::class)->forMessengerCustomer($tenant->id, 'psid-none');

        $this->assertSame('', $knowledge);
    }

    public function test_messenger_finds_the_most_recent_order_for_this_exact_psid(): void
    {
        $tenant = $this->makeTenant();
        $this->makeOrder($tenant->id, ['order_number' => 'ORD-000001', 'messenger_psid' => 'psid-1', 'status' => 'delivered', 'created_at' => now()->subDay()]);
        $this->makeOrder($tenant->id, ['order_number' => 'ORD-000002', 'messenger_psid' => 'psid-1', 'status' => 'shipped', 'customer_address' => 'House 12, Road 5, Dhaka']);

        $knowledge = app(AiCustomerMemoryService::class)->forMessengerCustomer($tenant->id, 'psid-1');

        $this->assertStringContainsString('ORD-000002', $knowledge);
        $this->assertStringContainsString('shipped', $knowledge);
        $this->assertStringContainsString('House 12, Road 5, Dhaka', $knowledge);
        $this->assertStringNotContainsString('ORD-000001', $knowledge);
    }

    public function test_messenger_never_matches_a_different_psid(): void
    {
        $tenant = $this->makeTenant();
        $this->makeOrder($tenant->id, ['messenger_psid' => 'psid-real-customer']);

        // A different visitor's psid — even though it's a real order row in
        // the SAME tenant, this psid never placed it, so nothing must match.
        $knowledge = app(AiCustomerMemoryService::class)->forMessengerCustomer($tenant->id, 'psid-someone-else');

        $this->assertSame('', $knowledge);
    }

    public function test_messenger_never_leaks_tenant_as_order_to_tenant_b(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $this->makeOrder($tenantA->id, ['messenger_psid' => 'shared-psid-value']);

        $knowledgeForB = app(AiCustomerMemoryService::class)->forMessengerCustomer($tenantB->id, 'shared-psid-value');

        $this->assertSame('', $knowledgeForB);
    }

    public function test_messenger_lookup_failure_degrades_to_empty_string_instead_of_throwing(): void
    {
        $tenant = $this->makeTenant();
        $this->makeOrder($tenant->id, ['messenger_psid' => 'psid-1']);

        Schema::dropIfExists('orders');

        $knowledge = app(AiCustomerMemoryService::class)->forMessengerCustomer($tenant->id, 'psid-1');

        $this->assertSame('', $knowledge);
    }

    // --- WhatsApp -------------------------------------------------------------------------

    public function test_whatsapp_matches_the_verified_wa_id_against_the_stored_local_phone_format(): void
    {
        $tenant = $this->makeTenant();
        // orders.customer_phone is always stored in the app's local format
        // (leading 0, no country code) — see FraudChecker::normalizePhone().
        $this->makeOrder($tenant->id, ['order_number' => 'ORD-000005', 'customer_phone' => '01700000000', 'status' => 'confirmed']);

        // wa_id arrives from Meta as the full international format.
        $knowledge = app(AiCustomerMemoryService::class)->forWhatsAppCustomer($tenant->id, '8801700000000');

        $this->assertStringContainsString('ORD-000005', $knowledge);
        $this->assertStringContainsString('confirmed', $knowledge);
    }

    public function test_whatsapp_returns_empty_string_when_this_phone_has_no_order(): void
    {
        $tenant = $this->makeTenant();

        $knowledge = app(AiCustomerMemoryService::class)->forWhatsAppCustomer($tenant->id, '8801700000000');

        $this->assertSame('', $knowledge);
    }

    public function test_whatsapp_never_leaks_tenant_as_order_to_tenant_b(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $this->makeOrder($tenantA->id, ['customer_phone' => '01700000000']);

        $knowledgeForB = app(AiCustomerMemoryService::class)->forWhatsAppCustomer($tenantB->id, '8801700000000');

        $this->assertSame('', $knowledgeForB);
    }

    public function test_whatsapp_lookup_failure_degrades_to_empty_string_instead_of_throwing(): void
    {
        $tenant = $this->makeTenant();
        $this->makeOrder($tenant->id, ['customer_phone' => '01700000000']);

        Schema::dropIfExists('orders');

        $knowledge = app(AiCustomerMemoryService::class)->forWhatsAppCustomer($tenant->id, '8801700000000');

        $this->assertSame('', $knowledge);
    }
}
