<?php

namespace Tests\Feature\WhatsApp;

use App\Models\WhatsAppBusinessAccount;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppPhoneNumber;

/**
 * Confirms WhatsAppWebhookController resolves tenant ownership ONLY from
 * phone_number_id looked up server-side against whatsapp_phone_numbers —
 * never from any tenant_id-shaped field an attacker could put in the
 * request body — and that an unknown/inactive number is safely ignored
 * rather than erroring or guessing an owner.
 */
class WhatsAppTenantResolutionTest extends WhatsAppFeatureTestCase
{
    protected function payload(string $phoneNumberId, string $wamid, string $waId = '8801700000000'): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => 'waba-entry-id',
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => ['display_phone_number' => '+8801700000000', 'phone_number_id' => $phoneNumberId],
                        'contacts' => [['profile' => ['name' => 'Apo'], 'wa_id' => $waId]],
                        'messages' => [[
                            'from' => $waId, 'id' => $wamid, 'timestamp' => (string) time(),
                            'type' => 'text', 'text' => ['body' => 'hello'],
                        ]],
                    ],
                ]],
            ]],
        ];
    }

    protected function postSignedWebhook(array $payload): \Illuminate\Testing\TestResponse
    {
        $body = json_encode($payload);
        $signature = 'sha256='.hash_hmac('sha256', $body, 'test-secret');

        return $this->call('POST', '/webhook/whatsapp', [], [], [], $this->transformHeadersToServerVars([
            'X-Hub-Signature-256' => $signature,
            'CONTENT_TYPE' => 'application/json',
        ]), $body);
    }

    protected function connectPhoneNumber(int $tenantId, string $phoneNumberId): WhatsAppPhoneNumber
    {
        $user = $this->makeUser($tenantId);
        $account = WhatsAppBusinessAccount::withoutGlobalScopes()->create([
            'tenant_id' => $tenantId, 'connected_by_user_id' => $user->id,
            'waba_id' => 'waba-'.$tenantId, 'user_access_token' => 'token',
        ]);

        return WhatsAppPhoneNumber::withoutGlobalScopes()->create([
            'tenant_id' => $tenantId,
            'whatsapp_business_account_id' => $account->id,
            'phone_number_id' => $phoneNumberId,
            'is_active' => 1,
        ]);
    }

    public function test_known_phone_number_id_resolves_to_the_correct_tenant(): void
    {
        config(['whatsapp.app_secret' => 'test-secret']);

        $tenant = $this->makeTenant();
        $this->connectPhoneNumber($tenant->id, 'pnid-known');

        $this->postSignedWebhook($this->payload('pnid-known', 'wamid.known-1'))->assertOk();

        $message = WhatsAppMessage::withoutGlobalScopes()->where('wamid', 'wamid.known-1')->first();
        $this->assertNotNull($message);
        $this->assertSame($tenant->id, $message->tenant_id);
    }

    public function test_unknown_phone_number_id_is_safely_ignored(): void
    {
        config(['whatsapp.app_secret' => 'test-secret']);

        $this->postSignedWebhook($this->payload('pnid-nobody-owns-this', 'wamid.unknown-1'))->assertOk();

        $this->assertSame(0, WhatsAppMessage::withoutGlobalScopes()->count());
    }

    public function test_disconnected_phone_number_is_treated_as_unknown(): void
    {
        config(['whatsapp.app_secret' => 'test-secret']);

        $tenant = $this->makeTenant();
        $phone = $this->connectPhoneNumber($tenant->id, 'pnid-disconnected');
        $phone->update(['is_active' => 0]);

        $this->postSignedWebhook($this->payload('pnid-disconnected', 'wamid.disc-1'))->assertOk();

        $this->assertSame(0, WhatsAppMessage::withoutGlobalScopes()->count());
    }

    public function test_tenant_a_webhook_cannot_produce_messages_attributed_to_tenant_b(): void
    {
        config(['whatsapp.app_secret' => 'test-secret']);

        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $this->connectPhoneNumber($tenantA->id, 'pnid-a');
        $this->connectPhoneNumber($tenantB->id, 'pnid-b');

        $this->postSignedWebhook($this->payload('pnid-a', 'wamid.cross-a'))->assertOk();

        $message = WhatsAppMessage::withoutGlobalScopes()->where('wamid', 'wamid.cross-a')->first();
        $this->assertSame($tenantA->id, $message->tenant_id);
        $this->assertNotSame($tenantB->id, $message->tenant_id);

        // Reading through Tenant B's bound scope must see none of Tenant A's messages.
        app()->instance('currentTenant', $tenantB);
        $this->assertSame(0, WhatsAppMessage::count());

        app()->instance('currentTenant', $tenantA);
        $this->assertSame(1, WhatsAppMessage::count());
    }

    public function test_invalid_signature_is_rejected_before_any_tenant_resolution(): void
    {
        config(['whatsapp.app_secret' => 'test-secret']);

        $tenant = $this->makeTenant();
        $this->connectPhoneNumber($tenant->id, 'pnid-sig-test');

        $body = json_encode($this->payload('pnid-sig-test', 'wamid.sig-1'));

        $response = $this->call('POST', '/webhook/whatsapp', [], [], [], $this->transformHeadersToServerVars([
            'X-Hub-Signature-256' => 'sha256=deadbeef',
            'CONTENT_TYPE' => 'application/json',
        ]), $body);

        $response->assertStatus(403);
        $this->assertSame(0, WhatsAppMessage::withoutGlobalScopes()->count());
    }
}
