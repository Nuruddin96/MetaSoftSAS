<?php

namespace Tests\Feature\WhatsApp;

use App\Models\Tenant;
use App\Models\WhatsAppBusinessAccount;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppPhoneNumber;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * WhatsAppInboxController::media() -> WhatsAppMediaService — the on-demand
 * proxy that fills the gap WhatsAppWebhookController::handleIncomingMessage()
 * deliberately left open ("No media download/re-hosting in this phase").
 * Covers the two-step Graph API shape, tenant isolation (the actual security
 * property — a message id is a numeric PK, so this is the one WhatsApp route
 * where IDOR would be a real risk without BelongsToTenant's scope applying),
 * and every "can't produce a real file" path failing closed to 404 rather
 * than leaking a stack trace or an empty-but-200 response.
 */
class WhatsAppMediaProxyTest extends WhatsAppFeatureTestCase
{
    protected function panelUrl(Tenant $tenant, string $path): string
    {
        return '/shop/'.$tenant->subdomain.'/panel/'.$path;
    }

    protected function makeConnectedTenant(): array
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $account = WhatsAppBusinessAccount::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'connected_by_user_id' => $user->id,
            'waba_id' => 'waba-media-'.$tenant->id, 'user_access_token' => 'tok-'.$tenant->id,
        ]);
        $phone = WhatsAppPhoneNumber::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'whatsapp_business_account_id' => $account->id,
            'phone_number_id' => 'phone-media-'.$tenant->id, 'is_active' => true, 'status' => 'active',
        ]);

        return [$tenant, $user, $phone];
    }

    protected function makeInboundImage(Tenant $tenant, WhatsAppPhoneNumber $phone, string $mediaId = 'media-1'): WhatsAppMessage
    {
        return WhatsAppMessage::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'whatsapp_phone_number_id' => $phone->id,
            'wa_id' => '8801700000000', 'wamid' => 'wamid-'.$mediaId,
            'message_type' => 'image', 'attachment_type' => 'image',
            'raw_payload' => ['image' => ['id' => $mediaId, 'mime_type' => 'image/jpeg']],
            'direction' => 'in', 'status' => 'new',
        ]);
    }

    public function test_inbound_image_streams_through_the_two_step_graph_proxy(): void
    {
        [$tenant, $user, $phone] = $this->makeConnectedTenant();
        $message = $this->makeInboundImage($tenant, $phone);

        Http::fake([
            'https://graph.facebook.com/*/media-1' => Http::response(['url' => 'https://lookaside.fbsbx.com/whatsapp_business/media-1', 'mime_type' => 'image/jpeg']),
            'https://lookaside.fbsbx.com/*' => Http::response('binary-image-bytes'),
        ]);

        $response = $this->actingAs($user, 'tenant')
            ->get($this->panelUrl($tenant, 'whatsapp/media/'.$message->id));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'image/jpeg');
        $this->assertSame('binary-image-bytes', $response->getContent());
    }

    public function test_tenant_a_cannot_fetch_tenant_bs_media(): void
    {
        [$tenantA, $userA] = $this->makeConnectedTenant();
        [$tenantB, , $phoneB] = $this->makeConnectedTenant();
        $messageB = $this->makeInboundImage($tenantB, $phoneB);

        Http::fake(['https://graph.facebook.com/*' => Http::response(['url' => 'https://lookaside.fbsbx.com/x', 'mime_type' => 'image/jpeg'])]);

        $response = $this->actingAs($userA, 'tenant')
            ->get($this->panelUrl($tenantA, 'whatsapp/media/'.$messageB->id));

        $response->assertNotFound();
    }

    public function test_outbound_message_has_no_proxyable_media_and_404s(): void
    {
        [$tenant, $user, $phone] = $this->makeConnectedTenant();
        $message = WhatsAppMessage::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'whatsapp_phone_number_id' => $phone->id,
            'wa_id' => '8801700000000', 'message_type' => 'image', 'attachment_type' => 'image',
            'attachment_url' => 'https://example.com/already-hosted.jpg',
            'direction' => 'out', 'status' => 'contacted',
        ]);

        $response = $this->actingAs($user, 'tenant')
            ->get($this->panelUrl($tenant, 'whatsapp/media/'.$message->id));

        $response->assertNotFound();
    }

    public function test_graph_lookup_failure_returns_404_not_a_crash(): void
    {
        [$tenant, $user, $phone] = $this->makeConnectedTenant();
        $message = $this->makeInboundImage($tenant, $phone, 'media-expired');

        Http::fake([
            'https://graph.facebook.com/*/media-expired' => Http::response(['error' => ['code' => 190, 'message' => 'expired']], 401),
        ]);

        $response = $this->actingAs($user, 'tenant')
            ->get($this->panelUrl($tenant, 'whatsapp/media/'.$message->id));

        $response->assertNotFound();
    }

    public function test_connection_exception_during_media_fetch_fails_gracefully(): void
    {
        [$tenant, $user, $phone] = $this->makeConnectedTenant();
        $message = $this->makeInboundImage($tenant, $phone, 'media-timeout');

        Http::fake(function () {
            throw new ConnectionException('timed out');
        });

        $response = $this->actingAs($user, 'tenant')
            ->get($this->panelUrl($tenant, 'whatsapp/media/'.$message->id));

        $response->assertNotFound();
    }

    public function test_message_with_no_resolvable_media_id_404s(): void
    {
        [$tenant, $user, $phone] = $this->makeConnectedTenant();
        $message = WhatsAppMessage::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'whatsapp_phone_number_id' => $phone->id,
            'wa_id' => '8801700000000', 'message_type' => 'location', 'attachment_type' => null,
            'raw_payload' => ['location' => ['latitude' => 1, 'longitude' => 2]],
            'direction' => 'in', 'status' => 'new',
        ]);

        $response = $this->actingAs($user, 'tenant')
            ->get($this->panelUrl($tenant, 'whatsapp/media/'.$message->id));

        $response->assertNotFound();
    }
}
