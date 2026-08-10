<?php

namespace Tests\Feature\Facebook;

use App\Models\FacebookConnection;
use App\Models\FacebookPage;
use App\Models\MessengerMessage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Regression coverage for the production outage this app hit when
 * database/sql/chunk25.sql (attachment_type/attachment_name columns) was
 * not yet imported: MessengerWebhookController and MessengerInboxController
 * used to put those two keys in every MessengerMessage::create() call
 * unconditionally, so EVERY incoming message — not just ones with
 * attachments — threw "Unknown column 'attachment_type'" and the webhook
 * 500'd. Both call sites are now guarded by
 * MessengerMessage::attachmentColumnsReady(), mirroring the pre-existing
 * FacebookPage::tablesReady() pattern for facebook_page_id.
 */
class MessengerAttachmentColumnGuardTest extends FacebookFeatureTestCase
{
    protected bool $includeAttachmentColumns = false;

    public function test_attachment_columns_ready_is_false_when_chunk25_not_imported(): void
    {
        $this->assertFalse(MessengerMessage::attachmentColumnsReady());
        $this->assertFalse(Schema::hasColumn('messenger_messages', 'attachment_type'));
    }

    public function test_incoming_text_message_still_saves_without_attachment_columns(): void
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
            'page_id' => 'guard-page', 'page_access_token' => 'page-tok', 'status' => 'active', 'is_active' => true,
        ]);

        $body = json_encode([
            'object' => 'page',
            'entry' => [[
                'id' => 'guard-page',
                'messaging' => [[
                    'sender' => ['id' => 'cust-guard-1'],
                    'message' => ['mid' => 'mid-guard-1', 'text' => 'Hello'],
                ]],
            ]],
        ]);
        $signature = 'sha256='.hash_hmac('sha256', $body, 'test-secret');

        $response = $this->call('POST', '/webhook/messenger', [], [], [], $this->transformHeadersToServerVars([
            'X-Hub-Signature-256' => $signature,
            'CONTENT_TYPE' => 'application/json',
        ]), $body);

        // Before the guard, this would 500 with "Unknown column
        // 'attachment_type'" — the whole point of this test.
        $response->assertOk();

        $message = MessengerMessage::withoutGlobalScopes()->where('mid', 'mid-guard-1')->first();
        $this->assertNotNull($message, 'the message must be saved even though chunk25.sql has not run yet');
        $this->assertSame($tenant->id, $message->tenant_id);
        $this->assertSame($page->id, $message->facebook_page_id);
    }

    public function test_outgoing_image_reply_still_saves_without_attachment_columns(): void
    {
        Storage::fake('public');

        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $conn = FacebookConnection::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'connected_by_user_id' => $user->id,
            'facebook_user_id' => 'fbu', 'user_access_token' => 'tok',
        ]);
        $page = FacebookPage::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'facebook_connection_id' => $conn->id,
            'page_id' => 'guard-out-page', 'page_access_token' => 'page-tok', 'status' => 'active', 'is_active' => true,
        ]);

        MessengerMessage::create([
            'tenant_id' => $tenant->id, 'facebook_page_id' => $page->id,
            'sender_psid' => 'cust-guard-out-1', 'message_text' => 'hi', 'direction' => 'in', 'status' => 'new',
        ]);

        Http::fake(['*/me/messages*' => Http::response(['message_id' => 'mid-guard-out-1'])]);

        $response = $this->actingAs($user, 'tenant')->post(
            '/shop/'.$tenant->subdomain.'/panel/messenger/cust-guard-out-1/reply',
            ['image' => UploadedFile::fake()->image('product.jpg', 300, 300)],
        );

        // Before the guard, this would throw on the create() call and never
        // redirect back cleanly.
        $response->assertRedirect();
        $response->assertSessionMissing('error');

        $message = MessengerMessage::withoutGlobalScopes()->where('mid', 'mid-guard-out-1')->first();
        $this->assertNotNull($message, 'the outgoing image message must be saved even though chunk25.sql has not run yet');
        $this->assertSame('out', $message->direction);
    }
}
