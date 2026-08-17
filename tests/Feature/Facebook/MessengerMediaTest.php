<?php

namespace Tests\Feature\Facebook;

use App\Models\FacebookConnection;
use App\Models\FacebookPage;
use App\Models\MessengerMessage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Media/attachment support for the Messenger inbox: incoming image/audio/
 * file attachments (type capture + re-hosting to our own durable storage,
 * since Meta's CDN URLs are time-limited), outgoing image sending, and
 * echo-dedup for media the same way it already works for text.
 */
class MessengerMediaTest extends FacebookFeatureTestCase
{
    protected function connectPage(int $tenantId, int $connectionId, string $pageId): FacebookPage
    {
        return FacebookPage::withoutGlobalScopes()->create([
            'tenant_id' => $tenantId,
            'facebook_connection_id' => $connectionId,
            'page_id' => $pageId,
            'page_name' => $pageId.' name',
            'page_access_token' => 'token-for-'.$pageId,
            'status' => 'active',
            'is_active' => true,
        ]);
    }

    protected function inboundAttachmentPayload(string $pageId, string $psid, string $mid, string $type, string $url): array
    {
        return [
            'object' => 'page',
            'entry' => [[
                'id' => $pageId,
                'messaging' => [[
                    'sender' => ['id' => $psid],
                    'recipient' => ['id' => $pageId],
                    'message' => ['mid' => $mid, 'attachments' => [['type' => $type, 'payload' => ['url' => $url]]]],
                ]],
            ]],
        ];
    }

    protected function echoAttachmentPayload(string $pageId, string $psid, string $mid, string $type, string $url): array
    {
        return [
            'object' => 'page',
            'entry' => [[
                'id' => $pageId,
                'messaging' => [[
                    'sender' => ['id' => $pageId],
                    'recipient' => ['id' => $psid],
                    'message' => ['is_echo' => true, 'mid' => $mid, 'attachments' => [['type' => $type, 'payload' => ['url' => $url]]]],
                ]],
            ]],
        ];
    }

    protected function postSignedWebhook(array $payload): \Illuminate\Testing\TestResponse
    {
        $body = json_encode($payload);
        $signature = 'sha256='.hash_hmac('sha256', $body, 'test-secret');

        return $this->call('POST', '/webhook/messenger', [], [], [], $this->transformHeadersToServerVars([
            'X-Hub-Signature-256' => $signature,
            'CONTENT_TYPE' => 'application/json',
        ]), $body);
    }

    protected function setUpConnectedPage(): array
    {
        config(['messenger.app_secret' => 'test-secret']);

        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $conn = FacebookConnection::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'connected_by_user_id' => $user->id,
            'facebook_user_id' => 'fbu', 'user_access_token' => 'tok',
        ]);
        $page = $this->connectPage($tenant->id, $conn->id, 'media-page-'.$tenant->id);

        return [$tenant, $user, $page];
    }

    public function test_incoming_image_is_typed_and_rehosted_to_durable_storage(): void
    {
        Storage::fake('public');
        [$tenant, , $page] = $this->setUpConnectedPage();

        Http::fake([
            'https://fake-cdn.test/*' => Http::response('FAKE-IMAGE-BYTES', 200, ['Content-Type' => 'image/jpeg']),
            '*' => Http::response([], 200),
        ]);

        $this->postSignedWebhook($this->inboundAttachmentPayload($page->page_id, 'cust-img-1', 'mid-img-1', 'image', 'https://fake-cdn.test/photo.jpg'))
            ->assertOk();

        $message = MessengerMessage::withoutGlobalScopes()->where('mid', 'mid-img-1')->first();

        $this->assertNotNull($message);
        $this->assertSame('image', $message->attachment_type);
        $this->assertSame('in', $message->direction);
        $this->assertStringContainsString('/storage/messenger/'.$tenant->id.'/', $message->attachment_url);
        $this->assertStringNotContainsString('fake-cdn.test', $message->attachment_url, 'the stored URL should be our own re-hosted copy, not Meta\'s temporary one');

        $path = ltrim(parse_url($message->attachment_url, PHP_URL_PATH), '/');
        $path = preg_replace('#^storage/#', '', $path);
        Storage::disk('public')->assertExists($path);
    }

    public function test_incoming_audio_is_typed_and_rehosted(): void
    {
        Storage::fake('public');
        [, , $page] = $this->setUpConnectedPage();

        Http::fake([
            'https://fake-cdn.test/*' => Http::response('FAKE-AUDIO-BYTES', 200, ['Content-Type' => 'audio/mpeg']),
            '*' => Http::response([], 200),
        ]);

        $this->postSignedWebhook($this->inboundAttachmentPayload($page->page_id, 'cust-audio-1', 'mid-audio-1', 'audio', 'https://fake-cdn.test/voice.mp4'))
            ->assertOk();

        $message = MessengerMessage::withoutGlobalScopes()->where('mid', 'mid-audio-1')->first();

        $this->assertNotNull($message);
        $this->assertSame('audio', $message->attachment_type);
        $this->assertStringEndsWith('.mp3', $message->attachment_url, 'extension should be derived from Content-Type, not the source URL');
    }

    public function test_incoming_file_captures_type_and_filename(): void
    {
        Storage::fake('public');
        [, , $page] = $this->setUpConnectedPage();

        Http::fake([
            'https://fake-cdn.test/*' => Http::response('%PDF-FAKE', 200, ['Content-Type' => 'application/pdf']),
            '*' => Http::response([], 200),
        ]);

        $this->postSignedWebhook($this->inboundAttachmentPayload($page->page_id, 'cust-file-1', 'mid-file-1', 'file', 'https://fake-cdn.test/invoice.pdf'))
            ->assertOk();

        $message = MessengerMessage::withoutGlobalScopes()->where('mid', 'mid-file-1')->first();

        $this->assertNotNull($message);
        $this->assertSame('file', $message->attachment_type);
        $this->assertSame('invoice.pdf', $message->attachment_name);
    }

    public function test_expired_or_unreachable_attachment_falls_back_to_original_url_without_losing_the_message(): void
    {
        Storage::fake('public');
        [, , $page] = $this->setUpConnectedPage();

        Http::fake([
            // Simulates an expired/dead Meta CDN link.
            'https://fake-cdn.test/*' => Http::response('', 404),
            '*' => Http::response([], 200),
        ]);

        $response = $this->postSignedWebhook($this->inboundAttachmentPayload($page->page_id, 'cust-expired-1', 'mid-expired-1', 'image', 'https://fake-cdn.test/gone.jpg'));

        $response->assertOk();

        $message = MessengerMessage::withoutGlobalScopes()->where('mid', 'mid-expired-1')->first();

        $this->assertNotNull($message, 'the message must still be recorded even when re-hosting the attachment fails');
        $this->assertSame('image', $message->attachment_type);
        $this->assertSame('https://fake-cdn.test/gone.jpg', $message->attachment_url, 'falls back to the original URL when re-hosting fails');
    }

    public function test_outgoing_image_is_sent_and_recorded(): void
    {
        Storage::fake('public');
        [$tenant, $user, $page] = $this->setUpConnectedPage();

        // Prime the conversation so resolveReplyToken() can find this page.
        MessengerMessage::create([
            'tenant_id' => $tenant->id, 'facebook_page_id' => $page->id,
            'sender_psid' => 'cust-out-img-1', 'message_text' => 'hi', 'direction' => 'in', 'status' => 'new',
        ]);

        Http::fake(['*/me/messages*' => Http::response(['message_id' => 'mid-out-img-1'])]);

        $file = UploadedFile::fake()->image('product.jpg', 300, 300);

        $response = $this->actingAs($user, 'tenant')->post(
            '/shop/'.$tenant->subdomain.'/panel/messenger/cust-out-img-1/reply',
            ['image' => $file],
        );

        $response->assertRedirect();

        $message = MessengerMessage::withoutGlobalScopes()->where('mid', 'mid-out-img-1')->first();

        $this->assertNotNull($message);
        $this->assertSame('out', $message->direction);
        $this->assertSame('image', $message->attachment_type);
        $this->assertStringContainsString('/storage/messenger/'.$tenant->id.'/outgoing/', $message->attachment_url);

        Http::assertSent(fn ($request) => str_contains((string) $request->body(), '"type":"image"'));
    }

    public function test_echo_of_a_panel_sent_image_does_not_duplicate_it(): void
    {
        Storage::fake('public');
        [$tenant, $user, $page] = $this->setUpConnectedPage();

        MessengerMessage::create([
            'tenant_id' => $tenant->id, 'facebook_page_id' => $page->id,
            'sender_psid' => 'cust-echo-img-1', 'message_text' => 'hi', 'direction' => 'in', 'status' => 'new',
        ]);

        Http::fake(['*/me/messages*' => Http::response(['message_id' => 'mid-echo-img-1'])]);

        $this->actingAs($user, 'tenant')->post(
            '/shop/'.$tenant->subdomain.'/panel/messenger/cust-echo-img-1/reply',
            ['image' => UploadedFile::fake()->image('sent.jpg')],
        )->assertRedirect();

        $this->assertSame(1, MessengerMessage::withoutGlobalScopes()->where('mid', 'mid-echo-img-1')->count());

        // Meta's message_echoes event for that exact send arrives afterward.
        $sentUrl = MessengerMessage::withoutGlobalScopes()->where('mid', 'mid-echo-img-1')->value('attachment_url');

        $this->postSignedWebhook($this->echoAttachmentPayload($page->page_id, 'cust-echo-img-1', 'mid-echo-img-1', 'image', $sentUrl))
            ->assertOk();

        $this->assertSame(
            1,
            MessengerMessage::withoutGlobalScopes()->where('mid', 'mid-echo-img-1')->count(),
            'the echo of our own panel-sent image must not create a second row'
        );
    }

    public function test_reply_with_no_message_and_no_image_is_rejected(): void
    {
        [$tenant, $user, $page] = $this->setUpConnectedPage();

        MessengerMessage::create([
            'tenant_id' => $tenant->id, 'facebook_page_id' => $page->id,
            'sender_psid' => 'cust-empty-1', 'message_text' => 'hi', 'direction' => 'in', 'status' => 'new',
        ]);

        $response = $this->actingAs($user, 'tenant')->post(
            '/shop/'.$tenant->subdomain.'/panel/messenger/cust-empty-1/reply',
            [],
        );

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertSame(0, MessengerMessage::withoutGlobalScopes()->where('sender_psid', 'cust-empty-1')->where('direction', 'out')->count());
    }
}
