<?php

namespace Tests\Feature\AiAgent;

use App\Jobs\ProcessAiAgentMessage;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithAiAgentSchema;
use Tests\TestCase;

/**
 * Phase 16 — the dedicated tenant-isolation security audit's one lasting
 * artifact (the audit itself found zero real gaps across every
 * withoutGlobalScopes() call site, AI tool schema, and cache key in
 * app/Services/AI/ — see the Phase 16 report). Every individual phase
 * (5-14) already has its own "tenant A never reaches tenant B" tests, but
 * each only exercises ONE context layer at a time. This file is
 * deliberately different: ONE realistic message, for a genuinely
 * SEPARATE tenant, with MAXIMUM identifier collision on purpose —
 * identical psid/wa_id, identical product names, identical order
 * numbers, identical style-example wording — so that if any future phase
 * ever accidentally weakens a tenant_id scope, this is the test most
 * likely to actually catch it (a narrower test using different
 * identifiers per tenant could pass by coincidence even with a broken
 * scope).
 *
 * Asserts BOTH directions on the same captured request: none of tenant
 * A's data appears (isolation), AND tenant B's own data DOES appear
 * (proves this isn't just an empty/broken context — the system still
 * works correctly for the legitimate tenant).
 */
class TenantIsolationAuditTest extends TestCase
{
    use InteractsWithAiAgentSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpAiAgentSchema();
    }

    protected function makeProduct(int $tenantId, string $name, float $sellingPrice): Product
    {
        app()->instance('currentTenant', Tenant::find($tenantId));

        $product = Product::create(['tenant_id' => $tenantId, 'name' => $name, 'is_active' => true]);
        ProductVariant::create([
            'tenant_id' => $tenantId, 'product_id' => $product->id, 'variant_name' => 'Default',
            'selling_price' => $sellingPrice, 'purchase_price' => 100,
        ]);

        return $product;
    }

    public function test_a_maximally_colliding_second_tenant_never_leaks_into_the_first_tenants_messenger_ai_call(): void
    {
        config(['ai.openai_api_key' => 'test-key']);
        $sharedPsid = 'shared-collision-psid';
        $sharedProductName = 'Shared Collision Product';

        // Tenant A — the victim. Everything an attacker (tenant B) must
        // never see.
        $tenantA = $this->makeTenant();
        $this->makeMessengerPage($tenantA->id, 'page-audit-a', ['is_active' => 1]);
        $this->enableAiAgentAndMessengerAutoReply($tenantA->id);
        $this->allocateAiCredit($tenantA->id, 100);
        $this->makeProduct($tenantA->id, $sharedProductName, 999999);
        DB::table('store_settings')->insert([
            'tenant_id' => $tenantA->id, 'key' => 'ai_custom_instructions',
            'value' => 'TENANT-A-SECRET-INSTRUCTION-99999', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('store_settings')->insert([
            'tenant_id' => $tenantA->id, 'key' => 'delivery_charge_inside_dhaka',
            'value' => '777', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('orders')->insert([
            'tenant_id' => $tenantA->id, 'order_number' => 'ORD-TENANT-A-SECRET', 'messenger_psid' => $sharedPsid,
            'customer_name' => 'Tenant A Victim', 'customer_phone' => '01700000001',
            'customer_address' => 'TENANT-A-SECRET-ADDRESS', 'status' => 'shipped',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('messenger_messages')->insert([
            ['tenant_id' => $tenantA->id, 'sender_psid' => 'other-psid-a', 'message_text' => 'প্রশ্ন', 'direction' => 'in', 'sent_by' => 'human', 'status' => 'contacted', 'created_at' => now()->subMinutes(5)],
            ['tenant_id' => $tenantA->id, 'sender_psid' => 'other-psid-a', 'message_text' => 'TENANT-A-SECRET-STYLE-PHRASE', 'direction' => 'out', 'sent_by' => 'human', 'status' => 'contacted', 'created_at' => now()->subMinutes(4)],
        ]);
        DB::table('ai_handoffs')->insert([
            'tenant_id' => $tenantA->id, 'channel' => 'messenger', 'external_id' => $sharedPsid,
            'reason' => 'customer_requested', 'created_at' => now(), 'resolved_at' => now(),
        ]);

        // Tenant B — the actual customer we're replying to, using the
        // EXACT SAME psid tenant A's own conversation used (Messenger
        // psids are unique per-Page in reality, but this deliberately
        // simulates the worst case, not the merely-likely one).
        $tenantB = $this->makeTenant();
        $this->makeMessengerPage($tenantB->id, 'page-audit-b', ['is_active' => 1]);
        $this->enableAiAgentAndMessengerAutoReply($tenantB->id);
        $this->allocateAiCredit($tenantB->id, 100);
        $this->makeProduct($tenantB->id, $sharedProductName, 500);
        DB::table('store_settings')->insert([
            'tenant_id' => $tenantB->id, 'key' => 'ai_custom_instructions',
            'value' => 'Tenant B own instruction', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('orders')->insert([
            'tenant_id' => $tenantB->id, 'order_number' => 'ORD-TENANT-B-OWN', 'messenger_psid' => $sharedPsid,
            'customer_name' => 'Tenant B Customer', 'customer_phone' => '01700000002',
            'customer_address' => 'Tenant B own address', 'status' => 'confirmed',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('messenger_messages')->insert([
            ['tenant_id' => $tenantB->id, 'sender_psid' => 'other-psid-b', 'message_text' => 'প্রশ্ন', 'direction' => 'in', 'sent_by' => 'human', 'status' => 'contacted', 'created_at' => now()->subMinutes(5)],
            ['tenant_id' => $tenantB->id, 'sender_psid' => 'other-psid-b', 'message_text' => 'Tenant B own style phrase', 'direction' => 'out', 'sent_by' => 'human', 'status' => 'contacted', 'created_at' => now()->subMinutes(4)],
        ]);

        $messageId = DB::table('messenger_messages')->insertGetId([
            'tenant_id' => $tenantB->id, 'sender_psid' => $sharedPsid, 'mid' => 'mid-audit-b',
            'message_text' => "{$sharedProductName} টার দাম কত? আমার অর্ডার কোথায়?",
            'direction' => 'in', 'status' => 'new', 'created_at' => now(),
        ]);
        DB::table('ai_agent_message_jobs')->insert([
            'tenant_id' => $tenantB->id, 'messenger_message_id' => $messageId, 'status' => 'pending',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        Http::fake([
            '*/chat/completions' => Http::response(['choices' => [['message' => ['content' => 'ok']]]]),
            '*/me/messages*' => Http::response(['message_id' => 'mid-audit-b-reply']),
        ]);

        ProcessAiAgentMessage::dispatch($tenantB->id, $messageId);

        $chatRequest = collect(Http::recorded())
            ->first(fn ($pair) => str_contains((string) $pair[0]->url(), 'chat/completions'));

        $this->assertNotNull($chatRequest, 'the chat/completions call must actually have been made');
        $content = $chatRequest[0]->data()['messages'][0]['content'] ?? '';
        $this->assertNotSame('', $content);

        // --- Isolation: nothing from tenant A anywhere in the prompt. ---
        $this->assertStringNotContainsString('TENANT-A-SECRET-INSTRUCTION-99999', $content);
        $this->assertStringNotContainsString('999999', $content, 'tenant A\'s price for the identically-named product must never appear');
        $this->assertStringNotContainsString('777', $content, 'tenant A\'s delivery charge must never appear');
        $this->assertStringNotContainsString('ORD-TENANT-A-SECRET', $content);
        $this->assertStringNotContainsString('TENANT-A-SECRET-ADDRESS', $content);
        $this->assertStringNotContainsString('TENANT-A-SECRET-STYLE-PHRASE', $content);
        $this->assertStringNotContainsString('Tenant A Victim', $content);

        // --- Correctness: tenant B's own data still flows normally. ---
        $this->assertStringContainsString('500', $content, "tenant B's own price for the shared product name must still appear");
        $this->assertStringContainsString('ORD-TENANT-B-OWN', $content);
        $this->assertStringContainsString($sharedProductName, $content);
    }
}
