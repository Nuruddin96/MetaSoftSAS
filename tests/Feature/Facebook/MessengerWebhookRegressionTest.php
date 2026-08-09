<?php

namespace Tests\Feature\Facebook;

use App\Models\FacebookConnection;
use App\Models\FacebookPage;
use App\Models\MessengerMessage;
use Illuminate\Support\Facades\DB;

/**
 * Confirms MessengerWebhookController's signature verification and mid-based
 * duplicate-event protection are unchanged by the new facebook_pages
 * resolution step — see resolvePageOwner() in that controller.
 */
class MessengerWebhookRegressionTest extends FacebookFeatureTestCase
{
    protected function payload(string $pageId, string $mid, string $psid = 'psid-1'): array
    {
        return [
            'object' => 'page',
            'entry' => [[
                'id' => $pageId,
                'messaging' => [[
                    'sender' => ['id' => $psid],
                    'message' => ['mid' => $mid, 'text' => 'Hello there'],
                ]],
            ]],
        ];
    }

    protected function postSignedWebhook(string $body, string $signature): \Illuminate\Testing\TestResponse
    {
        return $this->call('POST', '/webhook/messenger', [], [], [], $this->transformHeadersToServerVars([
            'X-Hub-Signature-256' => $signature,
            'CONTENT_TYPE' => 'application/json',
        ]), $body);
    }

    public function test_invalid_signature_is_still_rejected(): void
    {
        config(['messenger.app_secret' => 'test-secret']);

        $body = json_encode($this->payload('any-page', 'mid-1'));

        $this->postSignedWebhook($body, 'sha256=deadbeef')->assertStatus(403);

        $this->assertSame(0, MessengerMessage::withoutGlobalScopes()->count());
    }

    public function test_missing_signature_is_still_rejected(): void
    {
        config(['messenger.app_secret' => 'test-secret']);

        $body = json_encode($this->payload('any-page', 'mid-2'));

        $response = $this->call('POST', '/webhook/messenger', [], [], [], $this->transformHeadersToServerVars([
            'CONTENT_TYPE' => 'application/json',
        ]), $body);

        $response->assertStatus(403);
    }

    public function test_duplicate_mid_is_still_deduped_when_resolved_via_facebook_pages(): void
    {
        config(['messenger.app_secret' => 'test-secret']);

        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $conn = FacebookConnection::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'connected_by_user_id' => $user->id,
            'facebook_user_id' => 'fbu', 'user_access_token' => 'tok',
        ]);
        $page = FacebookPage::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'facebook_connection_id' => $conn->id,
            'page_id' => 'oauth-page', 'page_access_token' => 'page-tok', 'status' => 'active', 'is_active' => true,
        ]);

        $body = json_encode($this->payload('oauth-page', 'dup-mid-1'));
        $signature = 'sha256='.hash_hmac('sha256', $body, 'test-secret');

        $this->postSignedWebhook($body, $signature)->assertOk();
        $this->postSignedWebhook($body, $signature)->assertOk(); // simulated Meta retry of the same event

        $this->assertSame(
            1,
            MessengerMessage::withoutGlobalScopes()->where('mid', 'dup-mid-1')->count(),
            'a retried webhook delivery with the same mid must not create a second row'
        );

        $stored = MessengerMessage::withoutGlobalScopes()->where('mid', 'dup-mid-1')->first();
        $this->assertSame($tenant->id, $stored->tenant_id);
        $this->assertSame($page->id, $stored->facebook_page_id);
    }

    public function test_legacy_messenger_settings_resolution_still_works_unchanged(): void
    {
        config(['messenger.app_secret' => 'test-secret']);

        $tenant = $this->makeTenant();
        DB::table('messenger_settings')->insert([
            'tenant_id' => $tenant->id,
            'page_id' => 'legacy-page',
            'page_access_token' => encrypt('legacy-token'),
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $body = json_encode($this->payload('legacy-page', 'legacy-mid-1'));
        $signature = 'sha256='.hash_hmac('sha256', $body, 'test-secret');

        $this->postSignedWebhook($body, $signature)->assertOk();

        $message = MessengerMessage::withoutGlobalScopes()->where('mid', 'legacy-mid-1')->first();
        $this->assertNotNull($message, 'legacy messenger_settings-resolved pages must still receive messages');
        $this->assertSame($tenant->id, $message->tenant_id);
        $this->assertNull($message->facebook_page_id, 'legacy-path messages have no OAuth-connected page to attribute');
    }

    public function test_unconnected_page_id_is_silently_ignored(): void
    {
        config(['messenger.app_secret' => 'test-secret']);

        $body = json_encode($this->payload('nobody-owns-this-page', 'mid-x'));
        $signature = 'sha256='.hash_hmac('sha256', $body, 'test-secret');

        $this->postSignedWebhook($body, $signature)->assertOk();

        $this->assertSame(0, MessengerMessage::withoutGlobalScopes()->count());
    }
}
