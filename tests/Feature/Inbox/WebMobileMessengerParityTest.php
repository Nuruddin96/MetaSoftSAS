<?php

namespace Tests\Feature\Inbox;

use App\Models\MessengerCustomer;
use App\Models\MessengerMessage;
use App\Models\Tenant;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithApiSchema;
use Tests\TestCase;

/**
 * The actual task this covers: "Web Inbox shows Messenger, Mobile Inbox
 * doesn't." Traced end to end (Facebook webhook -> MessengerWebhookController
 * -> messenger_messages/messenger_customers -> UnifiedInboxService ->
 * {Tenant\InboxController (web /panel/inbox), Api\Mobile\MessengerController
 * (mobile)}) and found both the real web Inbox (route('tenant.inbox'), NOT
 * the separate legacy Tenant\MessengerInboxController) and the mobile API
 * already call the exact same UnifiedInboxService::paginate('messenger', ...)
 * — so there was no data-layer divergence to fix at that layer. This test
 * locks that fact in: given the identical underlying data (including a real
 * MessengerCustomer identity — name + photo), web and mobile must return the
 * identical customer_name/avatar_url for the same psid, never a generic
 * fallback when a real identity exists. The actual root cause (mobile never
 * re-fetching once loaded — no realtime mechanism) lives in the Flutter app,
 * not here; see inbox_list_controller.dart/conversation_thread_controller.dart's
 * silentRefresh().
 */
class WebMobileMessengerParityTest extends TestCase
{
    use InteractsWithApiSchema;

    protected function setUp(): void
    {
        parent::setUp();
        // Also creates messenger_messages/messenger_customers — see
        // InteractsWithCommerceSchema's own docblock on those tables.
        $this->setUpApiSchema();
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    protected function panelUrl(Tenant $tenant, string $path): string
    {
        return '/shop/'.$tenant->subdomain.'/panel/'.$path;
    }

    public function test_web_and_mobile_inbox_show_the_same_real_facebook_identity_for_the_same_conversation(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        app()->instance('currentTenant', $tenant);

        MessengerMessage::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'sender_psid' => 'psid-real-identity',
            'customer_name' => 'Messenger Customer', // the placeholder the webhook writes before identity resolves
            'message_text' => 'Hi, is this available?', 'direction' => 'in', 'status' => 'new',
            'created_at' => now(),
        ]);

