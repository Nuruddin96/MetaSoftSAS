<?php

namespace Tests\Feature\Api\Mobile;

use App\Models\WhatsAppBusinessAccount;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppPhoneNumber;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithApiSchema;
use Tests\TestCase;

/**
 * Covers Api\Mobile\WhatsAppController — reuses UnifiedInboxService and
 * WhatsAppSendService (the same services the web unified inbox and
 * Tenant\WhatsAppInboxController use), mirrors that controller's
 * show/reply/status/resume-ai logic exactly.
 *
 * Schema is added inline (not via InteractsWithWhatsAppSchema) — that
 * trait redefines makeTenant()/makeUser() with signatures that fatally
 * collide with InteractsWithApiSchema's own when both are `use`d on one
 * class; only the WhatsApp-specific tables it would have added are
 * created here instead, matching its exact column shapes.
 */
class WhatsAppApiTest extends TestCase
{
    use InteractsWithApiSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpApiSchema();

        // WhatsAppPhoneNumber::tablesReady() (and therefore
        // UnifiedInboxService's WhatsApp branch / WhatsAppSendService)
        // requires all four chunk26.sql tables to exist, this one included,
        // even though these tests never touch it directly.
        if (! Schema::hasTable('whatsapp_oauth_states')) {
            Schema::create('whatsapp_oauth_states', function (Blueprint $table) {
                $table->id();
                $table->string('state', 64)->unique();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('user_id');
                $table->timestamp('expires_at');
                $table->timestamp('used_at')->nullable();
                $table->timestamp('created_at')->nullable();
            });
        }

