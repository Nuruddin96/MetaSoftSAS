<?php

namespace Tests\Feature\SuperAdmin;

use App\Models\MobileDevice;
use App\Models\RemoteSupportSetting;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithRemoteSupportSchema;
use Tests\TestCase;

class RemoteSupportControllerTest extends TestCase
{
    use InteractsWithRemoteSupportSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRemoteSupportSchema();
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    protected function makeDevice(int $tenantId, int $userId, array $attrs = []): MobileDevice
    {
        return MobileDevice::create(array_merge([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'device_uuid' => 'dev-'.uniqid(),
            'status' => 'off',
        ], $attrs));
    }

    public function test_guest_is_redirected_away_from_the_console(): void
    {
        $tenant = $this->makeTenant();

        $this->get(route('super.remote-support.show', $tenant))->assertRedirect();
    }

    /**
     * Renders the ACTUAL shared Super Admin layout (layouts/super.blade.php)
     * that every other Super Admin page uses — the nav item lives there,
     * not in this feature's own view, so this is the real integration
     * check for "I cannot see any Remote Support option in the Super Admin
     * dashboard": it proves the menu link is actually present in the
     * rendered HTML a logged-in Super Admin's browser receives, not just
     * that the route exists in isolation.
     */
    public function test_the_remote_support_menu_item_is_visible_to_a_logged_in_super_admin(): void
    {
        $admin = $this->makeSuperAdmin();

        $response = $this->actingAs($admin, 'super_admin')->get(route('super.remote-support.index'));

        $response->assertOk();
        $response->assertSee('রিমোট সাপোর্ট');
        $response->assertSee(route('super.remote-support.index'), escape: false);
    }

    public function test_index_lists_tenants_with_their_remote_support_status(): void
    {
        $admin = $this->makeSuperAdmin();
        $enabledTenant = $this->makeTenant(['store_name' => 'Enabled Shop']);
        RemoteSupportSetting::create(['tenant_id' => $enabledTenant->id, 'enabled' => true]);
        $disabledTenant = $this->makeTenant(['store_name' => 'Disabled Shop']);

        $response = $this->actingAs($admin, 'super_admin')->get(route('super.remote-support.index'));

        $response->assertOk();
        $response->assertSee('Enabled Shop');
        $response->assertSee('Disabled Shop');
    }

    public function test_show_page_surfaces_the_open_live_screen_action_for_a_ready_device(): void
    {
        $admin = $this->makeSuperAdmin();
        $tenant = $this->makeTenant();
        RemoteSupportSetting::create(['tenant_id' => $tenant->id, 'enabled' => true]);
        $user = $this->makeUser($tenant->id);
        $ready = $this->makeDevice($tenant->id, $user->id, [
            'device_model' => 'Samsung A14', 'status' => 'on_ready', 'remote_support_enabled' => true, 'last_seen_at' => now(),
        ]);

        $response = $this->actingAs($admin, 'super_admin')->get(route('super.remote-support.show', $tenant));

        $response->assertOk();
        $response->assertSee('Samsung A14');
        $response->assertSee('লাইভ স্ক্রিন দেখুন');
        $response->assertSee(route('super.remote-support.session.start', [$tenant, $ready]), escape: false);
        // No approval workflow anywhere on this page — see
        // RemoteSupportService::registerDevice()'s doc comment on why
        // that step was removed entirely, not just hidden.
        $response->assertDontSee('verification_code');
        $response->assertDontSee('অনুমোদন');
    }

    public function test_super_admin_can_enable_remote_support_for_a_tenant(): void
    {
        $admin = $this->makeSuperAdmin();
        $tenant = $this->makeTenant();

        $this->actingAs($admin, 'super_admin')
            ->post(route('super.remote-support.toggle', $tenant), ['enabled' => '1'])
            ->assertRedirect();

        $this->assertDatabaseHas('remote_support_settings', ['tenant_id' => $tenant->id, 'enabled' => 1]);
        $this->assertTrue($tenant->fresh()->hasRemoteSupportEnabled());
    }

    public function test_super_admin_can_disable_remote_support_after_enabling(): void
    {
        $admin = $this->makeSuperAdmin();
        $tenant = $this->makeTenant();
        RemoteSupportSetting::create(['tenant_id' => $tenant->id, 'enabled' => true]);

        $this->actingAs($admin, 'super_admin')
            ->post(route('super.remote-support.toggle', $tenant), ['enabled' => '0'])
            ->assertRedirect();

        $this->assertFalse($tenant->fresh()->hasRemoteSupportEnabled());
    }

    public function test_revoking_a_device_ends_any_open_session_and_deletes_its_credential(): void
    {
        $admin = $this->makeSuperAdmin();
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $tokenId = $user->createToken('device:x', ['device:heartbeat'])->accessToken->id;
        $device = $this->makeDevice($tenant->id, $user->id, [
            'status' => 'on_ready',
            'remote_support_enabled' => true,
            'last_seen_at' => now(),
            'credential_token_id' => $tokenId,
        ]);
        DB::table('remote_support_sessions')->insert([
            'tenant_id' => $tenant->id, 'mobile_device_id' => $device->id, 'started_by_super_admin_id' => $admin->id,
            'status' => 'active', 'session_token' => 'tok-abc', 'started_at' => now(), 'expires_at' => now()->addMinutes(30),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($admin, 'super_admin')
            ->post(route('super.remote-support.devices.revoke', [$tenant, $device]), ['reason' => 'lost phone'])
            ->assertRedirect();

        $fresh = $device->fresh();
        $this->assertSame('revoked', $fresh->status);
        $this->assertFalse((bool) $fresh->remote_support_enabled);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $tokenId]);
        $this->assertDatabaseHas('remote_support_sessions', ['mobile_device_id' => $device->id, 'status' => 'ended']);
    }

