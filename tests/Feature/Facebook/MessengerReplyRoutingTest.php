<?php

namespace Tests\Feature\Facebook;

use App\Http\Controllers\Tenant\MessengerInboxController;
use App\Models\FacebookConnection;
use App\Models\FacebookPage;
use App\Models\MessengerMessage;
use App\Models\MessengerSetting;
use App\Models\Tenant;
use App\Services\AI\AiConversationStyleService;
use Illuminate\Support\Facades\Http;

/**
 * MessengerInboxController::reply() must resolve the exact Page a
 * conversation belongs to (via messenger_messages.facebook_page_id), never
 * an arbitrary "first active" connected Page — see the production-readiness
 * review finding this fixes.
 */
class MessengerReplyRoutingTest extends FacebookFeatureTestCase
{
    protected function panelUrl(Tenant $tenant, string $path): string
    {
        return '/shop/'.$tenant->subdomain.'/panel/'.$path;
    }

    protected function connectPage(Tenant $tenant, int $connectionId, string $pageId, ?string $token, string $status = 'active', bool $isActive = true): FacebookPage
    {
        return FacebookPage::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'facebook_connection_id' => $connectionId,
            'page_id' => $pageId,
            'page_name' => $pageId.' name',
            'page_access_token' => $token,
            'status' => $status,
            'is_active' => $isActive,
        ]);
    }

    protected function incomingMessage(Tenant $tenant, string $psid, string $mid, ?int $facebookPageId): void
    {
        app()->instance('currentTenant', $tenant);

        MessengerMessage::create([
            'sender_psid' => $psid,
            'facebook_page_id' => $facebookPageId,
            'mid' => $mid,
            'message_text' => 'hello',
            'direction' => 'in',
            'status' => 'new',
        ]);
    }

    public function test_reply_uses_the_exact_page_the_conversation_belongs_to(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $connection = FacebookConnection::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'connected_by_user_id' => $user->id,
            'facebook_user_id' => 'fbu', 'user_access_token' => 'user-token',
        ]);

        $pageA = $this->connectPage($tenant, $connection->id, 'page-a', 'token-for-page-a');
        $pageB = $this->connectPage($tenant, $connection->id, 'page-b', 'token-for-page-b');

        // Conversation with psid-b came in via Page B.
        $this->incomingMessage($tenant, 'psid-b', 'mid-b-1', $pageB->id);

        Http::fake([
            '*/me/messages*' => Http::response(['message_id' => 'm1']),
        ]);

        $this->actingAs($user, 'tenant')
            ->post($this->panelUrl($tenant, 'messenger/psid-b/reply'), ['message' => 'hi there'])
            ->assertRedirect();

        Http::assertSent(fn ($request) => $this->requestUsesToken($request, 'token-for-page-b'));
        Http::assertNotSent(fn ($request) => $this->requestUsesToken($request, 'token-for-page-a'));
    }

    protected function requestUsesToken($request, string $token): bool
    {
        $url = (string) $request->url();
        $body = (string) $request->body();

        return str_contains($url, $token) || str_contains($body, $token);
    }

    public function test_tenant_a_cannot_use_a_page_belonging_to_tenant_b(): void
    {
        $tenantA = $this->makeTenant();
        $userA = $this->makeUser($tenantA->id);
        $connA = FacebookConnection::withoutGlobalScopes()->create([
            'tenant_id' => $tenantA->id, 'connected_by_user_id' => $userA->id,
            'facebook_user_id' => 'fbu-a', 'user_access_token' => 'token-a',
        ]);
        $pageA = $this->connectPage($tenantA, $connA->id, 'page-a-only', 'token-page-a-only');

        $tenantB = $this->makeTenant();
        $userB = $this->makeUser($tenantB->id);
        $connB = FacebookConnection::withoutGlobalScopes()->create([
            'tenant_id' => $tenantB->id, 'connected_by_user_id' => $userB->id,
            'facebook_user_id' => 'fbu-b', 'user_access_token' => 'token-b',
        ]);
        $pageC = $this->connectPage($tenantB, $connB->id, 'page-c', 'token-page-c');

        // Sanity: Tenant B has its own conversation on its own Page.
        $this->incomingMessage($tenantB, 'psid-shared', 'mid-b-shared', $pageC->id);

        // Attempt: Tenant A's staff tries to reply to a psid that (in this
        // adversarial scenario) somehow only has a facebook_page_id row
        // pointing at Tenant B's Page. Even so, the tenant-scoped lookup in
        // resolveReplyToken() must never resolve Tenant B's token for a
        // request made under Tenant A's session.
        Http::fake(['*/me/messages*' => Http::response(['message_id' => 'm1'])]);

        $response = $this->actingAs($userA, 'tenant')
            ->post($this->panelUrl($tenantA, 'messenger/psid-shared/reply'), ['message' => 'hi']);

        $response->assertRedirect();
        Http::assertNotSent(fn ($request) => $this->requestUsesToken($request, 'token-page-c'));
        // Tenant A also has no conversation for this psid, and Tenant A's own
        // Page A token must not be substituted either — the request should
        // fail safely with no Send API call made at all.
        Http::assertNothingSent();
    }

    public function test_disconnected_page_fails_safely_without_using_another_page(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $connection = FacebookConnection::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'connected_by_user_id' => $user->id,
            'facebook_user_id' => 'fbu', 'user_access_token' => 'user-token',
        ]);

        $activePage = $this->connectPage($tenant, $connection->id, 'still-active-page', 'active-token');
        $goneePage = $this->connectPage($tenant, $connection->id, 'now-disconnected-page', null, 'active', false);

        $this->incomingMessage($tenant, 'psid-gone', 'mid-gone-1', $goneePage->id);

        Http::fake(['*/me/messages*' => Http::response(['message_id' => 'm1'])]);

        $response = $this->actingAs($user, 'tenant')
            ->post($this->panelUrl($tenant, 'messenger/psid-gone/reply'), ['message' => 'hi']);

        $response->assertRedirect();
        $response->assertSessionHas('error');
        Http::assertNothingSent();

        $this->assertSame(
            0,
            MessengerMessage::withoutGlobalScopes()->where('sender_psid', 'psid-gone')->where('direction', 'out')->count(),
            'no outgoing message should be recorded when the send was refused'
        );
    }

    public function test_conversation_with_no_facebook_page_id_falls_back_to_legacy_messenger_settings(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);

        MessengerSetting::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'page_id' => 'legacy-only-page',
            'page_access_token' => 'legacy-token',
            'is_active' => 1,
        ]);

        // No facebook_page_id at all for this psid (pre-dates the column, or
        // came in via the legacy messenger_settings webhook path).
        $this->incomingMessage($tenant, 'psid-legacy', 'mid-legacy-1', null);

        Http::fake(['*/me/messages*' => Http::response(['message_id' => 'm1'])]);

        $this->actingAs($user, 'tenant')
            ->post($this->panelUrl($tenant, 'messenger/psid-legacy/reply'), ['message' => 'hi'])
            ->assertRedirect();

        Http::assertSent(fn ($request) => $this->requestUsesToken($request, 'legacy-token'));
    }

    public function test_no_connection_at_all_shows_generic_not_connected_error(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);

        $this->incomingMessage($tenant, 'psid-none', 'mid-none-1', null);

        Http::fake(['*/me/messages*' => Http::response(['message_id' => 'm1'])]);

        $response = $this->actingAs($user, 'tenant')
            ->post($this->panelUrl($tenant, 'messenger/psid-none/reply'), ['message' => 'hi']);

        $response->assertRedirect();
        $response->assertSessionHas('error');
        Http::assertNothingSent();
    }

    public function test_a_human_panel_reply_immediately_invalidates_this_tenants_style_cache(): void
    {
        // The mechanism behind "human corrections are a high-value
        // signal" (see AiConversationStyleService::forgetMessengerStyleCache())
        // — a staff member's real reply must be visible to the AI's very
        // next call, not wait out the cache window.
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);

        MessengerSetting::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'page_id' => 'style-cache-page',
            'page_access_token' => 'style-cache-token', 'is_active' => 1,
        ]);
        $this->incomingMessage($tenant, 'psid-style', 'mid-style-1', null);

        $style = app(AiConversationStyleService::class);
        // Pre-warm the cache so there's something to invalidate.
        $style->messengerStyleExamples($tenant->id);

        Http::fake(['*/me/messages*' => Http::response(['message_id' => 'm-style-1'])]);

        $this->actingAs($user, 'tenant')
            ->post($this->panelUrl($tenant, 'messenger/psid-style/reply'), ['message' => 'এইটা ৯৯৯ টাকা'])
            ->assertRedirect();

        $this->assertStringContainsString('এইটা ৯৯৯ টাকা', $style->messengerStyleExamples($tenant->id));
    }
}
