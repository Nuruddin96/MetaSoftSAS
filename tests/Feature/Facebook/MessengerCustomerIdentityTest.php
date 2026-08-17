<?php

namespace Tests\Feature\Facebook;

use App\Models\FacebookConnection;
use App\Models\FacebookPage;
use App\Models\MessengerCustomer;
use App\Models\MessengerMessage;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Covers the Facebook Messenger customer identity system —
 * MessengerCustomer + FacebookMessengerCustomerService — now actually wired
 * into MessengerWebhookController::handleEvent() (Phase 1.1). Both the model
 * and the service already existed before this phase (database/sql/chunk28.sql)
 * but nothing ever called them; every test here exercises the real webhook
 * entry point end to end, not the service in isolation, so a regression in
 * the wiring itself (not just the service's own logic) would be caught here.
 */
class MessengerCustomerIdentityTest extends FacebookFeatureTestCase
{
    /** @return array{0: Tenant, 1: FacebookPage, 2: User} */
    protected function setUpTenantWithPage(string $pageId): array
    {
        config(['messenger.app_secret' => 'test-secret']);

        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $conn = FacebookConnection::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'connected_by_user_id' => $user->id,
            'facebook_user_id' => 'fbu-'.$pageId, 'user_access_token' => 'tok',
        ]);
        $page = FacebookPage::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'facebook_connection_id' => $conn->id,
            'page_id' => $pageId,
            'page_name' => $pageId.' name',
            'page_access_token' => 'token-for-'.$pageId,
            'status' => 'active',
            'is_active' => true,
        ]);

        return [$tenant, $page, $user];
    }

    protected function inboundPayload(string $pageId, string $psid, string $mid, string $text): array
    {
        return [
            'object' => 'page',
            'entry' => [[
                'id' => $pageId,
                'messaging' => [[
                    'sender' => ['id' => $psid],
                    'recipient' => ['id' => $pageId],
                    'message' => ['mid' => $mid, 'text' => $text],
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

    /** Test 1 — a new Messenger customer's identity is created with name, photo, Page, and tenant. */
    public function test_new_customer_identity_is_created_with_name_photo_page_and_tenant(): void
    {
        [$tenant, $page] = $this->setUpTenantWithPage('id-page-new');

        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'first_name' => 'Rahim', 'last_name' => 'Ahmed',
                'profile_pic' => 'https://scontent.xx.fbcdn.net/rahim.jpg',
            ]),
        ]);

        $this->postSignedWebhook($this->inboundPayload('id-page-new', 'psid-new-1', 'mid-new-1', 'দাম কত?'))->assertOk();

        $identity = MessengerCustomer::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)->where('psid', 'psid-new-1')->first();

        $this->assertNotNull($identity, 'a MessengerCustomer identity row must be created on the first inbound message');
        $this->assertSame('Rahim Ahmed', $identity->name);
        $this->assertSame('Rahim', $identity->first_name);
        $this->assertSame('Ahmed', $identity->last_name);
        $this->assertSame('https://scontent.xx.fbcdn.net/rahim.jpg', $identity->profile_pic_url);
        $this->assertSame($page->id, $identity->facebook_page_id);
        $this->assertSame($tenant->id, $identity->tenant_id);
        $this->assertNotNull($identity->identity_fetched_at);

        // messenger_messages.customer_name keeps working exactly as before —
        // this table is additive, not a replacement (see chunk28.sql).
        $message = MessengerMessage::withoutGlobalScopes()->where('mid', 'mid-new-1')->first();
        $this->assertSame('Rahim Ahmed', $message->customer_name);
    }

    /** Test 2 — an existing Messenger customer is reused, never duplicated, and Graph is not re-hit. */
    public function test_existing_customer_identity_is_reused_not_duplicated(): void
    {
        [$tenant] = $this->setUpTenantWithPage('id-page-existing');

        Http::fake([
            'graph.facebook.com/*' => Http::response(['first_name' => 'Karim', 'last_name' => 'Uddin', 'profile_pic' => 'https://x/karim.jpg']),
        ]);

        $this->postSignedWebhook($this->inboundPayload('id-page-existing', 'psid-existing-1', 'mid-e-1', 'হ্যালো'))->assertOk();
        $this->postSignedWebhook($this->inboundPayload('id-page-existing', 'psid-existing-1', 'mid-e-2', 'আছেন?'))->assertOk();

        $this->assertSame(
            1,
            MessengerCustomer::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('psid', 'psid-existing-1')->count(),
            'a second message from an already-known psid must reuse the existing identity row, not create a second one'
        );

        // needsRefresh() keeps a just-fetched identity fresh for
        // messenger.profile_refresh_hours (24h default) — the second
        // message must not call Graph again at all.
        Http::assertSentCount(1);
    }

    /**
     * Test 3 — a bad Graph HTTP response never blocks message processing,
     * AND (post-fix) a minimal messenger_customers row is still created —
     * see FacebookMessengerCustomerService's class docblock for why. Also
     * confirms Meta's HTTP status/error fields reach the log, which is the
     * whole reason MessengerApi::getProfile() now returns the raw Response
     * instead of discarding the body on failure.
     */
    public function test_facebook_profile_api_failure_does_not_block_message_processing(): void
    {
        [$tenant, $page] = $this->setUpTenantWithPage('id-page-fail');

        Http::fake([
            'graph.facebook.com/*' => Http::response(['error' => ['message' => 'temporarily unavailable', 'type' => 'OAuthException', 'code' => 1]], 500),
        ]);

        Log::spy();

        $this->postSignedWebhook($this->inboundPayload('id-page-fail', 'psid-fail-1', 'mid-fail-1', 'দাম কত?'))->assertOk();

        // Asserts the structured log line carries Meta's actual error
        // details (the whole point of this fix) rather than pinning an
        // exact call count, which Mockery's spy cardinality tracking
        // combined with a closure matcher can report unreliably.
        Log::shouldHaveReceived('warning')
            ->withArgs(function (string $message, array $context) {
                return $message === 'Messenger identity sync: Graph API returned an unsuccessful response.'
                    && $context['http_status'] === 500
                    && $context['error_code'] === 1
                    && $context['error_type'] === 'OAuthException'
                    && $context['error_message'] === 'temporarily unavailable';
            });

        $message = MessengerMessage::withoutGlobalScopes()->where('mid', 'mid-fail-1')->first();
        $this->assertNotNull($message, 'the message must still be recorded even when the profile lookup fails');
        $this->assertSame('Messenger Customer', $message->customer_name, 'no real name was ever available — must fall back to the neutral placeholder, not stay NULL');

        $identity = MessengerCustomer::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('psid', 'psid-fail-1')->first();
        $this->assertNotNull($identity, 'a failed Graph lookup must still leave a minimal identity row behind, not silently disappear');
        $this->assertSame($page->id, $identity->facebook_page_id);
        $this->assertNotNull($identity->identity_fetched_at, 'must advance even on failure so needsRefresh() backs off instead of retrying every message');
        $this->assertNull($identity->name);
        $this->assertNull($identity->profile_pic_url);
    }

    /** Test 3b — a transport-level failure (not just a bad response) is equally survivable, and still persists a minimal row. */
    public function test_facebook_profile_transport_exception_does_not_block_message_processing(): void
    {
        [$tenant] = $this->setUpTenantWithPage('id-page-throw');

        Http::fake(function () {
            throw new ConnectionException('connection refused');
        });

        $this->postSignedWebhook($this->inboundPayload('id-page-throw', 'psid-throw-1', 'mid-throw-1', 'হ্যালো'))->assertOk();

        $this->assertNotNull(
            MessengerMessage::withoutGlobalScopes()->where('mid', 'mid-throw-1')->first(),
            'a thrown transport exception during profile lookup must not take the whole webhook request down'
        );

        $identity = MessengerCustomer::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('psid', 'psid-throw-1')->first();
        $this->assertNotNull($identity, 'a transport-level failure must still leave a minimal identity row behind');
        $this->assertNotNull($identity->identity_fetched_at);
        $this->assertNull($identity->name);
    }

    /**
     * Test — Meta error code 100 / subcode 33 ("Object does not exist,
     * cannot be loaded due to missing permission") — the exact error shape
     * observed in the production incident this fix addresses. Must not
     * abort customer creation.
     */
    public function test_graph_error_code_100_subcode_33_does_not_abort_customer_creation(): void
    {
        [$tenant, $page] = $this->setUpTenantWithPage('id-page-100-33');

        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'error' => ['message' => 'Object does not exist, cannot be loaded due to missing permission', 'type' => 'GraphMethodException', 'code' => 100, 'error_subcode' => 33],
            ], 400),
        ]);

        $this->postSignedWebhook($this->inboundPayload('id-page-100-33', 'psid-100-33', 'mid-100-33', 'হ্যালো'))->assertOk();

        $identity = MessengerCustomer::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('psid', 'psid-100-33')->first();
        $this->assertNotNull($identity, 'code 100/subcode 33 must not prevent the identity row from being created');
        $this->assertSame($page->id, $identity->facebook_page_id);
        $this->assertNull($identity->name);

        $message = MessengerMessage::withoutGlobalScopes()->where('mid', 'mid-100-33')->first();
        $this->assertSame('Messenger Customer', $message->customer_name);
    }

    /**
     * Test — Meta error code 190 (invalid/expired OAuth token). Must not
     * abort customer creation either, same unconditional-persistence rule.
     */
    public function test_graph_error_code_190_does_not_abort_customer_creation(): void
    {
        [$tenant, $page] = $this->setUpTenantWithPage('id-page-190');

        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'error' => ['message' => 'Error validating access token: Session has expired', 'type' => 'OAuthException', 'code' => 190, 'error_subcode' => 463],
            ], 400),
        ]);

        $this->postSignedWebhook($this->inboundPayload('id-page-190', 'psid-190', 'mid-190', 'হ্যালো'))->assertOk();

        $identity = MessengerCustomer::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('psid', 'psid-190')->first();
        $this->assertNotNull($identity, 'code 190 must not prevent the identity row from being created');
        $this->assertSame($page->id, $identity->facebook_page_id);
        $this->assertNull($identity->name);
    }

    /**
     * Test — a failed refresh must never destroy a previously-good
     * identity. Seeds a successful sync (name + photo), backdates
     * identity_fetched_at past the refresh window so the next message is
     * refresh-eligible, then forces that refresh to fail — the existing
     * name/photo must survive untouched.
     */
    public function test_failed_lookup_preserves_previously_synced_identity_data(): void
    {
        [$tenant, $page] = $this->setUpTenantWithPage('id-page-preserve');

        Http::fake([
            'graph.facebook.com/*' => Http::response(['first_name' => 'Rahim', 'last_name' => 'Ahmed', 'profile_pic' => 'https://x/rahim.jpg']),
        ]);

        $this->postSignedWebhook($this->inboundPayload('id-page-preserve', 'psid-preserve-1', 'mid-preserve-1', 'হ্যালো'))->assertOk();

        $identity = MessengerCustomer::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('psid', 'psid-preserve-1')->first();
        $this->assertSame('Rahim Ahmed', $identity->name);
        $this->assertSame('https://x/rahim.jpg', $identity->profile_pic_url);

        // Force the identity stale so needsRefresh() allows a second attempt.
        $identity->forceFill(['identity_fetched_at' => now()->subHours(25)])->save();

        Http::fake([
            'graph.facebook.com/*' => Http::response(['error' => ['message' => 'Session has expired', 'type' => 'OAuthException', 'code' => 190]], 400),
        ]);

        $this->postSignedWebhook($this->inboundPayload('id-page-preserve', 'psid-preserve-1', 'mid-preserve-2', 'আছেন?'))->assertOk();

        $identity->refresh();
        $this->assertSame('Rahim Ahmed', $identity->name, 'a failed refresh must not erase a previously-synced name');
        $this->assertSame('https://x/rahim.jpg', $identity->profile_pic_url, 'a failed refresh must not erase a previously-synced photo');
        $this->assertSame($page->id, $identity->facebook_page_id);
    }

    /**
     * Test — identity_fetched_at advances even when the refresh attempt
     * fails, which is what makes needsRefresh()'s backoff actually work
     * (a permanently-failing PSID stops being retried every message).
     */
    public function test_identity_fetched_at_advances_even_when_lookup_fails(): void
    {
        [$tenant] = $this->setUpTenantWithPage('id-page-advance');

        Http::fake(['graph.facebook.com/*' => Http::response(['first_name' => 'Karim'])]);
        $this->postSignedWebhook($this->inboundPayload('id-page-advance', 'psid-advance-1', 'mid-advance-1', 'হ্যালো'))->assertOk();

        $identity = MessengerCustomer::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('psid', 'psid-advance-1')->first();
        $firstFetchedAt = $identity->identity_fetched_at;
        $identity->forceFill(['identity_fetched_at' => now()->subHours(25)])->save();
        $backdatedFetchedAt = $identity->fresh()->identity_fetched_at;

        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'temporarily unavailable', 'code' => 1]], 500)]);
        $this->postSignedWebhook($this->inboundPayload('id-page-advance', 'psid-advance-1', 'mid-advance-2', 'আছেন?'))->assertOk();

        $identity->refresh();
        $this->assertTrue(
            $identity->identity_fetched_at->gt($backdatedFetchedAt),
            'identity_fetched_at must advance on a failed refresh, not stay stuck at the backdated value'
        );
        $this->assertNotNull($firstFetchedAt); // sanity: the first sync did set it originally
    }

    /**
     * Test — customer_name three-tier fallback: MessengerCustomer name if
     * available, otherwise first_name+last_name (already covered by Test
     * 1), otherwise (this test) the neutral "Messenger Customer" fallback
     * when Graph gives us nothing usable at all and no name was ever
     * resolved elsewhere in the conversation.
     */
    public function test_customer_name_falls_back_to_neutral_placeholder_when_nothing_is_resolvable(): void
    {
        $this->setUpTenantWithPage('id-page-fallback');

        Http::fake(['graph.facebook.com/*' => Http::response(['id' => '123'])]); // 200 OK, no name/photo fields at all

        $this->postSignedWebhook($this->inboundPayload('id-page-fallback', 'psid-fallback-1', 'mid-fallback-1', 'হ্যালো'))->assertOk();

        $message = MessengerMessage::withoutGlobalScopes()->where('mid', 'mid-fallback-1')->first();
        $this->assertSame('Messenger Customer', $message->customer_name);
    }

    /** Test 4 — a retried webhook delivery does not create a duplicate identity. */
    public function test_duplicate_webhook_delivery_does_not_duplicate_identity(): void
    {
        [$tenant] = $this->setUpTenantWithPage('id-page-dup');

        Http::fake(['graph.facebook.com/*' => Http::response(['first_name' => 'Nusrat', 'last_name' => 'Jahan'])]);

        $payload = $this->inboundPayload('id-page-dup', 'psid-dup-1', 'mid-dup-1', 'অর্ডার দিতে চাই');

        $this->postSignedWebhook($payload)->assertOk();
        $this->postSignedWebhook($payload)->assertOk(); // simulated Meta retry of the exact same event

        $this->assertSame(1, MessengerMessage::withoutGlobalScopes()->where('mid', 'mid-dup-1')->count());
        $this->assertSame(
            1,
            MessengerCustomer::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('psid', 'psid-dup-1')->count()
        );
    }

    /** Test 5 — a tenant can never retrieve another tenant's Messenger customer identity. */
    public function test_tenant_cannot_retrieve_another_tenants_messenger_customer(): void
    {
        [$tenantA] = $this->setUpTenantWithPage('id-page-a');
        $tenantB = $this->makeTenant();
        $userB = $this->makeUser($tenantB->id);
        $connB = FacebookConnection::withoutGlobalScopes()->create([
            'tenant_id' => $tenantB->id, 'connected_by_user_id' => $userB->id,
            'facebook_user_id' => 'fbu-b', 'user_access_token' => 'tok-b',
        ]);
        FacebookPage::withoutGlobalScopes()->create([
            'tenant_id' => $tenantB->id, 'facebook_connection_id' => $connB->id,
            'page_id' => 'id-page-b', 'page_access_token' => 'token-b', 'status' => 'active', 'is_active' => true,
        ]);

        Http::fake([
            'graph.facebook.com/*' => Http::sequence()
                ->push(['first_name' => 'Alice'])
                ->push(['first_name' => 'Bob']),
        ]);

        // Same literal PSID string under two different tenants/Pages —
        // Facebook PSIDs are Page-scoped, not global (same premise as
        // AutoPendingOrderTest::test_two_tenants_never_cross_create_customers_or_orders).
        $this->postSignedWebhook($this->inboundPayload('id-page-a', 'shared-psid', 'mid-a-1', 'হাই'))->assertOk();
        $this->postSignedWebhook($this->inboundPayload('id-page-b', 'shared-psid', 'mid-b-1', 'হাই'))->assertOk();

        app()->instance('currentTenant', $tenantA);
        $visibleToA = MessengerCustomer::where('psid', 'shared-psid')->get();

        $this->assertCount(1, $visibleToA, "a tenant-scoped query must only see its own identity row, never another tenant's");
        $this->assertSame($tenantA->id, $visibleToA->first()->tenant_id);
        $this->assertSame('Alice', $visibleToA->first()->first_name);
    }

    /** Test 7 — the inbox renders without error and falls back gracefully when no profile photo is available. */
    public function test_inbox_renders_without_error_when_profile_photo_is_unavailable(): void
    {
        [$tenant, , $user] = $this->setUpTenantWithPage('id-page-nophoto');

        // A real, documented Graph response shape: a name but no
        // profile_pic at all.
        Http::fake(['graph.facebook.com/*' => Http::response(['first_name' => 'Sadia', 'last_name' => 'Islam'])]);

        $this->postSignedWebhook($this->inboundPayload('id-page-nophoto', 'psid-nophoto-1', 'mid-nophoto-1', 'হ্যালো'))->assertOk();

        $identity = MessengerCustomer::withoutGlobalScopes()->where('psid', 'psid-nophoto-1')->first();
        $this->assertNull($identity->profile_pic_url);

        $response = $this->actingAs($user, 'tenant')->get('/shop/'.$tenant->subdomain.'/panel/messenger');

        $response->assertOk();
        $response->assertSee('Sadia Islam');
    }
}
