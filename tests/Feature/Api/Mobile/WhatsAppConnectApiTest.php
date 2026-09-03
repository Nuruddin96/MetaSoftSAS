<?php

namespace Tests\Feature\Api\Mobile;

use App\Models\Plan;
use App\Models\WhatsAppBusinessAccount;
use App\Models\WhatsAppPhoneNumber;
use App\Services\WhatsApp\WhatsAppOAuthService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithApiSchema;
use Tests\TestCase;

/**
 * Covers Api\Mobile\WhatsAppConnectController — mirrors
 * Tenant\WhatsAppConnectController exactly, see that controller's and this
 * one's own docblocks. Scenarios ported from the web-side
 * tests/Feature/WhatsApp/WhatsAppConnectionTest.php, adapted for
 * Sanctum/JSON (state minting via connectConfig() instead of Blade
 * data-attributes, complete() returning JSON instead of a redirect+flash).
 */
class WhatsAppConnectApiTest extends TestCase
{
    use InteractsWithApiSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpApiSchema();

        if (! Schema::hasColumn('plans', 'features')) {
            Schema::table('plans', fn (Blueprint $table) => $table->json('features')->nullable());
        }

        if (! Schema::hasTable('whatsapp_oauth_states')) {
            Schema::create('whatsapp_oauth_states', function (Blueprint $table) {
                $table->id();
                $table->string('state', 64)->unique();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('user_id');
                $table->string('purpose', 30)->default('whatsapp');
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
                $table->string('business_id', 64)->nullable();
                $table->string('business_name', 150)->nullable();
                $table->text('user_access_token');
                $table->timestamp('token_expires_at')->nullable();
                $table->string('granted_scopes', 500)->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('whatsapp_phone_numbers')) {
            Schema::create('whatsapp_phone_numbers', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('whatsapp_business_account_id');
                $table->string('phone_number_id', 64)->unique();
                $table->string('display_phone_number', 30)->nullable();
                $table->string('verified_name', 150)->nullable();
                $table->string('quality_rating', 20)->nullable();
                $table->string('status', 30)->default('active');
                $table->boolean('is_active')->default(true);
                $table->timestamp('subscribed_at')->nullable();
                $table->timestamp('disconnected_at')->nullable();
                $table->timestamps();
            });
        }

        // WhatsAppPhoneNumber::tablesReady() checks this table too (all
        // four chunk26.sql tables must exist) even though these tests never
        // create a row in it.
        if (! Schema::hasTable('whatsapp_messages')) {
            Schema::create('whatsapp_messages', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('whatsapp_phone_number_id')->nullable();
                $table->string('wa_id', 30);
                $table->string('direction', 10)->default('in');
                $table->string('status', 20)->default('new');
                $table->timestamp('created_at')->nullable();
            });
        }
    }

    private function whatsappEnabledPlan(): Plan
    {
        return Plan::create(['name' => 'Test Plan', 'slug' => 'test-plan-'.uniqid(), 'features' => ['whatsapp']]);
    }

    private function fakeSuccessfulExchangeAndVerification(string $wabaId = 'waba-1', string $phoneNumberId = 'phone-1'): void
    {
        Http::fake([
            '*/oauth/access_token*' => Http::response(['access_token' => 'long-lived-token', 'expires_in' => 5184000]),
            "*/{$wabaId}/phone_numbers*" => Http::response(['data' => [
                ['id' => $phoneNumberId, 'display_phone_number' => '+8801700000000', 'verified_name' => 'My Shop', 'quality_rating' => 'GREEN'],
            ]]),
            "*/{$wabaId}/subscribed_apps*" => Http::response(['success' => true]),
        ]);
    }

    private function completePayload(WhatsAppOAuthService $service, $tenant, $user, array $overrides = []): array
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

    // --- connect-config ---------------------------------------------------------

    public function test_connect_config_mints_a_state_when_no_full_connection_exists(): void
    {
        $tenant = $this->makeTenant(['plan_id' => $this->whatsappEnabledPlan()->id]);
        $user = $this->makeUser($tenant->id);
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/mobile/v1/settings/whatsapp/connect-config')->assertOk();

        $response->assertJsonPath('ready', true)->assertJsonPath('connected', false);
        $this->assertNotNull($response->json('state'));
    }

    public function test_connect_config_is_gated_behind_the_whatsapp_feature(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        Sanctum::actingAs($user);

        $this->getJson('/api/mobile/v1/settings/whatsapp/connect-config')->assertStatus(403);
    }

    // --- status --------------------------------------------------------------------

    public function test_status_returns_empty_when_nothing_connected(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        Sanctum::actingAs($user);

        $this->getJson('/api/mobile/v1/settings/whatsapp/status')->assertOk()
            ->assertJsonPath('account', null)
            ->assertJsonPath('phones', []);
    }

    // --- valid connection / successful resolution --------------------------------

    public function test_valid_connection_creates_account_and_phone_number(): void
    {
        $tenant = $this->makeTenant(['plan_id' => $this->whatsappEnabledPlan()->id]);
        $user = $this->makeUser($tenant->id);
        $service = new WhatsAppOAuthService;
        $this->fakeSuccessfulExchangeAndVerification();
        Sanctum::actingAs($user);
        app()->instance('currentTenant', $tenant);

        $response = $this->postJson('/api/mobile/v1/settings/whatsapp/complete', $this->completePayload($service, $tenant, $user));

        $response->assertOk()->assertJsonPath('ok', true);

        $account = WhatsAppBusinessAccount::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();
        $this->assertNotNull($account);
        $this->assertSame('waba-1', $account->waba_id);
        $this->assertSame('long-lived-token', $account->user_access_token);

        $phone = WhatsAppPhoneNumber::withoutGlobalScopes()->where('phone_number_id', 'phone-1')->first();
        $this->assertNotNull($phone);
        $this->assertSame('active', $phone->status);
    }

    public function test_complete_response_never_includes_a_token(): void
    {
        $tenant = $this->makeTenant(['plan_id' => $this->whatsappEnabledPlan()->id]);
        $user = $this->makeUser($tenant->id);
        $service = new WhatsAppOAuthService;
        $this->fakeSuccessfulExchangeAndVerification();
        Sanctum::actingAs($user);
        app()->instance('currentTenant', $tenant);

        $response = $this->postJson('/api/mobile/v1/settings/whatsapp/complete', $this->completePayload($service, $tenant, $user));

        $this->assertStringNotContainsString('long-lived-token', $response->getContent());
    }

    // --- state security ------------------------------------------------------------

    public function test_invalid_state_is_rejected_without_creating_any_connection(): void
    {
        $tenant = $this->makeTenant(['plan_id' => $this->whatsappEnabledPlan()->id]);
        $user = $this->makeUser($tenant->id);
        Sanctum::actingAs($user);
        app()->instance('currentTenant', $tenant);

        $this->postJson('/api/mobile/v1/settings/whatsapp/complete', [
            'state' => 'not-a-real-state-token', 'code' => 'code', 'waba_id' => 'waba-1', 'phone_number_id' => 'phone-1',
        ])->assertStatus(422);

        $this->assertSame(0, WhatsAppBusinessAccount::withoutGlobalScopes()->count());
    }

    public function test_reused_state_is_rejected_on_second_submission(): void
    {
        $tenant = $this->makeTenant(['plan_id' => $this->whatsappEnabledPlan()->id]);
        $user = $this->makeUser($tenant->id);
        $service = new WhatsAppOAuthService;
        $this->fakeSuccessfulExchangeAndVerification();
        Sanctum::actingAs($user);
        app()->instance('currentTenant', $tenant);

        $payload = $this->completePayload($service, $tenant, $user);

        $this->postJson('/api/mobile/v1/settings/whatsapp/complete', $payload)->assertOk();
        $this->postJson('/api/mobile/v1/settings/whatsapp/complete', $payload)->assertStatus(422);

        $this->assertSame(1, WhatsAppBusinessAccount::withoutGlobalScopes()->count());
    }

    public function test_tenant_isolation_a_state_minted_for_tenant_a_cannot_connect_a_number_for_tenant_b(): void
    {
        $tenantA = $this->makeTenant(['plan_id' => $this->whatsappEnabledPlan()->id]);
        $userA = $this->makeUser($tenantA->id);
        $tenantB = $this->makeTenant(['plan_id' => $this->whatsappEnabledPlan()->id]);
        $userB = $this->makeUser($tenantB->id);
        $service = new WhatsAppOAuthService;
        $this->fakeSuccessfulExchangeAndVerification();

        $state = $service->currentOrNewState($tenantA, $userA);

        Sanctum::actingAs($userB);
        app()->instance('currentTenant', $tenantB);

        $response = $this->postJson('/api/mobile/v1/settings/whatsapp/complete', [
            'state' => $state->state, 'code' => 'code', 'waba_id' => 'waba-1', 'phone_number_id' => 'phone-1', 'business_id' => 'biz-1',
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, WhatsAppBusinessAccount::withoutGlobalScopes()->where('tenant_id', $tenantB->id)->count());
    }

    // --- WABA/phone verification ----------------------------------------------------

    public function test_waba_phone_mismatch_is_rejected_and_persists_nothing(): void
    {
        $tenant = $this->makeTenant(['plan_id' => $this->whatsappEnabledPlan()->id]);
        $user = $this->makeUser($tenant->id);
        $service = new WhatsAppOAuthService;
        Sanctum::actingAs($user);
        app()->instance('currentTenant', $tenant);

        Http::fake([
            '*/oauth/access_token*' => Http::response(['access_token' => 'long-lived-token', 'expires_in' => 5184000]),
            '*/waba-1/phone_numbers*' => Http::response(['data' => [
                ['id' => 'a-different-real-number', 'display_phone_number' => '+8801999999999'],
            ]]),
        ]);

        $this->postJson('/api/mobile/v1/settings/whatsapp/complete', $this->completePayload($service, $tenant, $user, ['phone_number_id' => 'phone-claimed-but-not-real']))
            ->assertStatus(422);

        $this->assertSame(0, WhatsAppBusinessAccount::withoutGlobalScopes()->count());
    }

    // --- duplicate-connection / cross-tenant hijack protection ----------------------

    public function test_waba_already_claimed_by_another_tenant_is_rejected(): void
    {
        $tenantA = $this->makeTenant();
        $userA = $this->makeUser($tenantA->id);
        WhatsAppBusinessAccount::withoutGlobalScopes()->create([
            'tenant_id' => $tenantA->id, 'connected_by_user_id' => $userA->id,
            'waba_id' => 'shared-waba', 'user_access_token' => 'tok-a',
        ]);

        $tenantB = $this->makeTenant(['plan_id' => $this->whatsappEnabledPlan()->id]);
        $userB = $this->makeUser($tenantB->id);
        $service = new WhatsAppOAuthService;
        $this->fakeSuccessfulExchangeAndVerification('shared-waba', 'phone-b');
        Sanctum::actingAs($userB);
        app()->instance('currentTenant', $tenantB);

        $this->postJson('/api/mobile/v1/settings/whatsapp/complete', $this->completePayload($service, $tenantB, $userB, ['waba_id' => 'shared-waba', 'phone_number_id' => 'phone-b']))
            ->assertStatus(422);

        $this->assertSame(0, WhatsAppBusinessAccount::withoutGlobalScopes()->where('tenant_id', $tenantB->id)->count());
    }

    // --- coexistence (no phone_number_id) --------------------------------------------

    public function test_coexistence_completion_without_phone_number_id_discovers_it_via_graph(): void
    {
        $tenant = $this->makeTenant(['plan_id' => $this->whatsappEnabledPlan()->id]);
        $user = $this->makeUser($tenant->id);
        $service = new WhatsAppOAuthService;
        Sanctum::actingAs($user);
        app()->instance('currentTenant', $tenant);

        Http::fake([
            '*/oauth/access_token*' => Http::response(['access_token' => 'coexistence-token', 'expires_in' => 5184000]),
            '*/waba-coex/phone_numbers*' => Http::response(['data' => [
                ['id' => 'discovered-phone', 'display_phone_number' => '+8801711111111', 'verified_name' => 'Coexistence Shop', 'quality_rating' => 'GREEN'],
            ]]),
            '*/waba-coex/subscribed_apps*' => Http::response(['success' => true]),
        ]);

        $response = $this->postJson('/api/mobile/v1/settings/whatsapp/complete', $this->completePayload($service, $tenant, $user, ['waba_id' => 'waba-coex', 'phone_number_id' => '']));

        $response->assertOk();
        $phone = WhatsAppPhoneNumber::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();
        $this->assertSame('discovered-phone', $phone->phone_number_id);
    }

    public function test_coexistence_completion_with_multiple_phone_numbers_on_waba_is_rejected_not_guessed(): void
    {
        $tenant = $this->makeTenant(['plan_id' => $this->whatsappEnabledPlan()->id]);
        $user = $this->makeUser($tenant->id);
        $service = new WhatsAppOAuthService;
        Sanctum::actingAs($user);
        app()->instance('currentTenant', $tenant);

        Http::fake([
            '*/oauth/access_token*' => Http::response(['access_token' => 'tok', 'expires_in' => 5184000]),
            '*/waba-multi/phone_numbers*' => Http::response(['data' => [
                ['id' => 'phone-x', 'display_phone_number' => '+8801700000001'],
                ['id' => 'phone-y', 'display_phone_number' => '+8801700000002'],
            ]]),
        ]);

        $this->postJson('/api/mobile/v1/settings/whatsapp/complete', $this->completePayload($service, $tenant, $user, ['waba_id' => 'waba-multi', 'phone_number_id' => '']))
            ->assertStatus(422);

        $this->assertSame(0, WhatsAppPhoneNumber::withoutGlobalScopes()->whereIn('phone_number_id', ['phone-x', 'phone-y'])->count());
    }

    // --- disconnect ----------------------------------------------------------------

    public function test_disconnect_preserves_the_account_and_marks_the_number_inactive(): void
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

        Http::fake(['*/subscribed_apps*' => Http::response(['success' => true])]);
        Sanctum::actingAs($user);
        app()->instance('currentTenant', $tenant);

        $this->postJson("/api/mobile/v1/settings/whatsapp/{$phone->id}/disconnect")
            ->assertOk()->assertJsonPath('ok', true);

        $phone->refresh();
        $this->assertFalse($phone->is_active);
        $this->assertNotNull($phone->disconnected_at);
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
        Sanctum::actingAs($userB);
        app()->instance('currentTenant', $tenantB);

        $this->postJson("/api/mobile/v1/settings/whatsapp/{$phoneA->id}/disconnect")->assertStatus(404);
        $this->assertTrue($phoneA->fresh()->is_active);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/mobile/v1/settings/whatsapp/status')->assertUnauthorized();
        $this->getJson('/api/mobile/v1/settings/whatsapp/connect-config')->assertUnauthorized();
        $this->postJson('/api/mobile/v1/settings/whatsapp/complete')->assertUnauthorized();
    }
}