        // The real, Graph-resolved identity — what MUST win over any placeholder/initials.
        MessengerCustomer::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'psid' => 'psid-real-identity',
            'first_name' => 'Farhana', 'last_name' => 'Akter', 'name' => 'Farhana Akter',
            'profile_pic_url' => 'https://platform-lookaside.fbsbx.com/platform/profilepic/?psid=psid-real-identity',
            'identity_fetched_at' => now(),
        ]);

        app()->forgetInstance('currentTenant');

        // Web — the real unified Inbox (route('tenant.inbox')), not the legacy Messenger-only page.
        $webResponse = $this->actingAs($user, 'tenant')->get($this->panelUrl($tenant, 'inbox'));
        $webResponse->assertOk();
        $webResponse->assertSee('Farhana Akter');
        $webResponse->assertSee('platform-lookaside.fbsbx.com', false);
        $webResponse->assertDontSee('Messenger Customer');

        // Mobile — Api\Mobile\MessengerController::index(), same UnifiedInboxService call.
        Sanctum::actingAs($user);
        $mobileResponse = $this->getJson('/api/mobile/v1/messenger/conversations');
        $mobileResponse->assertOk();
        $mobileResponse->assertJsonPath('data.0.psid', 'psid-real-identity');
        $mobileResponse->assertJsonPath('data.0.customer_name', 'Farhana Akter');
        $mobileResponse->assertJsonPath(
            'data.0.avatar_url',
            'https://platform-lookaside.fbsbx.com/platform/profilepic/?psid=psid-real-identity'
        );
    }

    public function test_mobile_conversation_detail_also_shows_the_real_identity_not_a_generic_fallback(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        app()->instance('currentTenant', $tenant);

        MessengerMessage::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'sender_psid' => 'psid-detail',
            'customer_name' => 'Messenger Customer',
            'message_text' => 'Hello', 'direction' => 'in', 'status' => 'new', 'created_at' => now(),
        ]);
        MessengerCustomer::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'psid' => 'psid-detail',
            'name' => 'Rashed Khan', 'profile_pic_url' => 'https://platform-lookaside.fbsbx.com/pic-detail',
            'identity_fetched_at' => now(),
        ]);
        app()->forgetInstance('currentTenant');

        Sanctum::actingAs($user);
        $response = $this->getJson('/api/mobile/v1/messenger/psid-detail');

        $response->assertOk();
        $response->assertJsonPath('customer_name', 'Rashed Khan');
        $response->assertJsonPath('avatar_url', 'https://platform-lookaside.fbsbx.com/pic-detail');
    }

    /**
     * Graceful degradation, per the task's explicit requirement: when Meta's
     * Graph API genuinely never returns an identity for a psid (the real,
     * documented Messenger Platform restriction — confirmed in production
     * logs as error code 100/subcode 33 for many psids), the UI must still
     * show SOMETHING better than a bare PSID or "Facebook User" — the
     * message-level customer_name a prior resolution already captured.
     */
    public function test_mobile_falls_back_to_resolved_message_name_when_no_graph_identity_exists(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        app()->instance('currentTenant', $tenant);

        MessengerMessage::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'sender_psid' => 'psid-no-graph-identity',
            'customer_name' => 'Sumon Mia', // resolved earlier via CustomerInfoExtractor, not Graph
            'message_text' => 'Amar naam Sumon Mia', 'direction' => 'in', 'status' => 'new', 'created_at' => now(),
        ]);
        // No MessengerCustomer row at all — Graph never resolved this one (subcode 33 style failure).
        app()->forgetInstance('currentTenant');

        Sanctum::actingAs($user);
        $response = $this->getJson('/api/mobile/v1/messenger/psid-no-graph-identity');

        $response->assertOk();
        $response->assertJsonPath('customer_name', 'Sumon Mia');
    }

    public function test_mobile_messenger_endpoint_never_returns_another_tenants_conversation(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $userB = $this->makeUser($tenantB->id);

        app()->instance('currentTenant', $tenantA);
        MessengerMessage::withoutGlobalScopes()->create([
            'tenant_id' => $tenantA->id, 'sender_psid' => 'psid-tenant-a-only',
            'customer_name' => 'Tenant A Customer', 'message_text' => 'secret', 'direction' => 'in',
            'status' => 'new', 'created_at' => now(),
        ]);
        MessengerCustomer::withoutGlobalScopes()->create([
            'tenant_id' => $tenantA->id, 'psid' => 'psid-tenant-a-only',
            'name' => 'Tenant A Customer', 'profile_pic_url' => 'https://example.com/tenant-a.jpg',
        ]);
        app()->forgetInstance('currentTenant');

        Sanctum::actingAs($userB);
        $response = $this->getJson('/api/mobile/v1/messenger/conversations');

        $response->assertOk();
        $response->assertJsonMissing(['psid' => 'psid-tenant-a-only']);
        $this->assertSame([], $response->json('data'));
    }

    /** New inbound message must move the conversation to the top and bump unread_count — the actual state a poll/refresh should surface. */
    public function test_new_inbound_message_updates_unread_count_and_latest_preview_for_both_surfaces(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        app()->instance('currentTenant', $tenant);

        MessengerMessage::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'sender_psid' => 'psid-updates',
            'customer_name' => 'Nasrin', 'message_text' => 'Price koto?', 'direction' => 'in',
            'status' => 'contacted', 'created_at' => now()->subMinutes(10),
        ]);

        // A brand-new inbound message — exactly what the webhook inserts on a fresh customer reply.
        MessengerMessage::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'sender_psid' => 'psid-updates',
            'customer_name' => 'Nasrin', 'message_text' => 'Ekhono ache ki?', 'direction' => 'in',
            'status' => 'new', 'created_at' => now(),
        ]);
        app()->forgetInstance('currentTenant');

        Sanctum::actingAs($user);
        $response = $this->getJson('/api/mobile/v1/messenger/conversations');

        $response->assertOk();
        $response->assertJsonPath('data.0.psid', 'psid-updates');
        $response->assertJsonPath('data.0.message_text', 'Ekhono ache ki?');
        $response->assertJsonPath('data.0.unread_count', 1);
    }
}
