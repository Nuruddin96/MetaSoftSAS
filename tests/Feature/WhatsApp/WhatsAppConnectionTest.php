<?php

namespace Tests\Feature\WhatsApp;

use App\Models\Tenant;
use App\Models\WhatsAppBusinessAccount;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppPhoneNumber;
use App\Services\WhatsApp\WhatsAppOAuthService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class WhatsAppConnectionTest extends WhatsAppFeatureTestCase
{
    protected function panelUrl(Tenant $tenant, string $path): string
    {
        return '/shop/'.$tenant->subdomain.'/panel/'.$path;
    }

    protected function fakeSuccessfulExchangeAndVerification(string $wabaId = 'waba-1', string $phoneNumberId = 'phone-1'): void
    {
        Http::fake([
            '*/oauth/access_token*' => Http::response(['access_token' => 'long-lived-token', 'expires_in' => 5184000]),
            "*/{$wabaId}/phone_numbers*" => Http::response(['data' => [
                ['id' => $phoneNumberId, 'display_phone_number' => '+8801700000000', 'verified_name' => 'My Shop', 'quality_rating' => 'GREEN'],
            ]]),
            "*/{$wabaId}/subscribed_apps*" => Http::response(['success' => true]),
        ]);
    }

    protected function completePayload(WhatsAppOAuthService $service, Tenant $tenant, $user, array $overrides = []): array
    {
        $state = $service->currentOrNewState($tenant, $user);

        return array_merge([
            'state' => $state->state,
            'code' => 'good-code',
            'waba_id' => 'waba-1',
            'phone_number_id' => 'phone-1',
            'business_id' => 'biz-1',
        ], $overrides);
    }

    // --- valid connection / successful resolution -------------------------------------------------

    public function test_valid_connection_callback_creates_account_and_phone_number(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $service = new WhatsAppOAuthService;
        $this->fakeSuccessfulExchangeAndVerification();

        $payload = $this->completePayload($service, $tenant, $user);

        $response = $this->actingAs($user, 'tenant')
            ->post($this->panelUrl($tenant, 'whatsapp/connect'), $payload);

        $response->assertRedirect(route('tenant.settings', ['tenant_slug' => $tenant->subdomain]));
        $response->assertSessionHas('success');

        $account = WhatsAppBusinessAccount::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();
        $this->assertNotNull($account);
        $this->assertSame('waba-1', $account->waba_id);
        $this->assertSame('biz-1', $account->business_id);
        $this->assertSame('long-lived-token', $account->user_access_token);

        $phone = WhatsAppPhoneNumber::withoutGlobalScopes()->where('phone_number_id', 'phone-1')->first();
        $this->assertNotNull($phone);
        $this->assertSame($tenant->id, $phone->tenant_id);
        $this->assertSame($account->id, $phone->whatsapp_business_account_id);
        $this->assertSame('+8801700000000', $phone->display_phone_number);
        $this->assertSame('My Shop', $phone->verified_name);
        $this->assertSame('active', $phone->status);
        $this->assertNotNull($phone->subscribed_at);
    }

    public function test_successful_waba_and_phone_number_resolution_persists_meta_supplied_details(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $service = new WhatsAppOAuthService;
        $this->fakeSuccessfulExchangeAndVerification('waba-resolve', 'phone-resolve');

        $this->actingAs($user, 'tenant')->post(
            $this->panelUrl($tenant, 'whatsapp/connect'),
            $this->completePayload($service, $tenant, $user, ['waba_id' => 'waba-resolve', 'phone_number_id' => 'phone-resolve'])
        );

        $phone = WhatsAppPhoneNumber::withoutGlobalScopes()->where('phone_number_id', 'phone-resolve')->first();
        $this->assertSame('GREEN', $phone->quality_rating);
    }

    // --- state security -----------------------------------------------------------------------------

    public function test_invalid_state_is_rejected_without_creating_any_connection(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);

        $response = $this->actingAs($user, 'tenant')->post($this->panelUrl($tenant, 'whatsapp/connect'), [
            'state' => 'not-a-real-state-token',
            'code' => 'code', 'waba_id' => 'waba-1', 'phone_number_id' => 'phone-1',
        ]);

        $response->assertRedirect(route('tenant.settings', ['tenant_slug' => $tenant->subdomain]));
        $response->assertSessionHas('error');
        $this->assertSame(0, WhatsAppBusinessAccount::withoutGlobalScopes()->count());
    }

    public function test_expired_state_is_rejected(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $service = new WhatsAppOAuthService;

        $state = $service->createState($tenant, $user);
        $state->forceFill(['expires_at' => now()->subMinute()])->save();

        $response = $this->actingAs($user, 'tenant')->post($this->panelUrl($tenant, 'whatsapp/connect'), [
            'state' => $state->state, 'code' => 'code', 'waba_id' => 'waba-1', 'phone_number_id' => 'phone-1',
        ]);

        $response->assertSessionHas('error');
        $this->assertSame(0, WhatsAppBusinessAccount::withoutGlobalScopes()->count());
    }

    public function test_reused_state_is_rejected_on_second_submission(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $service = new WhatsAppOAuthService;
        $this->fakeSuccessfulExchangeAndVerification();

        $payload = $this->completePayload($service, $tenant, $user);

        $this->actingAs($user, 'tenant')->post($this->panelUrl($tenant, 'whatsapp/connect'), $payload)
            ->assertSessionHas('success');

        // Same state token submitted again (e.g. a double-click or a replayed request).
        $second = $this->actingAs($user, 'tenant')->post($this->panelUrl($tenant, 'whatsapp/connect'), $payload);

        $second->assertSessionHas('error');
        $this->assertSame(1, WhatsAppBusinessAccount::withoutGlobalScopes()->count(), 'a replayed state must not create/duplicate anything');
    }

    public function test_tenant_isolation_a_state_minted_for_tenant_a_cannot_connect_a_number_for_tenant_b(): void
    {
        $tenantA = $this->makeTenant();
        $userA = $this->makeUser($tenantA->id);
        $tenantB = $this->makeTenant();
        $userB = $this->makeUser($tenantB->id);
        $service = new WhatsAppOAuthService;
        $this->fakeSuccessfulExchangeAndVerification();

        // State was minted for tenant A/user A...
        $state = $service->currentOrNewState($tenantA, $userA);

        // ...but tenant B's own staff member submits it against tenant B's panel URL.
        $response = $this->actingAs($userB, 'tenant')->post($this->panelUrl($tenantB, 'whatsapp/connect'), [
            'state' => $state->state, 'code' => 'code', 'waba_id' => 'waba-1', 'phone_number_id' => 'phone-1', 'business_id' => 'biz-1',
        ]);

        $response->assertSessionHas('error');
        $this->assertSame(0, WhatsAppBusinessAccount::withoutGlobalScopes()->where('tenant_id', $tenantB->id)->count());
    }

    // --- WABA/phone verification ---------------------------------------------------------------------

    public function test_waba_phone_mismatch_is_rejected_and_persists_nothing(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $service = new WhatsAppOAuthService;

        Http::fake([
            '*/oauth/access_token*' => Http::response(['access_token' => 'long-lived-token', 'expires_in' => 5184000]),
            // The WABA's real phone_numbers list does NOT contain "phone-claimed-but-not-real".
            '*/waba-1/phone_numbers*' => Http::response(['data' => [
                ['id' => 'a-different-real-number', 'display_phone_number' => '+8801999999999'],
            ]]),
        ]);

        $response = $this->actingAs($user, 'tenant')->post(
            $this->panelUrl($tenant, 'whatsapp/connect'),
            $this->completePayload($service, $tenant, $user, ['phone_number_id' => 'phone-claimed-but-not-real'])
        );

        $response->assertSessionHas('error');
        $this->assertSame(0, WhatsAppBusinessAccount::withoutGlobalScopes()->count());
        $this->assertSame(0, WhatsAppPhoneNumber::withoutGlobalScopes()->count());
    }

    public function test_meta_api_failure_during_token_exchange_is_handled_gracefully(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $service = new WhatsAppOAuthService;

        Http::fake(['*/oauth/access_token*' => Http::response(['error' => ['message' => 'invalid code', 'code' => 100]], 400)]);

        $response = $this->actingAs($user, 'tenant')->post(
            $this->panelUrl($tenant, 'whatsapp/connect'),
            $this->completePayload($service, $tenant, $user)
        );

        $response->assertRedirect(route('tenant.settings', ['tenant_slug' => $tenant->subdomain]));
        $response->assertSessionHas('error');
        $this->assertSame(0, WhatsAppBusinessAccount::withoutGlobalScopes()->count());
    }

    public function test_connection_failure_during_token_exchange_fails_gracefully(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $service = new WhatsAppOAuthService;

        Http::fake(function () {
            throw new ConnectionException('Could not resolve host: graph.facebook.com');
        });

        $response = $this->actingAs($user, 'tenant')->post(
            $this->panelUrl($tenant, 'whatsapp/connect'),
            $this->completePayload($service, $tenant, $user)
        );

        $response->assertRedirect(route('tenant.settings', ['tenant_slug' => $tenant->subdomain]));
        $response->assertSessionHas('error');
        $this->assertSame(0, WhatsAppBusinessAccount::withoutGlobalScopes()->count());
    }

    public function test_invalid_or_expired_code_during_phone_verification_is_handled_gracefully(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $service = new WhatsAppOAuthService;

        Http::fake([
            '*/oauth/access_token*' => Http::response(['access_token' => 'long-lived-token', 'expires_in' => 5184000]),
            '*/waba-1/phone_numbers*' => Http::response([
                'error' => ['message' => 'Error validating access token', 'type' => 'OAuthException', 'code' => 190],
            ], 401),
        ]);

        $response = $this->actingAs($user, 'tenant')->post(
            $this->panelUrl($tenant, 'whatsapp/connect'),
            $this->completePayload($service, $tenant, $user)
        );

        $response->assertSessionHas('error');
        $this->assertSame(0, WhatsAppBusinessAccount::withoutGlobalScopes()->count());
    }

    // --- duplicate-connection / cross-tenant hijack protection -----------------------------------------

    public function test_waba_already_claimed_by_another_tenant_is_rejected(): void
    {
        $tenantA = $this->makeTenant();
        $userA = $this->makeUser($tenantA->id);
        WhatsAppBusinessAccount::withoutGlobalScopes()->create([
            'tenant_id' => $tenantA->id, 'connected_by_user_id' => $userA->id,
            'waba_id' => 'shared-waba', 'user_access_token' => 'tok-a',
        ]);

        $tenantB = $this->makeTenant();
        $userB = $this->makeUser($tenantB->id);
        $service = new WhatsAppOAuthService;
        $this->fakeSuccessfulExchangeAndVerification('shared-waba', 'phone-b');

        $response = $this->actingAs($userB, 'tenant')->post(
            $this->panelUrl($tenantB, 'whatsapp/connect'),
            $this->completePayload($service, $tenantB, $userB, ['waba_id' => 'shared-waba', 'phone_number_id' => 'phone-b'])
        );

        $response->assertSessionHas('error');
        $this->assertSame(0, WhatsAppBusinessAccount::withoutGlobalScopes()->where('tenant_id', $tenantB->id)->count());
    }

    public function test_phone_number_already_claimed_by_another_tenant_is_rejected(): void
    {
        $tenantA = $this->makeTenant();
        $userA = $this->makeUser($tenantA->id);
        $accountA = WhatsAppBusinessAccount::withoutGlobalScopes()->create([
            'tenant_id' => $tenantA->id, 'connected_by_user_id' => $userA->id,
            'waba_id' => 'waba-a', 'user_access_token' => 'tok-a',
        ]);
        WhatsAppPhoneNumber::withoutGlobalScopes()->create([
            'tenant_id' => $tenantA->id, 'whatsapp_business_account_id' => $accountA->id,
            'phone_number_id' => 'shared-phone', 'is_active' => 1,
        ]);

        $tenantB = $this->makeTenant();
        $userB = $this->makeUser($tenantB->id);
        $service = new WhatsAppOAuthService;
        $this->fakeSuccessfulExchangeAndVerification('waba-b', 'shared-phone');

        $response = $this->actingAs($userB, 'tenant')->post(
            $this->panelUrl($tenantB, 'whatsapp/connect'),
            $this->completePayload($service, $tenantB, $userB, ['waba_id' => 'waba-b', 'phone_number_id' => 'shared-phone'])
        );

        $response->assertSessionHas('error');
        $this->assertSame(
            $tenantA->id,
            WhatsAppPhoneNumber::withoutGlobalScopes()->where('phone_number_id', 'shared-phone')->first()->tenant_id,
            'the phone number must stay with its rightful owner'
        );
    }

    // --- reconnect / needs_reconnect -----------------------------------------------------------------

    public function test_reconnect_updates_the_existing_connection_instead_of_duplicating_it(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $service = new WhatsAppOAuthService;
        $this->fakeSuccessfulExchangeAndVerification();

        $this->actingAs($user, 'tenant')->post(
            $this->panelUrl($tenant, 'whatsapp/connect'),
            $this->completePayload($service, $tenant, $user)
        )->assertSessionHas('success');

        // Simulate Phase 3 flagging the number for reconnect after a token failure.
        WhatsAppPhoneNumber::withoutGlobalScopes()->where('phone_number_id', 'phone-1')->update(['status' => 'needs_reconnect']);

        // Tenant runs Connect again (same WABA/number) — must UPDATE, not duplicate.
        $this->actingAs($user, 'tenant')->post(
            $this->panelUrl($tenant, 'whatsapp/connect'),
            $this->completePayload($service, $tenant, $user)
        )->assertSessionHas('success');

        $this->assertSame(1, WhatsAppBusinessAccount::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count());
        $this->assertSame(1, WhatsAppPhoneNumber::withoutGlobalScopes()->where('phone_number_id', 'phone-1')->count());

        $phone = WhatsAppPhoneNumber::withoutGlobalScopes()->where('phone_number_id', 'phone-1')->first();
        $this->assertSame('active', $phone->status, 'a successful reconnect must clear needs_reconnect');
    }

    // --- disconnect ------------------------------------------------------------------------------------

    public function test_disconnect_preserves_historical_messages_and_marks_the_number_inactive(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $account = WhatsAppBusinessAccount::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'connected_by_user_id' => $user->id,
            'waba_id' => 'waba-disc', 'user_access_token' => 'tok',
        ]);
        $phone = WhatsAppPhoneNumber::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'whatsapp_business_account_id' => $account->id,
            'phone_number_id' => 'phone-disc', 'is_active' => 1, 'status' => 'active',
        ]);
        WhatsAppMessage::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'whatsapp_phone_number_id' => $phone->id,
            'wa_id' => '8801700000000', 'message_text' => 'historical message', 'direction' => 'in',
        ]);

        Http::fake(['*/subscribed_apps*' => Http::response(['success' => true])]);

        $response = $this->actingAs($user, 'tenant')
            ->post($this->panelUrl($tenant, 'whatsapp/'.$phone->id.'/disconnect'));

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $phone->refresh();
        $this->assertFalse($phone->is_active);
        $this->assertNotNull($phone->disconnected_at);

        $this->assertSame(
            1,
            WhatsAppMessage::withoutGlobalScopes()->where('whatsapp_phone_number_id', $phone->id)->count(),
            'disconnect must never delete historical conversation messages'
        );

        // The account-level token is untouched — a tenant can reconnect
        // without redoing the full WABA-level signup for the common
        // single-number case.
        $this->assertSame('tok', $account->fresh()->user_access_token);
    }

    public function test_disconnect_enforces_tenant_ownership(): void
    {
        $tenantA = $this->makeTenant();
        $accountA = WhatsAppBusinessAccount::withoutGlobalScopes()->create([
            'tenant_id' => $tenantA->id, 'connected_by_user_id' => $this->makeUser($tenantA->id)->id,
            'waba_id' => 'waba-a', 'user_access_token' => 'tok-a',
        ]);
        $phoneA = WhatsAppPhoneNumber::withoutGlobalScopes()->create([
            'tenant_id' => $tenantA->id, 'whatsapp_business_account_id' => $accountA->id,
            'phone_number_id' => 'phone-a', 'is_active' => 1, 'status' => 'active',
        ]);

        $tenantB = $this->makeTenant();
        $userB = $this->makeUser($tenantB->id);

        Http::fake(['*/subscribed_apps*' => Http::response(['success' => true])]);

        $response = $this->actingAs($userB, 'tenant')
            ->post($this->panelUrl($tenantB, 'whatsapp/'.$phoneA->id.'/disconnect'));

        $response->assertNotFound();
        $this->assertTrue($phoneA->fresh()->is_active, "tenant A's number must be untouched by tenant B's attempt");
        Http::assertNothingSent();
    }

    public function test_disconnecting_the_last_active_number_unsubscribes_the_waba(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $account = WhatsAppBusinessAccount::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'connected_by_user_id' => $user->id,
            'waba_id' => 'waba-last', 'user_access_token' => 'tok-last',
        ]);
        $phone = WhatsAppPhoneNumber::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'whatsapp_business_account_id' => $account->id,
            'phone_number_id' => 'phone-last', 'is_active' => 1, 'status' => 'active',
        ]);

        Http::fake(['*/waba-last/subscribed_apps*' => Http::response(['success' => true])]);

        $this->actingAs($user, 'tenant')->post($this->panelUrl($tenant, 'whatsapp/'.$phone->id.'/disconnect'));

        Http::assertSent(fn ($request) => $request->method() === 'DELETE' && str_contains((string) $request->url(), 'waba-last/subscribed_apps'));
    }

    public function test_disconnecting_one_of_several_numbers_does_not_unsubscribe_the_waba(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $account = WhatsAppBusinessAccount::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'connected_by_user_id' => $user->id,
            'waba_id' => 'waba-multi', 'user_access_token' => 'tok-multi',
        ]);
        $phone1 = WhatsAppPhoneNumber::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'whatsapp_business_account_id' => $account->id,
            'phone_number_id' => 'phone-multi-1', 'is_active' => 1, 'status' => 'active',
        ]);
        WhatsAppPhoneNumber::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'whatsapp_business_account_id' => $account->id,
            'phone_number_id' => 'phone-multi-2', 'is_active' => 1, 'status' => 'active',
        ]);

        Http::fake();

        $this->actingAs($user, 'tenant')->post($this->panelUrl($tenant, 'whatsapp/'.$phone1->id.'/disconnect'));

        Http::assertNothingSent();
    }

    // --- token security ----------------------------------------------------------------------------

    public function test_access_token_is_encrypted_at_rest_via_the_connect_flow(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $service = new WhatsAppOAuthService;
        $this->fakeSuccessfulExchangeAndVerification();

        $this->actingAs($user, 'tenant')->post(
            $this->panelUrl($tenant, 'whatsapp/connect'),
            $this->completePayload($service, $tenant, $user)
        );

        $account = WhatsAppBusinessAccount::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();
        $raw = \Illuminate\Support\Facades\DB::table('whatsapp_business_accounts')->where('id', $account->id)->value('user_access_token');

        $this->assertNotSame('long-lived-token', $raw);
        $this->assertSame('long-lived-token', $account->fresh()->user_access_token);
    }

    public function test_settings_page_never_renders_the_raw_access_token(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        WhatsAppBusinessAccount::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'connected_by_user_id' => $user->id,
            'waba_id' => 'waba-secret', 'user_access_token' => 'super-secret-token-value',
        ]);

        $response = $this->actingAs($user, 'tenant')->get($this->panelUrl($tenant, 'settings'));

        $response->assertOk();
        $response->assertDontSee('super-secret-token-value');
    }

    // --- token exchange / expiry handling (System User / never-expiring token support) ---------------

    /**
     * WhatsAppOAuthService::exchangeForLongLivedToken() must return the
     * expires_in Meta actually sent — no fabricated fallback. This is a
     * direct unit-level check on the service, independent of the full
     * connect flow below.
     */
    public function test_exchange_for_long_lived_token_returns_the_real_expires_in_when_present(): void
    {
        Http::fake(['*/oauth/access_token*' => Http::response(['access_token' => 'tok', 'expires_in' => 5184000])]);

        $result = (new WhatsAppOAuthService)->exchangeForLongLivedToken('some-short-lived-token');

        $this->assertSame('tok', $result['access_token']);
        $this->assertSame(5184000, $result['expires_in']);
    }

    /**
     * The bug this fix addresses: when Meta's response has no expires_in at
     * all (a non-expiring token), exchangeForLongLivedToken() must return
     * null — never a fabricated 5184000 (~60 day) fallback.
     */
    public function test_exchange_for_long_lived_token_returns_null_expires_in_when_meta_omits_it(): void
    {
        Http::fake(['*/oauth/access_token*' => Http::response(['access_token' => 'never-expiring-token'])]);

        $result = (new WhatsAppOAuthService)->exchangeForLongLivedToken('some-token');

        $this->assertSame('never-expiring-token', $result['access_token']);
        $this->assertNull($result['expires_in'], 'a missing expires_in must never be replaced with a guessed value');
    }

    /**
     * End-to-end: the code exchange itself returns expires_in (a classic
     * short-lived USER token) — exchangeForLongLivedToken() must be called
     * to promote it, and the SECOND call's token/expiry (not the first
     * call's) is what actually gets persisted. Http::sequence() gives two
     * distinct responses to the two calls hitting the same oauth/access_token
     * endpoint so this is provable, not assumed.
     */
    public function test_connect_promotes_to_long_lived_token_when_expires_in_is_present(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $service = new WhatsAppOAuthService;

        Http::fake([
            '*/oauth/access_token*' => Http::sequence()
                ->push(['access_token' => 'short-lived-token', 'expires_in' => 3600])   // exchangeCodeForAccessToken()
                ->push(['access_token' => 'long-lived-token', 'expires_in' => 5184000]), // exchangeForLongLivedToken()
            '*/waba-1/phone_numbers*' => Http::response(['data' => [
                ['id' => 'phone-1', 'display_phone_number' => '+8801700000000', 'verified_name' => 'My Shop', 'quality_rating' => 'GREEN'],
            ]]),
            '*/waba-1/subscribed_apps*' => Http::response(['success' => true]),
        ]);

        $response = $this->actingAs($user, 'tenant')->post(
            $this->panelUrl($tenant, 'whatsapp/connect'),
            $this->completePayload($service, $tenant, $user)
        );

        $response->assertSessionHas('success');

        $account = WhatsAppBusinessAccount::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();
        $this->assertSame('long-lived-token', $account->user_access_token, 'must store the PROMOTED (second call) token, not the short-lived first one');
        $this->assertNotNull($account->token_expires_at);
        $this->assertTrue(
            $account->token_expires_at->betweenIncluded(now()->addSeconds(5184000)->subMinute(), now()->addSeconds(5184000)->addMinute()),
            'token_expires_at must be computed from the long-lived exchange\'s own expires_in (~60 days out)'
        );
    }

    /**
     * End-to-end: the code exchange returns NO expires_in at all — matching
     * a System User / never-expiring token issued directly by this Embedded
     * Signup Configuration. exchangeForLongLivedToken() must NOT be called
     * (the single-entry Http::sequence() below throws if a second request
     * hits the oauth/access_token endpoint — proving this, not just
     * asserting a count), the token is stored as-is, and token_expires_at
     * is NULL.
     */
    public function test_connect_does_not_call_long_lived_exchange_and_stores_null_expiry_when_expires_in_is_absent(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $service = new WhatsAppOAuthService;

        Http::fake([
            // Exactly ONE response queued — a second call to this endpoint
            // (i.e. an unwanted exchangeForLongLivedToken() call) throws
            // OutOfBoundsException from the exhausted sequence rather than
            // silently reusing a response.
            '*/oauth/access_token*' => Http::sequence()
                ->push(['access_token' => 'system-user-token']),
            '*/waba-1/phone_numbers*' => Http::response(['data' => [
                ['id' => 'phone-1', 'display_phone_number' => '+8801700000000', 'verified_name' => 'My Shop', 'quality_rating' => 'GREEN'],
            ]]),
            '*/waba-1/subscribed_apps*' => Http::response(['success' => true]),
        ]);

        $response = $this->actingAs($user, 'tenant')->post(
            $this->panelUrl($tenant, 'whatsapp/connect'),
            $this->completePayload($service, $tenant, $user)
        );

        $response->assertSessionHas('success');

        $account = WhatsAppBusinessAccount::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();
        $this->assertSame('system-user-token', $account->user_access_token, 'the single non-expiring token must be used as-is');
        $this->assertNull($account->token_expires_at, 'a token Meta never said expires must never be recorded with a guessed expiry');
    }

    // --- WhatsApp Business App Coexistence -------------------------------------------------------

    /**
     * The real shape a coexistence completion POST arrives in: settings.blade.php's
     * form builder always includes the phone_number_id hidden input, but its
     * value is '' when signupResult.phoneNumberId is null (FINISH_WHATSAPP_
     * BUSINESS_APP_ONBOARDING never supplies one) — never a genuinely absent key.
     */
    protected function coexistenceCompletePayload(WhatsAppOAuthService $service, Tenant $tenant, $user, array $overrides = []): array
    {
        return $this->completePayload($service, $tenant, $user, array_merge(['phone_number_id' => ''], $overrides));
    }

    public function test_coexistence_completion_without_phone_number_id_discovers_it_via_graph(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $service = new WhatsAppOAuthService;

        Http::fake([
            '*/oauth/access_token*' => Http::response(['access_token' => 'coexistence-token', 'expires_in' => 5184000]),
            '*/waba-coex/phone_numbers*' => Http::response(['data' => [
                ['id' => 'discovered-phone', 'display_phone_number' => '+8801711111111', 'verified_name' => 'Coexistence Shop', 'quality_rating' => 'GREEN'],
            ]]),
            '*/waba-coex/subscribed_apps*' => Http::response(['success' => true]),
        ]);

        $response = $this->actingAs($user, 'tenant')->post(
            $this->panelUrl($tenant, 'whatsapp/connect'),
            $this->coexistenceCompletePayload($service, $tenant, $user, ['waba_id' => 'waba-coex'])
        );

        $response->assertSessionHas('success');

        $account = WhatsAppBusinessAccount::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();
        $this->assertNotNull($account);
        $this->assertSame('waba-coex', $account->waba_id);

        $phone = WhatsAppPhoneNumber::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();
        $this->assertNotNull($phone, 'the phone number must be discovered server-side, never required from the browser for a coexistence completion');
        $this->assertSame('discovered-phone', $phone->phone_number_id);
        $this->assertSame('+8801711111111', $phone->display_phone_number);
        $this->assertSame('active', $phone->status);
    }

    public function test_coexistence_completion_with_zero_phone_numbers_on_waba_is_rejected(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $service = new WhatsAppOAuthService;

        Http::fake([
            '*/oauth/access_token*' => Http::response(['access_token' => 'tok', 'expires_in' => 5184000]),
            '*/waba-empty/phone_numbers*' => Http::response(['data' => []]),
        ]);

        $response = $this->actingAs($user, 'tenant')->post(
            $this->panelUrl($tenant, 'whatsapp/connect'),
            $this->coexistenceCompletePayload($service, $tenant, $user, ['waba_id' => 'waba-empty'])
        );

        $response->assertSessionHas('error');
        $this->assertSame(0, WhatsAppBusinessAccount::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count());
    }

    public function test_coexistence_completion_with_multiple_phone_numbers_on_waba_is_rejected_not_guessed(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $service = new WhatsAppOAuthService;

        Http::fake([
            '*/oauth/access_token*' => Http::response(['access_token' => 'tok', 'expires_in' => 5184000]),
            '*/waba-multi/phone_numbers*' => Http::response(['data' => [
                ['id' => 'phone-x', 'display_phone_number' => '+8801700000001'],
                ['id' => 'phone-y', 'display_phone_number' => '+8801700000002'],
            ]]),
        ]);

        $response = $this->actingAs($user, 'tenant')->post(
            $this->panelUrl($tenant, 'whatsapp/connect'),
            $this->coexistenceCompletePayload($service, $tenant, $user, ['waba_id' => 'waba-multi'])
        );

        $response->assertSessionHas('error');
        $this->assertSame(
            0,
            WhatsAppPhoneNumber::withoutGlobalScopes()->whereIn('phone_number_id', ['phone-x', 'phone-y'])->count(),
            'must never guess which of several numbers to connect'
        );
    }

    public function test_coexistence_discovered_phone_number_already_claimed_by_another_tenant_is_rejected(): void
    {
        $tenantA = $this->makeTenant();
        $userA = $this->makeUser($tenantA->id);
        $accountA = WhatsAppBusinessAccount::withoutGlobalScopes()->create([
            'tenant_id' => $tenantA->id, 'connected_by_user_id' => $userA->id,
            'waba_id' => 'waba-a-coex', 'user_access_token' => 'tok-a',
        ]);
        WhatsAppPhoneNumber::withoutGlobalScopes()->create([
            'tenant_id' => $tenantA->id, 'whatsapp_business_account_id' => $accountA->id,
            'phone_number_id' => 'already-claimed-phone', 'is_active' => 1,
        ]);

        $tenantB = $this->makeTenant();
        $userB = $this->makeUser($tenantB->id);
        $service = new WhatsAppOAuthService;

        Http::fake([
            '*/oauth/access_token*' => Http::response(['access_token' => 'tok-b', 'expires_in' => 5184000]),
            '*/waba-b-coex/phone_numbers*' => Http::response(['data' => [
                ['id' => 'already-claimed-phone', 'display_phone_number' => '+8801799999999'],
            ]]),
        ]);

        $response = $this->actingAs($userB, 'tenant')->post(
            $this->panelUrl($tenantB, 'whatsapp/connect'),
            $this->coexistenceCompletePayload($service, $tenantB, $userB, ['waba_id' => 'waba-b-coex'])
        );

        $response->assertSessionHas('error');
        $this->assertSame(
            $tenantA->id,
            WhatsAppPhoneNumber::withoutGlobalScopes()->where('phone_number_id', 'already-claimed-phone')->first()->tenant_id,
            'server-side-discovered phone numbers must go through the same cross-tenant hijack check as browser-claimed ones'
        );
    }

    /**
     * The actual real-world target scenario: a Coexistence completion whose
     * token exchange also returns no expires_in (e.g. a System User access
     * token) — both fixes must compose correctly together.
     */
    public function test_coexistence_completion_with_never_expiring_token_stores_null_expiry(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $service = new WhatsAppOAuthService;

        Http::fake([
            '*/oauth/access_token*' => Http::sequence()
                ->push(['access_token' => 'system-user-token']), // no expires_in — single response, second call would throw
            '*/waba-su/phone_numbers*' => Http::response(['data' => [
                ['id' => 'su-phone', 'display_phone_number' => '+8801722222222'],
            ]]),
            '*/waba-su/subscribed_apps*' => Http::response(['success' => true]),
        ]);

        $response = $this->actingAs($user, 'tenant')->post(
            $this->panelUrl($tenant, 'whatsapp/connect'),
            $this->coexistenceCompletePayload($service, $tenant, $user, ['waba_id' => 'waba-su'])
        );

        $response->assertSessionHas('success');

        $account = WhatsAppBusinessAccount::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();
        $this->assertSame('system-user-token', $account->user_access_token);
        $this->assertNull($account->token_expires_at, 'a coexistence completion with a never-expiring token must still store NULL, not a guessed expiry');

        $phone = WhatsAppPhoneNumber::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();
        $this->assertSame('su-phone', $phone->phone_number_id);
    }

    // --- cross-tenant read isolation on the Settings page -------------------------------------------

    public function test_settings_page_only_shows_the_current_tenants_own_whatsapp_connection(): void
    {
        $tenantA = $this->makeTenant();
        WhatsAppBusinessAccount::withoutGlobalScopes()->create([
            'tenant_id' => $tenantA->id, 'connected_by_user_id' => $this->makeUser($tenantA->id)->id,
            'waba_id' => 'waba-a-only', 'user_access_token' => 'tok-a',
        ]);

        $tenantB = $this->makeTenant();
        $userB = $this->makeUser($tenantB->id);

        $response = $this->actingAs($userB, 'tenant')->get($this->panelUrl($tenantB, 'settings'));

        $response->assertOk();
        $response->assertDontSee('waba-a-only');
    }
}