    public function test_starting_a_session_fails_when_device_is_not_ready(): void
    {
        $admin = $this->makeSuperAdmin();
        $tenant = $this->makeTenant();
        RemoteSupportSetting::create(['tenant_id' => $tenant->id, 'enabled' => true]);
        $user = $this->makeUser($tenant->id);
        $device = $this->makeDevice($tenant->id, $user->id, [
            'status' => 'on_not_ready',
            'remote_support_enabled' => true,
            'last_seen_at' => now(),
        ]);

        $this->actingAs($admin, 'super_admin')
            ->post(route('super.remote-support.session.start', [$tenant, $device]), [])
            ->assertStatus(409);

        $this->assertDatabaseCount('remote_support_sessions', 0);
    }

    public function test_starting_a_session_fails_when_tenant_level_remote_support_is_disabled(): void
    {
        $admin = $this->makeSuperAdmin();
        $tenant = $this->makeTenant();
        // No RemoteSupportSetting row at all == disabled by default.
        $user = $this->makeUser($tenant->id);
        $device = $this->makeDevice($tenant->id, $user->id, [
            'status' => 'on_ready',
            'remote_support_enabled' => true,
            'last_seen_at' => now(),
        ]);

        $this->actingAs($admin, 'super_admin')
            ->post(route('super.remote-support.session.start', [$tenant, $device]), [])
            ->assertStatus(409);
    }

    public function test_super_admin_can_start_a_session_on_a_ready_eligible_device(): void
    {
        $admin = $this->makeSuperAdmin();
        $tenant = $this->makeTenant();
        RemoteSupportSetting::create(['tenant_id' => $tenant->id, 'enabled' => true]);
        $user = $this->makeUser($tenant->id);
        $device = $this->makeDevice($tenant->id, $user->id, [
            'status' => 'on_ready',
            'remote_support_enabled' => true,
            'last_seen_at' => now(),
        ]);

        $this->actingAs($admin, 'super_admin')
            ->post(route('super.remote-support.session.start', [$tenant, $device]), ['include_microphone' => '1'])
            ->assertRedirect();

        $this->assertDatabaseHas('remote_support_sessions', [
            'mobile_device_id' => $device->id, 'status' => 'active', 'include_microphone' => 1,
        ]);
        $this->assertDatabaseHas('device_events', ['mobile_device_id' => $device->id, 'event_type' => 'session_started']);
    }

    public function test_a_second_session_is_rejected_while_the_first_is_still_open(): void
    {
        $admin = $this->makeSuperAdmin();
        $tenant = $this->makeTenant();
        RemoteSupportSetting::create(['tenant_id' => $tenant->id, 'enabled' => true]);
        $user = $this->makeUser($tenant->id);
        $device = $this->makeDevice($tenant->id, $user->id, [
            'status' => 'on_ready', 'remote_support_enabled' => true, 'last_seen_at' => now(),
        ]);
        DB::table('remote_support_sessions')->insert([
            'tenant_id' => $tenant->id, 'mobile_device_id' => $device->id, 'started_by_super_admin_id' => $admin->id,
            'status' => 'active', 'session_token' => 'still-open', 'started_at' => now(),
            'expires_at' => now()->addMinutes(30), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($admin, 'super_admin')
            ->post(route('super.remote-support.session.start', [$tenant, $device]), [])
            ->assertStatus(409);

        $this->assertDatabaseCount('remote_support_sessions', 1);
    }

    public function test_an_abandoned_expired_session_self_heals_and_does_not_block_a_new_one(): void
    {
        $admin = $this->makeSuperAdmin();
        $tenant = $this->makeTenant();
        RemoteSupportSetting::create(['tenant_id' => $tenant->id, 'enabled' => true]);
        $user = $this->makeUser($tenant->id);
        $device = $this->makeDevice($tenant->id, $user->id, [
            'status' => 'on_ready', 'remote_support_enabled' => true, 'last_seen_at' => now(),
        ]);
        // Never explicitly stopped (e.g. the admin closed the browser tab
        // mid-session) — still `active` in the DB, but its hard cap has
        // already passed.
        DB::table('remote_support_sessions')->insert([
            'tenant_id' => $tenant->id, 'mobile_device_id' => $device->id, 'started_by_super_admin_id' => $admin->id,
            'status' => 'active', 'session_token' => 'abandoned', 'started_at' => now()->subHour(),
            'expires_at' => now()->subMinutes(5), 'created_at' => now()->subHour(), 'updated_at' => now()->subHour(),
        ]);

        $this->actingAs($admin, 'super_admin')
            ->post(route('super.remote-support.session.start', [$tenant, $device]), [])
            ->assertRedirect();

        $this->assertDatabaseHas('remote_support_sessions', ['session_token' => 'abandoned', 'status' => 'ended', 'end_reason' => 'expired']);
        $this->assertDatabaseHas('remote_support_sessions', ['mobile_device_id' => $device->id, 'status' => 'active']);
        $this->assertDatabaseCount('remote_support_sessions', 2);
    }

    public function test_a_device_stuck_offline_is_not_eligible_even_if_status_column_says_on_ready(): void
    {
        $tenant = $this->makeTenant();
        RemoteSupportSetting::create(['tenant_id' => $tenant->id, 'enabled' => true]);
        $user = $this->makeUser($tenant->id);
        $device = $this->makeDevice($tenant->id, $user->id, [
            'status' => 'on_ready',
            'remote_support_enabled' => true,
            'last_seen_at' => now()->subMinutes(10), // stale heartbeat
        ]);

        $this->assertSame('offline', $device->liveStatus());
        $this->assertFalse($device->isEligibleForSession());
    }
}
