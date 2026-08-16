<?php

namespace Tests\Feature\WhatsApp;

use App\Models\WhatsAppBusinessAccount;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppPhoneNumber;
use App\Services\WhatsApp\WhatsAppSendService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class WhatsAppSendServiceTest extends WhatsAppFeatureTestCase
{
    protected function connectTenant(array $phoneAttrs = [], array $accountAttrs = []): array
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);

        $account = WhatsAppBusinessAccount::withoutGlobalScopes()->create(array_merge([
            'tenant_id' => $tenant->id,
            'connected_by_user_id' => $user->id,
            'waba_id' => 'waba-'.$tenant->id,
            'user_access_token' => 'secret-token-'.$tenant->id,
        ], $accountAttrs));

        $phone = WhatsAppPhoneNumber::withoutGlobalScopes()->create(array_merge([
            'tenant_id' => $tenant->id,
            'whatsapp_business_account_id' => $account->id,
            'phone_number_id' => 'pnid-'.$tenant->id,
            'is_active' => 1,
            'status' => 'active',
        ], $phoneAttrs));

        return [$tenant, $account, $phone];
    }

    public function test_text_send_success_persists_message_with_returned_wamid(): void
    {
        [$tenant] = $this->connectTenant();
        Http::fake(['*/messages' => Http::response(['messages' => [['id' => 'wamid.sent-text-1']]])]);

        $result = (new WhatsAppSendService)->sendText($tenant, '8801700000000', 'হ্যালো, আপনার অর্ডার কনফার্ম হয়েছে।');

        $this->assertTrue($result->successful);
        $this->assertSame('wamid.sent-text-1', $result->message->wamid);
        $this->assertSame('sent', $result->message->delivery_status);
        $this->assertSame('out', $result->message->direction);
        $this->assertSame('contacted', $result->message->status);
        $this->assertSame('text', $result->message->message_type);
        $this->assertSame($tenant->id, $result->message->tenant_id);

        $this->assertDatabaseHas('whatsapp_messages', [
            'wamid' => 'wamid.sent-text-1', 'delivery_status' => 'sent', 'direction' => 'out',
        ]);
    }

    /** Phase 7 — see chunk37.sql and AiConversationStyleService::whatsappStyleExamples()'s docblock for why this default matters. */
    public function test_sent_by_defaults_to_human_when_not_specified(): void
    {
        [$tenant] = $this->connectTenant();
        Http::fake(['*/messages' => Http::response(['messages' => [['id' => 'wamid.sentby-default']]])]);

        $result = (new WhatsAppSendService)->sendText($tenant, '8801700000000', 'হ্যালো');

        $this->assertSame('human', $result->message->sent_by);
    }

    public function test_sent_by_ai_is_persisted_when_explicitly_passed(): void
    {
        [$tenant] = $this->connectTenant();
        Http::fake(['*/messages' => Http::response(['messages' => [['id' => 'wamid.sentby-ai']]])]);

        $result = (new WhatsAppSendService)->sendText($tenant, '8801700000000', 'হ্যালো', sentBy: 'ai');

        $this->assertSame('ai', $result->message->sent_by);
    }

    public function test_sent_by_ai_is_persisted_even_on_a_failed_send(): void
    {
        [$tenant] = $this->connectTenant();
        Http::fake(['*/messages' => Http::response(['error' => ['code' => 131047, 'message' => 'rejected']], 400)]);

        $result = (new WhatsAppSendService)->sendText($tenant, '8801700000000', 'হ্যালো', sentBy: 'ai');

        $this->assertFalse($result->successful);
        $this->assertSame('ai', $result->message->sent_by);
    }

    public function test_image_send_success_persists_attachment_fields_and_caption(): void
    {
        [$tenant] = $this->connectTenant();
        Http::fake(['*/messages' => Http::response(['messages' => [['id' => 'wamid.sent-image-1']]])]);

        $result = (new WhatsAppSendService)->sendMedia(
            $tenant, '8801700000000', 'image', 'https://example.test/storage/product.jpg', 'এই প্রোডাক্টটি দেখুন'
        );

        $this->assertTrue($result->successful);
        $this->assertSame('image', $result->message->message_type);
        $this->assertSame('image', $result->message->attachment_type);
        $this->assertSame('https://example.test/storage/product.jpg', $result->message->attachment_url);
        $this->assertSame('এই প্রোডাক্টটি দেখুন', $result->message->message_text);
        $this->assertSame('wamid.sent-image-1', $result->message->wamid);
    }

    public function test_document_send_success_persists_filename(): void
    {
        [$tenant] = $this->connectTenant();
        Http::fake(['*/messages' => Http::response(['messages' => [['id' => 'wamid.sent-doc-1']]])]);

        $result = (new WhatsAppSendService)->sendMedia(
            $tenant, '8801700000000', 'document', 'https://example.test/storage/invoice.pdf', 'আপনার ইনভয়েস', 'invoice.pdf'
        );

        $this->assertTrue($result->successful);
        $this->assertSame('document', $result->message->attachment_type);
        $this->assertSame('invoice.pdf', $result->message->attachment_name);
        $this->assertSame('আপনার ইনভয়েস', $result->message->message_text);
    }

    public function test_audio_send_success_ignores_caption_since_the_api_does_not_support_it(): void
    {
        [$tenant] = $this->connectTenant();
        Http::fake(['*/messages' => Http::response(['messages' => [['id' => 'wamid.sent-audio-1']]])]);

        $result = (new WhatsAppSendService)->sendMedia(
            $tenant, '8801700000000', 'audio', 'https://example.test/storage/note.ogg', 'this caption should be dropped'
        );

        $this->assertTrue($result->successful);
        $this->assertNull($result->message->message_text);

        Http::assertSent(function ($request) {
            $body = json_decode((string) $request->body(), true);

            return ! isset($body['audio']['caption']);
        });
    }

    public function test_video_send_success(): void
    {
        [$tenant] = $this->connectTenant();
        Http::fake(['*/messages' => Http::response(['messages' => [['id' => 'wamid.sent-video-1']]])]);

        $result = (new WhatsAppSendService)->sendMedia($tenant, '8801700000000', 'video', 'https://example.test/storage/demo.mp4');

        $this->assertTrue($result->successful);
        $this->assertSame('video', $result->message->attachment_type);
    }

    public function test_sticker_send_success(): void
    {
        [$tenant] = $this->connectTenant();
        Http::fake(['*/messages' => Http::response(['messages' => [['id' => 'wamid.sent-sticker-1']]])]);

        $result = (new WhatsAppSendService)->sendMedia($tenant, '8801700000000', 'sticker', 'https://example.test/storage/sticker.webp');

        $this->assertTrue($result->successful);
        $this->assertSame('sticker', $result->message->attachment_type);
    }

    public function test_unsupported_media_type_throws_before_any_http_call(): void
    {
        [$tenant] = $this->connectTenant();
        Http::fake();

        $this->expectException(\InvalidArgumentException::class);

        (new WhatsAppSendService)->sendMedia($tenant, '8801700000000', 'reaction', 'https://example.test/x');
    }

    public function test_api_failure_does_not_persist_a_successful_message(): void
    {
        [$tenant] = $this->connectTenant();
        Http::fake(['*/messages' => Http::response([
            'error' => ['message' => 'Invalid recipient phone number', 'type' => 'OAuthException', 'code' => 131030],
        ], 400)]);

        $result = (new WhatsAppSendService)->sendText($tenant, 'bad-number', 'hi');

        $this->assertFalse($result->successful);
        $this->assertSame('131030', $result->errorCode);
        $this->assertSame('Invalid recipient phone number', $result->errorMessage);
        $this->assertNull($result->message->wamid);
        $this->assertSame('failed', $result->message->delivery_status);
        $this->assertSame('131030', $result->message->error_code);

        $this->assertSame(
            0,
            WhatsAppMessage::withoutGlobalScopes()->where('delivery_status', 'sent')->count(),
            'a rejected send must never leave a row that looks like it succeeded'
        );
    }

    public function test_invalid_or_expired_token_marks_the_phone_number_as_needing_reconnect(): void
    {
        [$tenant, , $phone] = $this->connectTenant();
        Http::fake(['*/messages' => Http::response([
            'error' => ['message' => 'Error validating access token', 'type' => 'OAuthException', 'code' => 190],
        ], 401)]);

        $result = (new WhatsAppSendService)->sendText($tenant, '8801700000000', 'hi');

        $this->assertFalse($result->successful);
        $this->assertSame('needs_reconnect', $phone->fresh()->status);
    }

    public function test_network_failure_fails_gracefully_without_throwing(): void
    {
        [$tenant] = $this->connectTenant();
        Http::fake(function () {
            throw new ConnectionException('Connection timed out');
        });

        $result = (new WhatsAppSendService)->sendText($tenant, '8801700000000', 'hi');

        $this->assertFalse($result->successful);
        $this->assertNull($result->message);
    }

    public function test_tenant_with_no_connected_whatsapp_number_fails_without_any_http_call(): void
    {
        $tenant = $this->makeTenant();
        Http::fake();

        $result = (new WhatsAppSendService)->sendText($tenant, '8801700000000', 'hi');

        $this->assertFalse($result->successful);
        $this->assertNull($result->message);
        Http::assertNothingSent();
    }

    public function test_correct_phone_number_id_is_used_in_the_request_url(): void
    {
        [$tenant] = $this->connectTenant(['phone_number_id' => 'pnid-explicit-check']);
        Http::fake(['*/messages' => Http::response(['messages' => [['id' => 'wamid.url-check']]])]);

        (new WhatsAppSendService)->sendText($tenant, '8801700000000', 'hi');

        Http::assertSent(function ($request) {
            return str_starts_with(
                (string) $request->url(),
                'https://graph.facebook.com/'.config('whatsapp.graph_version').'/pnid-explicit-check/messages'
            );
        });
    }

    public function test_access_token_is_sent_as_a_bearer_header_not_in_the_url(): void
    {
        [$tenant] = $this->connectTenant([], ['user_access_token' => 'very-secret-token']);
        Http::fake(['*/messages' => Http::response(['messages' => [['id' => 'wamid.token-check']]])]);

        (new WhatsAppSendService)->sendText($tenant, '8801700000000', 'hi');

        Http::assertSent(function ($request) {
            return ! str_contains((string) $request->url(), 'very-secret-token')
                && $request->hasHeader('Authorization', 'Bearer very-secret-token');
        });
    }

    public function test_tenant_isolation_sending_for_tenant_a_never_touches_tenant_bs_number_or_messages(): void
    {
        [$tenantA] = $this->connectTenant();
        [$tenantB, , $phoneB] = $this->connectTenant();
        Http::fake(['*/messages' => Http::response(['messages' => [['id' => 'wamid.isolation-1']]])]);

        (new WhatsAppSendService)->sendText($tenantA, '8801700000000', 'hi from A');

        Http::assertSent(fn ($request) => ! str_contains((string) $request->url(), $phoneB->phone_number_id));

        app()->instance('currentTenant', $tenantB);
        $this->assertSame(0, WhatsAppMessage::count(), "tenant B must not see tenant A's outgoing message");

        app()->instance('currentTenant', $tenantA);
        $this->assertSame(1, WhatsAppMessage::count());
    }

    public function test_only_the_active_phone_number_is_selected_when_a_disconnected_one_also_exists(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $account = WhatsAppBusinessAccount::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'connected_by_user_id' => $user->id,
            'waba_id' => 'waba-multi', 'user_access_token' => 'token',
        ]);
        WhatsAppPhoneNumber::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'whatsapp_business_account_id' => $account->id,
            'phone_number_id' => 'pnid-old-disconnected', 'is_active' => 0, 'status' => 'active',
        ]);
        WhatsAppPhoneNumber::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'whatsapp_business_account_id' => $account->id,
            'phone_number_id' => 'pnid-current-active', 'is_active' => 1, 'status' => 'active',
        ]);
        Http::fake(['*/messages' => Http::response(['messages' => [['id' => 'wamid.pick-active']]])]);

        (new WhatsAppSendService)->sendText($tenant, '8801700000000', 'hi');

        Http::assertSent(fn ($request) => str_contains((string) $request->url(), 'pnid-current-active'));
    }
}