        if (! Schema::hasTable('whatsapp_business_accounts')) {
            Schema::create('whatsapp_business_accounts', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->unique();
                $table->unsignedBigInteger('connected_by_user_id');
                $table->string('waba_id', 64)->unique();
                $table->text('user_access_token');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('whatsapp_phone_numbers')) {
            Schema::create('whatsapp_phone_numbers', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('whatsapp_business_account_id');
                $table->string('phone_number_id', 64)->unique();
                $table->string('status', 30)->default('active');
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('whatsapp_messages')) {
            Schema::create('whatsapp_messages', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('whatsapp_phone_number_id')->nullable();
                $table->string('wa_id', 30);
                $table->string('wamid', 100)->nullable()->unique();
                $table->string('customer_name', 150)->nullable();
                $table->string('message_type', 20)->nullable();
                $table->text('message_text')->nullable();
                $table->string('attachment_url', 500)->nullable();
                $table->string('attachment_type', 20)->nullable();
                $table->string('attachment_name', 255)->nullable();
                $table->json('raw_payload')->nullable();
                $table->string('direction', 10)->default('in');
                $table->string('sent_by', 10)->default('human');
                $table->string('status', 20)->default('new');
                $table->string('delivery_status', 20)->nullable();
                $table->timestamp('created_at')->nullable();
            });
        }

        if (! Schema::hasTable('ai_handoffs')) {
            Schema::create('ai_handoffs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->string('channel', 20);
                $table->string('external_id', 100);
                $table->string('reason', 50)->nullable();
                $table->timestamp('resolved_at')->nullable();
                $table->unsignedBigInteger('resolved_by_user_id')->nullable();
                $table->timestamps();
            });
        }
    }

    protected function connectTenant(): array
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);

        $account = WhatsAppBusinessAccount::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'connected_by_user_id' => $user->id,
            'waba_id' => 'waba-'.$tenant->id,
            'user_access_token' => 'secret-token-'.$tenant->id,
        ]);

        $phone = WhatsAppPhoneNumber::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'whatsapp_business_account_id' => $account->id,
            'phone_number_id' => 'pnid-'.$tenant->id,
            'is_active' => 1,
            'status' => 'active',
        ]);

        return [$tenant, $user, $phone];
    }

    /** Mirrors WhatsAppMediaProxyTest::makeInboundImage(), generalized to any media type. */
    protected function makeInboundMedia(
        int $tenantId,
        WhatsAppPhoneNumber $phone,
        string $waId,
        string $mediaId,
        string $type = 'image',
    ): WhatsAppMessage {
        app()->instance('currentTenant', \App\Models\Tenant::find($tenantId));
        $message = WhatsAppMessage::create([
            'tenant_id' => $tenantId,
            'whatsapp_phone_number_id' => $phone->id,
            'wa_id' => $waId,
            'wamid' => 'wamid-'.$mediaId,
            'message_type' => $type,
            'attachment_type' => $type,
            'raw_payload' => [$type => ['id' => $mediaId, 'mime_type' => $type === 'audio' ? 'audio/ogg' : 'image/jpeg']],
            'direction' => 'in',
            'status' => 'new',
        ]);
        app()->forgetInstance('currentTenant');

        return $message;
    }

    protected function makeConversation(int $tenantId, string $waId, array $attrs = []): WhatsAppMessage
    {
        app()->instance('currentTenant', \App\Models\Tenant::find($tenantId));
        $message = WhatsAppMessage::create(array_merge([
            'tenant_id' => $tenantId,
            'wa_id' => $waId,
            'customer_name' => 'Karim',
            'message_text' => 'হ্যালো',
            'direction' => 'in',
            'status' => 'new',
        ], $attrs));
        app()->forgetInstance('currentTenant');

        return $message;
    }

    public function test_index_lists_conversations_via_the_real_unified_inbox_service(): void
    {
        [$tenant, $user] = $this->connectTenant();
        $this->makeConversation($tenant->id, '8801700000001', ['customer_name' => 'Rahim']);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/mobile/v1/whatsapp/conversations')->assertOk();
        $response->assertJsonStructure(['data' => [['wa_id', 'customer_name', 'status', 'unread_count']], 'has_more']);
        $this->assertSame('8801700000001', $response->json('data.0.wa_id'));
    }

    public function test_show_returns_the_full_thread_and_matches_customer_by_phone_variants(): void
    {
        [$tenant, $user] = $this->connectTenant();
        $this->makeConversation($tenant->id, '8801700000001', ['message_text' => 'First message']);
        app()->instance('currentTenant', $tenant);
        \App\Models\Customer::create(['tenant_id' => $tenant->id, 'name' => 'Rahim', 'phone' => '01700000001']);
        app()->forgetInstance('currentTenant');

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/mobile/v1/whatsapp/8801700000001')->assertOk();
        $response->assertJsonStructure(['wa_id', 'customer_name', 'handoff_active', 'matched_customer', 'messages']);
        $this->assertSame('Rahim', $response->json('matched_customer.name'));
    }

    public function test_show_404s_for_an_unknown_wa_id(): void
    {
        [, $user] = $this->connectTenant();

        Sanctum::actingAs($user);

        $this->getJson('/api/mobile/v1/whatsapp/never-existed')->assertNotFound();
    }

    public function test_reply_sends_via_the_real_send_service_and_persists_the_outbound_message(): void
    {
        [$tenant, $user] = $this->connectTenant();
        $this->makeConversation($tenant->id, '8801700000001');

        Http::fake(['*/messages' => Http::response(['messages' => [['id' => 'wamid.sent-1']]])]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/mobile/v1/whatsapp/8801700000001/reply', ['message' => 'ধন্যবাদ']);

        $response->assertCreated()->assertJsonPath('text', 'ধন্যবাদ')->assertJsonPath('direction', 'out');
        $this->assertDatabaseHas('whatsapp_messages', ['wa_id' => '8801700000001', 'direction' => 'out', 'message_text' => 'ধন্যবাদ']);
    }

    public function test_reply_rejects_when_no_number_is_connected(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $this->makeConversation($tenant->id, '8801700000001');

        Sanctum::actingAs($user);

        $this->postJson('/api/mobile/v1/whatsapp/8801700000001/reply', ['message' => 'Hello'])->assertStatus(422);
    }

    public function test_reply_rejects_an_empty_message(): void
    {
        [$tenant, $user] = $this->connectTenant();
        $this->makeConversation($tenant->id, '8801700000001');

        Sanctum::actingAs($user);

        $this->postJson('/api/mobile/v1/whatsapp/8801700000001/reply', [])->assertStatus(422)->assertJsonValidationErrors('message');
    }

    public function test_update_status_changes_every_message_row_for_that_wa_id(): void
    {
        [$tenant, $user] = $this->connectTenant();
        $this->makeConversation($tenant->id, '8801700000001');

        Sanctum::actingAs($user);

        $this->patchJson('/api/mobile/v1/whatsapp/8801700000001/status', ['status' => 'converted'])
            ->assertOk()->assertJsonPath('status', 'converted');
        $this->assertDatabaseHas('whatsapp_messages', ['wa_id' => '8801700000001', 'status' => 'converted']);
    }

    public function test_resume_ai_resolves_a_real_active_handoff(): void
    {
        [$tenant, $user] = $this->connectTenant();
        $this->makeConversation($tenant->id, '8801700000001');
        \App\Models\AiHandoff::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'channel' => 'whatsapp', 'external_id' => '8801700000001',
            'reason' => 'customer_requested',
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/mobile/v1/whatsapp/8801700000001/resume-ai')->assertOk()->assertJsonPath('ok', true);

        $this->assertDatabaseHas('ai_handoffs', ['tenant_id' => $tenant->id, 'external_id' => '8801700000001']);
        $this->assertNotNull(\App\Models\AiHandoff::withoutGlobalScopes()->first()->resolved_at);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/mobile/v1/whatsapp/conversations')->assertUnauthorized();
    }

    // --- WhatsAppController::media() — mirrors WhatsAppMediaProxyTest's
    // cases (Tenant\WhatsAppInboxController::media()) adapted for Sanctum. ---

    public function test_show_exposes_a_masked_inbound_media_url_only_when_resolvable(): void
    {
        [$tenant, $user, $phone] = $this->connectTenant();
        $message = $this->makeInboundMedia($tenant->id, $phone, '8801700000001', 'media-1');

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/mobile/v1/whatsapp/8801700000001')->assertOk();
        $this->assertStringContainsString(
            '/api/mobile/v1/whatsapp/media/'.$message->id,
            $response->json('messages.0.inbound_media_url'),
        );
        // The proxy URL never embeds the Meta media id or any token.
        $this->assertStringNotContainsString('media-1', $response->json('messages.0.inbound_media_url'));
    }

    public function test_media_streams_an_inbound_image_through_the_two_step_graph_proxy(): void
    {
        [$tenant, $user, $phone] = $this->connectTenant();
        $message = $this->makeInboundMedia($tenant->id, $phone, '8801700000001', 'media-1');

        Http::fake([
            'https://graph.facebook.com/*/media-1' => Http::response(['url' => 'https://lookaside.fbsbx.com/whatsapp_business/media-1', 'mime_type' => 'image/jpeg']),
            'https://lookaside.fbsbx.com/*' => Http::response('binary-image-bytes'),
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/mobile/v1/whatsapp/media/'.$message->id);

        $response->assertOk();
        $response->assertHeader('Content-Type', 'image/jpeg');
        $this->assertSame('binary-image-bytes', $response->getContent());
    }

    public function test_media_streams_an_inbound_audio_note(): void
    {
        [$tenant, $user, $phone] = $this->connectTenant();
        $message = $this->makeInboundMedia($tenant->id, $phone, '8801700000001', 'media-audio', 'audio');

        Http::fake([
            'https://graph.facebook.com/*/media-audio' => Http::response(['url' => 'https://lookaside.fbsbx.com/media-audio', 'mime_type' => 'audio/ogg']),
            'https://lookaside.fbsbx.com/*' => Http::response('binary-audio-bytes'),
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/mobile/v1/whatsapp/media/'.$message->id);

        $response->assertOk();
        $response->assertHeader('Content-Type', 'audio/ogg');
        $this->assertSame('binary-audio-bytes', $response->getContent());
    }

    public function test_media_is_rejected_without_authentication(): void
    {
        [$tenant, , $phone] = $this->connectTenant();
        $message = $this->makeInboundMedia($tenant->id, $phone, '8801700000001', 'media-1');

        $this->getJson('/api/mobile/v1/whatsapp/media/'.$message->id)->assertUnauthorized();
    }

    public function test_tenant_a_cannot_fetch_tenant_bs_media(): void
    {
        [, $userA] = $this->connectTenant();
        [$tenantB, , $phoneB] = $this->connectTenant();
        $messageB = $this->makeInboundMedia($tenantB->id, $phoneB, '8801700000002', 'media-b');

        Http::fake(['https://graph.facebook.com/*' => Http::response(['url' => 'https://lookaside.fbsbx.com/x', 'mime_type' => 'image/jpeg'])]);

        Sanctum::actingAs($userA);

        $this->getJson('/api/mobile/v1/whatsapp/media/'.$messageB->id)->assertNotFound();
    }

    public function test_outbound_message_has_no_proxyable_media_and_404s(): void
    {
        [$tenant, $user, $phone] = $this->connectTenant();
        app()->instance('currentTenant', $tenant);
        $message = WhatsAppMessage::create([
            'tenant_id' => $tenant->id,
            'whatsapp_phone_number_id' => $phone->id,
            'wa_id' => '8801700000001',
            'message_type' => 'image',
            'attachment_type' => 'image',
            'attachment_url' => 'https://example.com/already-hosted.jpg',
            'direction' => 'out',
            'status' => 'contacted',
        ]);
        app()->forgetInstance('currentTenant');

        Sanctum::actingAs($user);

        $this->getJson('/api/mobile/v1/whatsapp/media/'.$message->id)->assertNotFound();
    }

    public function test_message_with_no_resolvable_media_id_404s(): void
    {
        [$tenant, $user, $phone] = $this->connectTenant();
        app()->instance('currentTenant', $tenant);
        $message = WhatsAppMessage::create([
            'tenant_id' => $tenant->id,
            'whatsapp_phone_number_id' => $phone->id,
            'wa_id' => '8801700000001',
            'message_type' => 'location',
            'attachment_type' => null,
            'raw_payload' => ['location' => ['latitude' => 1, 'longitude' => 2]],
            'direction' => 'in',
            'status' => 'new',
        ]);
        app()->forgetInstance('currentTenant');

        Sanctum::actingAs($user);

        $this->getJson('/api/mobile/v1/whatsapp/media/'.$message->id)->assertNotFound();
    }

    public function test_graph_lookup_failure_returns_404_not_a_crash(): void
    {
        [$tenant, $user, $phone] = $this->connectTenant();
        $message = $this->makeInboundMedia($tenant->id, $phone, '8801700000001', 'media-expired');

        Http::fake([
            'https://graph.facebook.com/*/media-expired' => Http::response(['error' => ['code' => 190, 'message' => 'expired']], 401),
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/mobile/v1/whatsapp/media/'.$message->id)->assertNotFound();
    }

    public function test_a_nonnumeric_media_id_never_reaches_the_controller(): void
    {
        [, $user] = $this->connectTenant();

        Sanctum::actingAs($user);

        // The route's ->whereNumber('id') constraint means this is a 404
        // from routing itself (no match), not from the controller — proof
        // this endpoint can't be handed an arbitrary non-numeric target.
        $this->getJson('/api/mobile/v1/whatsapp/media/not-a-number')->assertNotFound();
    }
}
