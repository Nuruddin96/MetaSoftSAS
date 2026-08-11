<?php

namespace Tests\Feature\WhatsApp;

use App\Models\WhatsAppBusinessAccount;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppPhoneNumber;
use Illuminate\Database\QueryException;

/**
 * Confirms wamid-based dedup survives both a same-event webhook retry
 * (the exists() fast path) and a true race where two inserts for the same
 * wamid both pass the exists() check before either commits (the
 * UNIQUE(wamid) constraint from chunk26.sql is what actually closes that
 * window, not the exists() check itself — see WhatsAppWebhookController::
 * handleIncomingMessage()'s docblock). Mirrors
 * MessengerWebhookRegressionTest::test_duplicate_mid_is_still_deduped...().
 */
class WhatsAppWebhookDuplicateTest extends WhatsAppFeatureTestCase
{
    protected function setUpConnectedTenant(): int
    {
        config(['whatsapp.app_secret' => 'test-secret']);

        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $account = WhatsAppBusinessAccount::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'connected_by_user_id' => $user->id,
            'waba_id' => 'waba-dup', 'user_access_token' => 'token',
        ]);
        WhatsAppPhoneNumber::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'whatsapp_business_account_id' => $account->id,
            'phone_number_id' => 'pnid-dup', 'is_active' => 1,
        ]);

        return $tenant->id;
    }

    protected function payload(string $wamid): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => 'waba-dup',
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => ['phone_number_id' => 'pnid-dup'],
                        'contacts' => [['profile' => ['name' => 'Apo'], 'wa_id' => '8801700000000']],
                        'messages' => [[
                            'from' => '8801700000000', 'id' => $wamid, 'timestamp' => (string) time(),
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

    public function test_a_retried_webhook_delivery_with_the_same_wamid_does_not_create_a_second_row(): void
    {
        $this->setUpConnectedTenant();

        $payload = $this->payload('wamid.dup-1');

        $this->postSignedWebhook($payload)->assertOk();
        $this->postSignedWebhook($payload)->assertOk(); // simulated Meta retry of the exact same event

        $this->assertSame(
            1,
            WhatsAppMessage::withoutGlobalScopes()->where('wamid', 'wamid.dup-1')->count(),
            'a retried webhook delivery with the same wamid must not create a second row'
        );
    }

    public function test_database_constraint_rejects_a_second_insert_that_races_past_the_exists_check(): void
    {
        // Directly exercises the actual guarantee (UNIQUE(wamid)), not the
        // exists() pre-check the controller also does — simulates two
        // requests that both read "not found" before either commits.
        $tenantId = $this->setUpConnectedTenant();

        WhatsAppMessage::withoutGlobalScopes()->create([
            'tenant_id' => $tenantId, 'wa_id' => '8801700000000', 'wamid' => 'wamid.race-1', 'message_text' => 'first',
        ]);

        $this->expectException(QueryException::class);

        WhatsAppMessage::withoutGlobalScopes()->create([
            'tenant_id' => $tenantId, 'wa_id' => '8801700000000', 'wamid' => 'wamid.race-1', 'message_text' => 'second',
        ]);
    }
}
