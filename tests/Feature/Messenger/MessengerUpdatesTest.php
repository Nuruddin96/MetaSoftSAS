<?php

namespace Tests\Feature\Messenger;

use App\Models\MessengerMessage;
use App\Models\Tenant;
use Tests\Concerns\InteractsWithCommerceSchema;
use Tests\TestCase;

/**
 * Covers MessengerInboxController::updates() — the polling endpoint behind
 * the real-time inbox (no WebSockets/queue worker assumed on Hostinger
 * shared hosting, see the implementation plan). Runs under the normal
 * tenant panel middleware stack, so tenant isolation here rides on the
 * same BelongsToTenant global scope as index()/show() — nothing here does
 * manual tenant_id filtering, which is the point being tested.
 */
class MessengerUpdatesTest extends TestCase
{
    use InteractsWithCommerceSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCommerceSchema();
    }

    protected function panelUrl(Tenant $tenant, string $path): string
    {
        return '/shop/'.$tenant->subdomain.'/panel/'.$path;
    }

    public function test_updates_endpoint_only_returns_the_requesting_tenants_conversations(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $userA = $this->makeUser($tenantA->id);

        app()->instance('currentTenant', $tenantA);
        MessengerMessage::create(['sender_psid' => 'psid-a', 'message_text' => 'Hello from A', 'direction' => 'in', 'status' => 'new']);

        app()->instance('currentTenant', $tenantB);
        MessengerMessage::create(['sender_psid' => 'psid-b', 'message_text' => 'Hello from B', 'direction' => 'in', 'status' => 'new']);

        $response = $this->actingAs($userA, 'tenant')->getJson($this->panelUrl($tenantA, 'messenger/updates?after_id=0'));

        $response->assertOk();
        $psids = collect($response->json('conversations'))->pluck('psid');

        $this->assertTrue($psids->contains('psid-a'));
        $this->assertFalse($psids->contains('psid-b'), "tenant A's poll must never see tenant B's Messenger conversations");
    }

    public function test_after_id_only_returns_newer_messages_for_the_requested_conversation(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);

        app()->instance('currentTenant', $tenant);
        $first = MessengerMessage::create(['sender_psid' => 'psid-1', 'message_text' => 'first', 'direction' => 'in', 'status' => 'new']);
        MessengerMessage::create(['sender_psid' => 'psid-1', 'message_text' => 'second', 'direction' => 'in', 'status' => 'new']);

        $response = $this->actingAs($user, 'tenant')
            ->getJson($this->panelUrl($tenant, 'messenger/updates?after_id='.$first->id.'&psid=psid-1'));

        $response->assertOk();
        $messages = $response->json('messages');

        $this->assertCount(1, $messages);
        $this->assertSame('second', $messages[0]['message_text']);
    }
}
