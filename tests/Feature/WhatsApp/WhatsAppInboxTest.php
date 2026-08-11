<?php

namespace Tests\Feature\WhatsApp;

use App\Models\Tenant;
use App\Models\WhatsAppBusinessAccount;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppPhoneNumber;
use Illuminate\Support\Facades\Http;

/**
 * WhatsAppInboxController (Phase 5) — the channel-native thread view, kept
 * separate from the unified list per the "a WhatsApp conversation remains a
 * WhatsApp conversation" rule. Mirrors the shape of Messenger's own inbox
 * tests (MessengerReplyRoutingTest, MessengerUpdatesTest) applied to
 * WhatsApp's own model/service.
 */
class WhatsAppInboxTest extends WhatsAppFeatureTestCase
{
    protected function panelUrl(Tenant $tenant, string $path): string
    {
        return '/shop/'.$tenant->subdomain.'/panel/'.$path;
    }

    protected function connectTenant(Tenant $tenant, $user): WhatsAppPhoneNumber
    {
        $account = WhatsAppBusinessAccount::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'connected_by_user_id' => $user->id,
            'waba_id' => 'waba-'.$tenant->id, 'user_access_token' => 'token-'.$tenant->id,
        ]);

        return WhatsAppPhoneNumber::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'whatsapp_business_account_id' => $account->id,
            'phone_number_id' => 'pnid-'.$tenant->id, 'is_active' => 1, 'status' => 'active',
        ]);
    }

    // --- conversation loading -------------------------------------------------------------------

    public function test_whatsapp_conversation_opens_correctly_with_channel_preserved(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        WhatsAppMessage::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'wa_id' => '8801700000000', 'customer_name' => 'Apo',
            'message_text' => 'হ্যালো', 'direction' => 'in',
        ]);

        $response = $this->actingAs($user, 'tenant')->get($this->panelUrl($tenant, 'whatsapp/8801700000000'));

        $response->assertOk();
        $response->assertSee('Apo');
        $response->assertSee('হ্যালো');
        $response->assertSee('WhatsApp');
    }

    public function test_unknown_conversation_404s(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);

        $response = $this->actingAs($user, 'tenant')->get($this->panelUrl($tenant, 'whatsapp/never-existed'));

        $response->assertNotFound();
    }

    public function test_tenant_a_cannot_open_tenant_bs_whatsapp_conversation(): void
    {
        $tenantA = $this->makeTenant();
        WhatsAppMessage::withoutGlobalScopes()->create([
            'tenant_id' => $tenantA->id, 'wa_id' => '8801700000000', 'message_text' => 'private', 'direction' => 'in',
        ]);

        $tenantB = $this->makeTenant();
        $userB = $this->makeUser($tenantB->id);

        $response = $this->actingAs($userB, 'tenant')->get($this->panelUrl($tenantB, 'whatsapp/8801700000000'));

        $response->assertNotFound();
    }

    // --- sending routes to WhatsAppSendService ----------------------------------------------------

    public function test_reply_sends_via_whatsapp_send_service_and_persists_the_outbound_message(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $this->connectTenant($tenant, $user);
        WhatsAppMessage::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'wa_id' => '8801700000000', 'status' => 'new', 'direction' => 'in',
        ]);
        Http::fake(['*/messages' => Http::response(['messages' => [['id' => 'wamid.reply-1']]])]);

        $response = $this->actingAs($user, 'tenant')
            ->post($this->panelUrl($tenant, 'whatsapp/8801700000000/reply'), ['message' => 'ধন্যবাদ']);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        Http::assertSent(fn ($request) => str_contains((string) $request->url(), 'pnid-'.$tenant->id));

        $sent = WhatsAppMessage::withoutGlobalScopes()->where('wamid', 'wamid.reply-1')->first();
        $this->assertNotNull($sent);
        $this->assertSame('out', $sent->direction);
        $this->assertSame($tenant->id, $sent->tenant_id);

        // The existing inbound "new" row for this conversation must be
        // cleared to "contacted", same transition Messenger's reply() applies.
        $this->assertSame(
            0,
            WhatsAppMessage::withoutGlobalScopes()->where('wa_id', '8801700000000')->where('status', 'new')->count()
        );
    }

    public function test_wrong_channel_send_is_rejected_when_no_whatsapp_number_is_connected(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        // Deliberately NOT calling connectTenant() — no WhatsApp number connected.
        WhatsAppMessage::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'wa_id' => '8801700000000', 'direction' => 'in',
        ]);
        Http::fake();

        $response = $this->actingAs($user, 'tenant')
            ->post($this->panelUrl($tenant, 'whatsapp/8801700000000/reply'), ['message' => 'hi']);

        $response->assertSessionHas('error');
        Http::assertNothingSent();
        $this->assertSame(0, WhatsAppMessage::withoutGlobalScopes()->where('direction', 'out')->count());
    }

    public function test_reply_requires_either_a_message_or_an_image(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $this->connectTenant($tenant, $user);
        WhatsAppMessage::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'wa_id' => '8801700000000', 'direction' => 'in',
        ]);

        $response = $this->actingAs($user, 'tenant')
            ->post($this->panelUrl($tenant, 'whatsapp/8801700000000/reply'), []);

        $response->assertSessionHas('error');
        $this->assertSame(0, WhatsAppMessage::withoutGlobalScopes()->where('direction', 'out')->count());
    }

    public function test_failed_send_shows_error_and_does_not_clear_the_new_badge(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $this->connectTenant($tenant, $user);
        WhatsAppMessage::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'wa_id' => '8801700000000', 'status' => 'new', 'direction' => 'in',
        ]);
        Http::fake(['*/messages' => Http::response(['error' => ['message' => 'Invalid recipient', 'code' => 131030]], 400)]);

        $response = $this->actingAs($user, 'tenant')
            ->post($this->panelUrl($tenant, 'whatsapp/8801700000000/reply'), ['message' => 'hi']);

        $response->assertSessionHas('error');
        $this->assertSame(
            1,
            WhatsAppMessage::withoutGlobalScopes()->where('wa_id', '8801700000000')->where('status', 'new')->count(),
            'a failed send must not clear the unread/new badge — nothing was actually delivered'
        );
    }

    public function test_tenant_a_reply_cannot_use_tenant_bs_phone_number(): void
    {
        $tenantA = $this->makeTenant();
        $userA = $this->makeUser($tenantA->id);
        // Tenant A has no WhatsApp connected.
        WhatsAppMessage::withoutGlobalScopes()->create([
            'tenant_id' => $tenantA->id, 'wa_id' => '8801700000000', 'direction' => 'in',
        ]);

        $tenantB = $this->makeTenant();
        $userB = $this->makeUser($tenantB->id);
        $this->connectTenant($tenantB, $userB); // tenant B DOES have one connected

        Http::fake(['*/messages' => Http::response(['messages' => [['id' => 'wamid.x']]])]);

        $response = $this->actingAs($userA, 'tenant')
            ->post($this->panelUrl($tenantA, 'whatsapp/8801700000000/reply'), ['message' => 'hi']);

        // WhatsAppSendService::resolvePhoneNumber() is explicitly tenant_id-
        // scoped (Phase 3) — tenant A's send must resolve to "no number
        // connected", never fall through to tenant B's, even though tenant
        // B has one active. No Graph call is made at all.
        $response->assertSessionHas('error');
        Http::assertNothingSent();
        $this->assertSame(0, WhatsAppMessage::withoutGlobalScopes()->where('tenant_id', $tenantA->id)->where('direction', 'out')->count());
    }

    // --- status ------------------------------------------------------------------------------------

    public function test_update_status_changes_business_status_without_touching_delivery_status(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $message = WhatsAppMessage::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'wa_id' => '8801700000000', 'status' => 'new',
            'delivery_status' => 'read', 'direction' => 'out',
        ]);

        $response = $this->actingAs($user, 'tenant')
            ->post($this->panelUrl($tenant, 'whatsapp/8801700000000/status'), ['status' => 'converted']);

        $response->assertRedirect();
        $message->refresh();
        $this->assertSame('converted', $message->status);
        $this->assertSame('read', $message->delivery_status, 'business status update must never touch the WhatsApp delivery lifecycle column');
    }

    // --- attachments ---------------------------------------------------------------------------------

    public function test_unsupported_message_type_renders_the_thread_without_crashing(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        WhatsAppMessage::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'wa_id' => '8801700000000', 'message_type' => 'order',
            'message_text' => null, 'attachment_type' => null, 'direction' => 'in',
        ]);

        $response = $this->actingAs($user, 'tenant')->get($this->panelUrl($tenant, 'whatsapp/8801700000000'));

        $response->assertOk();
    }

    /**
     * No raw_payload at all here (unlike a normal webhook-ingested row) —
     * simulates a row with no resolvable Cloud API media id
     * (WhatsAppMessage::inboundMediaId() returns null), which is the one
     * case WhatsAppInboxController::media() can never proxy regardless of
     * Meta's API being reachable. Still must render a safe placeholder, not
     * a broken media tag or a blank bubble.
     */
    public function test_inbound_media_with_no_resolvable_media_id_renders_a_placeholder_not_a_broken_media_tag(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        WhatsAppMessage::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'wa_id' => '8801700000000', 'message_type' => 'image',
            'attachment_type' => 'image', 'attachment_url' => null, 'direction' => 'in',
        ]);

        $response = $this->actingAs($user, 'tenant')->get($this->panelUrl($tenant, 'whatsapp/8801700000000'));

        $response->assertOk();
        $response->assertSee('মিডিয়া লোড করা যায়নি');
    }

    /** The normal case (a real webhook-ingested row, raw_payload carries the media id) renders a live, proxied <img> instead of the placeholder. */
    public function test_inbound_media_with_a_resolvable_media_id_renders_a_proxied_image_tag(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $message = WhatsAppMessage::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'wa_id' => '8801700000000', 'message_type' => 'image',
            'attachment_type' => 'image', 'attachment_url' => null, 'direction' => 'in',
            'raw_payload' => ['image' => ['id' => 'media-xyz', 'mime_type' => 'image/jpeg']],
        ]);

        $response = $this->actingAs($user, 'tenant')->get($this->panelUrl($tenant, 'whatsapp/8801700000000'));

        $response->assertOk();
        $response->assertSee(route('tenant.whatsapp.media', $message->id), false);
    }

    public function test_outbound_image_with_a_url_renders_a_real_image_tag(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        WhatsAppMessage::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'wa_id' => '8801700000000', 'message_type' => 'image',
            'attachment_type' => 'image', 'attachment_url' => 'https://example.test/product.jpg', 'direction' => 'out',
        ]);

        $response = $this->actingAs($user, 'tenant')->get($this->panelUrl($tenant, 'whatsapp/8801700000000'));

        $response->assertOk();
        $response->assertSee('https://example.test/product.jpg', false);
    }

    // --- polling / updates -----------------------------------------------------------------------

    public function test_updates_endpoint_only_returns_the_requesting_tenants_messages(): void
    {
        $tenantA = $this->makeTenant();
        $userA = $this->makeUser($tenantA->id);
        WhatsAppMessage::withoutGlobalScopes()->create(['tenant_id' => $tenantA->id, 'wa_id' => 'wa-a', 'message_text' => 'a']);

        $tenantB = $this->makeTenant();
        WhatsAppMessage::withoutGlobalScopes()->create(['tenant_id' => $tenantB->id, 'wa_id' => 'wa-b', 'message_text' => 'b']);

        $response = $this->actingAs($userA, 'tenant')->get($this->panelUrl($tenantA, 'whatsapp/updates?after_id=0'));

        $response->assertOk();
        $this->assertSame(1, WhatsAppMessage::count(), "tenant A's scoped query must only see its own message");
    }
}
