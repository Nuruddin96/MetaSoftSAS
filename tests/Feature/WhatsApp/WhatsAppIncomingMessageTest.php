<?php

namespace Tests\Feature\WhatsApp;

use App\Models\WhatsAppBusinessAccount;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppPhoneNumber;
use App\Models\Tenant;

class WhatsAppIncomingMessageTest extends WhatsAppFeatureTestCase
{
    protected Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        config(['whatsapp.app_secret' => 'test-secret']);

        $this->tenant = $this->makeTenant();
        $user = $this->makeUser($this->tenant->id);
        $account = WhatsAppBusinessAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'connected_by_user_id' => $user->id,
            'waba_id' => 'waba-1', 'user_access_token' => 'token',
        ]);
        WhatsAppPhoneNumber::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'whatsapp_business_account_id' => $account->id,
            'phone_number_id' => 'pnid-1', 'is_active' => 1,
        ]);
    }

    protected function postMessage(array $message, string $waId = '8801700000000'): \Illuminate\Testing\TestResponse
    {
        $payload = [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => 'waba-1',
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => ['display_phone_number' => '+8801700000000', 'phone_number_id' => 'pnid-1'],
                        'contacts' => [['profile' => ['name' => 'Apo'], 'wa_id' => $waId]],
                        'messages' => [$message],
                    ],
                ]],
            ]],
        ];

        $body = json_encode($payload);
        $signature = 'sha256='.hash_hmac('sha256', $body, 'test-secret');

        return $this->call('POST', '/webhook/whatsapp', [], [], [], $this->transformHeadersToServerVars([
            'X-Hub-Signature-256' => $signature,
            'CONTENT_TYPE' => 'application/json',
        ]), $body);
    }

    public function test_text_message_is_persisted(): void
    {
        $this->postMessage([
            'from' => '8801700000000', 'id' => 'wamid.text-1', 'timestamp' => (string) time(),
            'type' => 'text', 'text' => ['body' => 'দাম কত?'],
        ])->assertOk();

        $m = WhatsAppMessage::withoutGlobalScopes()->where('wamid', 'wamid.text-1')->first();
        $this->assertNotNull($m);
        $this->assertSame($this->tenant->id, $m->tenant_id);
        $this->assertSame('8801700000000', $m->wa_id);
        $this->assertSame('Apo', $m->customer_name);
        $this->assertSame('text', $m->message_type);
        $this->assertSame('দাম কত?', $m->message_text);
        $this->assertSame('in', $m->direction);
        $this->assertSame('new', $m->status);
        $this->assertNull($m->delivery_status);
        $this->assertIsArray($m->raw_payload);
    }

    public function test_image_message_persists_media_id_via_raw_payload_and_caption_as_text(): void
    {
        $this->postMessage([
            'from' => '8801700000000', 'id' => 'wamid.image-1', 'timestamp' => (string) time(),
            'type' => 'image',
            'image' => ['id' => 'media-id-123', 'mime_type' => 'image/jpeg', 'sha256' => 'abc', 'caption' => 'এই রকম আছে?'],
        ])->assertOk();

        $m = WhatsAppMessage::withoutGlobalScopes()->where('wamid', 'wamid.image-1')->first();
        $this->assertSame('image', $m->message_type);
        $this->assertSame('image', $m->attachment_type);
        $this->assertSame('এই রকম আছে?', $m->message_text);
        $this->assertNull($m->attachment_url, 'media is not downloaded/re-hosted in this phase');
        $this->assertSame('media-id-123', $m->raw_payload['image']['id']);
        $this->assertSame('image/jpeg', $m->raw_payload['image']['mime_type']);
    }

    public function test_video_message_persists_media_id_via_raw_payload(): void
    {
        $this->postMessage([
            'from' => '8801700000000', 'id' => 'wamid.video-1', 'timestamp' => (string) time(),
            'type' => 'video',
            'video' => ['id' => 'media-id-video', 'mime_type' => 'video/mp4'],
        ])->assertOk();

        $m = WhatsAppMessage::withoutGlobalScopes()->where('wamid', 'wamid.video-1')->first();
        $this->assertSame('video', $m->message_type);
        $this->assertSame('video', $m->attachment_type);
        $this->assertSame('media-id-video', $m->raw_payload['video']['id']);
    }

    public function test_audio_message_persists_media_id_via_raw_payload(): void
    {
        $this->postMessage([
            'from' => '8801700000000', 'id' => 'wamid.audio-1', 'timestamp' => (string) time(),
            'type' => 'audio',
            'audio' => ['id' => 'media-id-audio', 'mime_type' => 'audio/ogg'],
        ])->assertOk();

        $m = WhatsAppMessage::withoutGlobalScopes()->where('wamid', 'wamid.audio-1')->first();
        $this->assertSame('audio', $m->message_type);
        $this->assertSame('audio', $m->attachment_type);
        $this->assertSame('media-id-audio', $m->raw_payload['audio']['id']);
    }

    public function test_document_message_persists_filename_and_media_id(): void
    {
        $this->postMessage([
            'from' => '8801700000000', 'id' => 'wamid.doc-1', 'timestamp' => (string) time(),
            'type' => 'document',
            'document' => ['id' => 'media-id-doc', 'mime_type' => 'application/pdf', 'filename' => 'invoice.pdf', 'caption' => 'এটা দেখুন'],
        ])->assertOk();

        $m = WhatsAppMessage::withoutGlobalScopes()->where('wamid', 'wamid.doc-1')->first();
        $this->assertSame('document', $m->message_type);
        $this->assertSame('document', $m->attachment_type);
        $this->assertSame('invoice.pdf', $m->attachment_name);
        $this->assertSame('এটা দেখুন', $m->message_text);
        $this->assertSame('media-id-doc', $m->raw_payload['document']['id']);
    }

    public function test_location_message_persists_raw_payload(): void
    {
        $this->postMessage([
            'from' => '8801700000000', 'id' => 'wamid.loc-1', 'timestamp' => (string) time(),
            'type' => 'location',
            'location' => ['latitude' => 23.7, 'longitude' => 90.4, 'name' => 'Shop'],
        ])->assertOk();

        $m = WhatsAppMessage::withoutGlobalScopes()->where('wamid', 'wamid.loc-1')->first();
        $this->assertSame('location', $m->message_type);
        $this->assertSame(23.7, $m->raw_payload['location']['latitude']);
        $this->assertSame(90.4, $m->raw_payload['location']['longitude']);
    }

    public function test_contacts_message_extracts_name_and_persists_raw_payload(): void
    {
        $this->postMessage([
            'from' => '8801700000000', 'id' => 'wamid.contacts-1', 'timestamp' => (string) time(),
            'type' => 'contacts',
            'contacts' => [['name' => ['formatted_name' => 'Karim Uddin'], 'phones' => [['phone' => '+8801800000000']]]],
        ])->assertOk();

        $m = WhatsAppMessage::withoutGlobalScopes()->where('wamid', 'wamid.contacts-1')->first();
        $this->assertSame('contacts', $m->message_type);
        $this->assertSame('Karim Uddin', $m->message_text);
        $this->assertSame('Karim Uddin', $m->raw_payload['contacts'][0]['name']['formatted_name']);
    }

    public function test_interactive_button_reply_extracts_title(): void
    {
        $this->postMessage([
            'from' => '8801700000000', 'id' => 'wamid.interactive-1', 'timestamp' => (string) time(),
            'type' => 'interactive',
            'interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => 'yes', 'title' => 'হ্যাঁ']],
        ])->assertOk();

        $m = WhatsAppMessage::withoutGlobalScopes()->where('wamid', 'wamid.interactive-1')->first();
        $this->assertSame('interactive', $m->message_type);
        $this->assertSame('হ্যাঁ', $m->message_text);
    }

    public function test_button_quick_reply_extracts_text(): void
    {
        $this->postMessage([
            'from' => '8801700000000', 'id' => 'wamid.button-1', 'timestamp' => (string) time(),
            'type' => 'button',
            'button' => ['text' => 'Confirm Order', 'payload' => 'CONFIRM'],
        ])->assertOk();

        $m = WhatsAppMessage::withoutGlobalScopes()->where('wamid', 'wamid.button-1')->first();
        $this->assertSame('button', $m->message_type);
        $this->assertSame('Confirm Order', $m->message_text);
    }

    public function test_unsupported_message_type_does_not_crash_and_still_records_raw_payload(): void
    {
        $this->postMessage([
            'from' => '8801700000000', 'id' => 'wamid.unsupported-1', 'timestamp' => (string) time(),
            'type' => 'unsupported',
            'errors' => [['code' => 131051, 'title' => 'Unsupported message type']],
        ])->assertOk();

        $m = WhatsAppMessage::withoutGlobalScopes()->where('wamid', 'wamid.unsupported-1')->first();
        $this->assertNotNull($m);
        $this->assertSame('unsupported', $m->message_type);
        $this->assertNull($m->message_text);
        $this->assertIsArray($m->raw_payload);
    }

    public function test_message_with_no_from_field_is_skipped_without_error(): void
    {
        $this->postMessage([
            'id' => 'wamid.malformed-1', 'timestamp' => (string) time(),
            'type' => 'text', 'text' => ['body' => 'no from field'],
        ])->assertOk();

        $this->assertSame(0, WhatsAppMessage::withoutGlobalScopes()->count());
    }

    public function test_missing_contact_profile_leaves_customer_name_null_without_error(): void
    {
        $payload = [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => 'waba-1',
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => ['phone_number_id' => 'pnid-1'],
                        // no "contacts" key at all
                        'messages' => [[
                            'from' => '8801700000000', 'id' => 'wamid.noname-1', 'timestamp' => (string) time(),
                            'type' => 'text', 'text' => ['body' => 'hi'],
                        ]],
                    ],
                ]],
            ]],
        ];
        $body = json_encode($payload);
        $signature = 'sha256='.hash_hmac('sha256', $body, 'test-secret');

        $this->call('POST', '/webhook/whatsapp', [], [], [], $this->transformHeadersToServerVars([
            'X-Hub-Signature-256' => $signature,
            'CONTENT_TYPE' => 'application/json',
        ]), $body)->assertOk();

        $m = WhatsAppMessage::withoutGlobalScopes()->where('wamid', 'wamid.noname-1')->first();
        $this->assertNotNull($m);
        $this->assertNull($m->customer_name);
    }
}
