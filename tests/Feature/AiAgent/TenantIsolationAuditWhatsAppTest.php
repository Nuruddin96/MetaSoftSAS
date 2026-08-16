<?php

namespace Tests\Feature\AiAgent;

use App\Jobs\ProcessWhatsAppAiAgentMessage;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Tenant;
use App\Models\WhatsAppBusinessAccount;
use App\Models\WhatsAppPhoneNumber;
use App\Services\AI\Providers\AiProviderInterface;
use App\Services\AI\Providers\AiProviderResponse;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithWhatsAppSchema;
use Tests\TestCase;

/**
 * WhatsApp counterpart of TenantIsolationAuditTest — see that class's
 * docblock for the full "maximum deliberate collision" rationale. Worth
 * its own file rather than reuse: WhatsApp's customer-memory linkage
 * (AiCustomerMemoryService::forWhatsAppCustomer()) matches by NORMALIZED
 * PHONE NUMBER against orders.customer_phone, a genuinely different code
 * path from Messenger's messenger_psid column match — a scoping mistake
 * in one would not necessarily exist in the other.
 */
class TenantIsolationAuditWhatsAppTest extends TestCase
{
    use InteractsWithWhatsAppSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpWhatsAppSchema();
    }

    protected function connectPhoneNumber(int $tenantId, string $phoneNumberId = 'pnid-1'): void
    {
        $user = $this->makeUser($tenantId);
        $account = WhatsAppBusinessAccount::withoutGlobalScopes()->create([
            'tenant_id' => $tenantId, 'connected_by_user_id' => $user->id,
            'waba_id' => 'waba-'.$phoneNumberId, 'user_access_token' => 'token',
        ]);
        WhatsAppPhoneNumber::withoutGlobalScopes()->create([
            'tenant_id' => $tenantId, 'whatsapp_business_account_id' => $account->id,
            'phone_number_id' => $phoneNumberId, 'is_active' => 1, 'status' => 'active',
        ]);
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

    public function test_a_maximally_colliding_second_tenant_never_leaks_into_the_first_tenants_whatsapp_ai_call(): void
    {
        config(['ai.openai_api_key' => 'test-key']);
        $sharedWaId = '8801700000099';
        $sharedLocalPhone = '01700000099';
        $sharedProductName = 'Shared Collision Product';

        // Both tenants' identity setup (connectPhoneNumber -> makeUser(),
        // which reads through User's own BelongsToTenant scope) happens
        // BEFORE either tenant's makeProduct() call below — makeProduct()
        // binds app('currentTenant'), and that binding persists in the
        // container afterward, so a LATER connectPhoneNumber() call for
        // the OTHER tenant would otherwise look its user up through the
        // wrong tenant's scope and find nothing.
        $tenantA = $this->makeTenant();
        $this->connectPhoneNumber($tenantA->id, 'pnid-audit-a');
        $this->enableAiAgentAndWhatsAppAutoReply($tenantA->id);
        $this->allocateAiCredit($tenantA->id, 100);

        $tenantB = $this->makeTenant();
        $this->connectPhoneNumber($tenantB->id, 'pnid-audit-b');
        $this->enableAiAgentAndWhatsAppAutoReply($tenantB->id);
        $this->allocateAiCredit($tenantB->id, 100);

        // Now safe to bind currentTenant per product — no more
        // connectPhoneNumber()/makeUser() calls remain after this point.
        $this->makeProduct($tenantA->id, $sharedProductName, 999999);
        $this->makeProduct($tenantB->id, $sharedProductName, 500);

        // Tenant A — the victim. Everything an attacker (tenant B) must
        // never see.
        DB::table('store_settings')->insert([
            'tenant_id' => $tenantA->id, 'key' => 'ai_custom_instructions',
            'value' => 'TENANT-A-SECRET-INSTRUCTION-99999', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('orders')->insert([
            'tenant_id' => $tenantA->id, 'order_number' => 'ORD-TENANT-A-SECRET',
            'customer_name' => 'Tenant A Victim', 'customer_phone' => $sharedLocalPhone,
            'customer_address' => 'TENANT-A-SECRET-ADDRESS', 'status' => 'shipped',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('whatsapp_messages')->insert([
            ['tenant_id' => $tenantA->id, 'wa_id' => '8801700000011', 'message_type' => 'text', 'message_text' => 'প্রশ্ন', 'direction' => 'in', 'sent_by' => 'human', 'status' => 'contacted', 'created_at' => now()->subMinutes(5)],
            ['tenant_id' => $tenantA->id, 'wa_id' => '8801700000011', 'message_type' => 'text', 'message_text' => 'TENANT-A-SECRET-STYLE-PHRASE', 'direction' => 'out', 'sent_by' => 'human', 'status' => 'contacted', 'created_at' => now()->subMinutes(4)],
        ]);

        // Tenant B — the actual customer we're replying to, using the
        // EXACT SAME wa_id/phone number tenant A's own order used
        // (deliberately the worst case, not the merely-likely one).
        DB::table('orders')->insert([
            'tenant_id' => $tenantB->id, 'order_number' => 'ORD-TENANT-B-OWN',
            'customer_name' => 'Tenant B Customer', 'customer_phone' => $sharedLocalPhone,
            'customer_address' => 'Tenant B own address', 'status' => 'confirmed',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('whatsapp_messages')->insert([
            ['tenant_id' => $tenantB->id, 'wa_id' => '8801700000022', 'message_type' => 'text', 'message_text' => 'প্রশ্ন', 'direction' => 'in', 'sent_by' => 'human', 'status' => 'contacted', 'created_at' => now()->subMinutes(5)],
            ['tenant_id' => $tenantB->id, 'wa_id' => '8801700000022', 'message_type' => 'text', 'message_text' => 'Tenant B own style phrase', 'direction' => 'out', 'sent_by' => 'human', 'status' => 'contacted', 'created_at' => now()->subMinutes(4)],
        ]);

        $messageId = DB::table('whatsapp_messages')->insertGetId([
            'tenant_id' => $tenantB->id, 'wa_id' => $sharedWaId, 'wamid' => 'wamid.audit-b',
            'message_type' => 'text', 'message_text' => "{$sharedProductName} টার দাম কত? আমার অর্ডার কোথায়?",
            'direction' => 'in', 'status' => 'new', 'created_at' => now(),
        ]);
        DB::table('ai_whatsapp_message_jobs')->insert([
            'tenant_id' => $tenantB->id, 'whatsapp_message_id' => $messageId, 'status' => 'pending',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $capturedMessages = null;
        $this->app->bind(AiProviderInterface::class, function () use (&$capturedMessages) {
            return new class($capturedMessages) implements AiProviderInterface
            {
                public function __construct(private &$capturedMessages) {}

                public function chat(array $messages, array $tools = []): AiProviderResponse
                {
                    $this->capturedMessages = $messages;

                    return AiProviderResponse::success('ok', 1, 1, 'fake-model');
                }
            };
        });

        ProcessWhatsAppAiAgentMessage::dispatch($tenantB->id, $messageId);

        $this->assertNotNull($capturedMessages, 'the provider must actually have been called');
        $content = $capturedMessages[0]['content'] ?? '';
        $this->assertNotSame('', $content);

        // --- Isolation: nothing from tenant A anywhere in the prompt. ---
        $this->assertStringNotContainsString('TENANT-A-SECRET-INSTRUCTION-99999', $content);
        $this->assertStringNotContainsString('999999', $content, 'tenant A\'s price for the identically-named product must never appear');
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
