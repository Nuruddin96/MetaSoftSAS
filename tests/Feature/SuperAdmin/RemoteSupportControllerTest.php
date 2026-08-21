<?php

namespace Tests\Feature\SuperAdmin;

use App\Models\MobileDevice;
use App\Models\RemoteSupportSetting;
use App\Models\RemoteSupportSignal;
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
        $response->assertSee('Live Screen');
        $response->assertSee(route('super.remote-support.session.start', [$tenant, $ready]), escape: false);
        // No approval workflow anywhere on this page — see
        // RemoteSupportService::registerDevice()'s doc comment on why
        // that step was removed entirely, not just hidden.
        $response->assertDontSee('verification_code');
        $response->assertDontSee('অনুমোদন');
    }

    /**
     * The Live Screen action must never simply be MISSING from the row —
     * every non-revoked device shows it, with its enabled/disabled state
     * and reason making clear why it can't be opened yet, rather than the
     * action silently disappearing (which previously looked like the
     * feature wasn't implemented at all when a device was merely offline
     * or not yet ready).
     */
    public function test_offline_and_not_ready_devices_show_a_disabled_live_screen_state_with_a_reason(): void
    {
        $admin = $this->makeSuperAdmin();
        $tenant = $this->makeTenant();
        RemoteSupportSetting::create(['tenant_id' => $tenant->id, 'enabled' => true]);
        $user = $this->makeUser($tenant->id);
        $offline = $this->makeDevice($tenant->id, $user->id, [
            'device_model' => 'Offline Phone', 'status' => 'on_ready', 'remote_support_enabled' => true,
            'last_seen_at' => now()->subMinutes(10),
        ]);
        $notReady = $this->makeDevice($tenant->id, $user->id, [
            'device_model' => 'Not Ready Phone', 'status' => 'on_not_ready', 'remote_support_enabled' => true,
            'last_seen_at' => now(),
        ]);
        $disabled = $this->makeDevice($tenant->id, $user->id, [
            'device_model' => 'Disabled Phone', 'status' => 'off', 'remote_support_enabled' => false,
            'last_seen_at' => now(),
        ]);

        $response = $this->actingAs($admin, 'super_admin')->get(route('super.remote-support.show', $tenant));

        $response->assertOk();
        $response->assertSee('ডিভাইস অফলাইন');
        $response->assertSee('প্রস্তুত হচ্ছে');
        $response->assertSee('লাইভ স্ক্রিন অনুপলব্ধ');

        // None of these three devices should render an actual submittable
        // session-start form — only the status labels above.
        $response->assertDontSee(route('super.remote-support.session.start', [$tenant, $offline]), escape: false);
        $response->assertDontSee(route('super.remote-support.session.start', [$tenant, $notReady]), escape: false);
        $response->assertDontSee(route('super.remote-support.session.start', [$tenant, $disabled]), escape: false);
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

    /**
     * Found while diagnosing a device stuck showing not-ready right after
     * being re-enabled: toggling a device back on used to always reset
     * status to on_not_ready, discarding the fact that its last heartbeat
     * had already reported every precondition satisfied. It now
     * recomputes readiness from that same stored data instead.
     */
    public function test_re_enabling_a_device_recomputes_readiness_instead_of_always_resetting_to_not_ready(): void
    {
        $admin = $this->makeSuperAdmin();
        $tenant = $this->makeTenant();
        RemoteSupportSetting::create(['tenant_id' => $tenant->id, 'enabled' => true]);
        $user = $this->makeUser($tenant->id);
        $device = $this->makeDevice($tenant->id, $user->id, [
            'status' => 'off',
            'remote_support_enabled' => false,
            'foreground_service_running' => true,
            'permissions' => ['notifications' => true, 'battery_optimization_exempt' => true],
        ]);

        $this->actingAs($admin, 'super_admin')
            ->post(route('super.remote-support.devices.toggle', [$tenant, $device]), ['enabled' => '1'])
            ->assertRedirect();

        $this->assertSame('on_ready', $device->fresh()->status);
    }

    public function test_re_enabling_a_device_missing_a_precondition_still_lands_on_not_ready(): void
    {
        $admin = $this->makeSuperAdmin();
        $tenant = $this->makeTenant();
        RemoteSupportSetting::create(['tenant_id' => $tenant->id, 'enabled' => true]);
        $user = $this->makeUser($tenant->id);
        $device = $this->makeDevice($tenant->id, $user->id, [
            'status' => 'off',
            'remote_support_enabled' => false,
            'foreground_service_running' => false,
            'permissions' => ['notifications' => true, 'battery_optimization_exempt' => true],
        ]);

        $this->actingAs($admin, 'super_admin')
            ->post(route('super.remote-support.devices.toggle', [$tenant, $device]), ['enabled' => '1'])
            ->assertRedirect();

        $this->assertSame('on_not_ready', $device->fresh()->status);
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

    /**
     * Verifies the viewer page actually EXPOSES the mic/camera/screen
     * controls (not just that the backend/session model supports them) —
     * and that each control's initial state reflects the device's real,
     * last-heartbeated permission state (mic granted, camera not) rather
     * than a fake "on" for something the device never actually granted.
     */
    public function test_viewer_exposes_real_screen_mic_and_camera_state_for_the_session(): void
    {
        $admin = $this->makeSuperAdmin();
        $tenant = $this->makeTenant(['store_name' => 'Viewer Test Shop']);
        RemoteSupportSetting::create(['tenant_id' => $tenant->id, 'enabled' => true]);
        $user = $this->makeUser($tenant->id);
        $device = $this->makeDevice($tenant->id, $user->id, [
            'device_model' => 'Pixel Viewer Test',
            'status' => 'on_ready',
            'remote_support_enabled' => true,
            'last_seen_at' => now(),
            'permissions' => ['notifications' => true, 'battery_optimization_exempt' => true, 'microphone' => true, 'camera' => false],
        ]);
        $session = DB::table('remote_support_sessions')->insertGetId([
            'tenant_id' => $tenant->id, 'mobile_device_id' => $device->id, 'started_by_super_admin_id' => $admin->id,
            'status' => 'active', 'session_token' => 'viewer-test', 'include_microphone' => true, 'include_camera' => true,
            'started_at' => now(), 'expires_at' => now()->addMinutes(30), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->actingAs($admin, 'super_admin')
            ->get(route('super.remote-support.session.viewer', [$tenant, $device, $session]));

        $response->assertOk();
        $response->assertSee('Viewer Test Shop');
        $response->assertSee('Pixel Viewer Test');
        // Screen: always present, real WebRTC track state driven client-side.
        $response->assertSee('স্ক্রিন');
        $response->assertSee('remoteVideo', escape: false);
        // The URL is embedded via Blade's @json(), which escapes forward
        // slashes — match that same encoding rather than the raw route().
        $response->assertSee(
            str_replace('/', '\/', route('super.remote-support.session.stop', [$tenant, $device, $session])),
            escape: false,
        );

        // Camera requested but device does NOT hold the permission → must
        // show the real "permission required" state; microphone requested
        // AND permitted → must NOT show that same state — asserted by
        // isolating each control's own <p id="...State"> block rather than
        // a page-wide assertSee, so this can't pass by matching the wrong
        // control's text.
        $micBlock = $this->extractBetween($response->getContent(), 'id="micState"', '</p>');
        $cameraBlock = $this->extractBetween($response->getContent(), 'id="cameraState"', '</p>');
        $this->assertStringNotContainsString('ডিভাইসে অনুমতি নেই', $micBlock);
        $this->assertStringContainsString('ডিভাইসে অনুমতি নেই', $cameraBlock);
    }

    /**
     * The Super Admin side of the WebRTC signal relay (sendSignal/
     * pollSignal) had NO coverage at all before this — every existing test
     * either exercised the device side (Api\Mobile\SignalController, see
     * DeviceApiTest) or wrote signal rows directly to the DB, bypassing
     * this controller entirely. That gap is exactly why the
     * SENDER_DEVICE/SENDER_ADMIN mixup in
     * RemoteSupportService::pushSignal() (fixed alongside this test) went
     * unnoticed: nothing ever posted a real admin 'answer' through the
     * actual HTTP endpoint the browser viewer uses.
     */
    public function test_admin_posting_an_answer_signal_flips_the_session_to_connected(): void
    {
        $admin = $this->makeSuperAdmin();
        $tenant = $this->makeTenant();
        RemoteSupportSetting::create(['tenant_id' => $tenant->id, 'enabled' => true]);
        $user = $this->makeUser($tenant->id);
        $device = $this->makeDevice($tenant->id, $user->id, [
            'status' => 'on_ready', 'remote_support_enabled' => true, 'last_seen_at' => now(),
        ]);
        $sessionId = DB::table('remote_support_sessions')->insertGetId([
            'tenant_id' => $tenant->id, 'mobile_device_id' => $device->id, 'started_by_super_admin_id' => $admin->id,
            'status' => 'active', 'session_token' => 'sess-answer', 'started_at' => now(),
            'expires_at' => now()->addMinutes(30), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($admin, 'super_admin')
            ->postJson(route('super.remote-support.session.signal.send', [$tenant, $device, $sessionId]), [
                'type' => 'answer', 'payload' => '{"sdp":"v=0...","type":"answer"}',
            ])->assertCreated();

        $this->assertDatabaseHas('remote_support_signals', [
            'remote_support_session_id' => $sessionId, 'sender' => 'admin', 'type' => 'answer',
        ]);
        $session = DB::table('remote_support_sessions')->find($sessionId);
        $this->assertNotNull($session->connected_at);
        $this->assertDatabaseHas('device_events', [
            'remote_support_session_id' => $sessionId, 'event_type' => 'session_connected', 'actor_type' => 'admin',
        ]);
    }

    /**
     * A device sending its own 'answer' type is not a real flow (the
     * device only ever offers), but pushSignal's connected_at trigger must
     * key off SENDER_ADMIN specifically, not merely `type === 'answer'` —
     * this pins that down as a regression guard for the exact mixup fixed
     * in pushSignal().
     */
    public function test_a_device_sent_answer_signal_does_not_flip_the_session_to_connected(): void
    {
        $admin = $this->makeSuperAdmin();
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $device = $this->makeDevice($tenant->id, $user->id, ['status' => 'on_ready', 'remote_support_enabled' => true]);
        $sessionId = DB::table('remote_support_sessions')->insertGetId([
            'tenant_id' => $tenant->id, 'mobile_device_id' => $device->id, 'started_by_super_admin_id' => $admin->id,
            'status' => 'active', 'session_token' => 'sess-device-answer', 'started_at' => now(),
            'expires_at' => now()->addMinutes(30), 'created_at' => now(), 'updated_at' => now(),
        ]);

        RemoteSupportSignal::create([
            'tenant_id' => $tenant->id, 'remote_support_session_id' => $sessionId,
            'sender' => 'device', 'type' => 'answer', 'payload' => '{}', 'created_at' => now(),
        ]);

        $this->assertNull(DB::table('remote_support_sessions')->find($sessionId)->connected_at);
    }

    /**
     * The viewer's pollLoop only makes sense if it never echoes the
     * admin's own outgoing signals back to itself — pollSignals() must
     * filter to the opposite sender.
     */
    public function test_admin_polling_signals_only_returns_device_sent_signals(): void
    {
        $admin = $this->makeSuperAdmin();
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $device = $this->makeDevice($tenant->id, $user->id, ['status' => 'on_ready', 'remote_support_enabled' => true]);
        $sessionId = DB::table('remote_support_sessions')->insertGetId([
            'tenant_id' => $tenant->id, 'mobile_device_id' => $device->id, 'started_by_super_admin_id' => $admin->id,
            'status' => 'active', 'session_token' => 'sess-poll', 'started_at' => now(),
            'expires_at' => now()->addMinutes(30), 'created_at' => now(), 'updated_at' => now(),
        ]);
        RemoteSupportSignal::create([
            'tenant_id' => $tenant->id, 'remote_support_session_id' => $sessionId,
            'sender' => 'device', 'type' => 'offer', 'payload' => '{"sdp":"...","type":"offer"}', 'created_at' => now(),
        ]);
        RemoteSupportSignal::create([
            'tenant_id' => $tenant->id, 'remote_support_session_id' => $sessionId,
            'sender' => 'admin', 'type' => 'ice-candidate', 'payload' => '{}', 'created_at' => now(),
        ]);

        $response = $this->actingAs($admin, 'super_admin')
            ->getJson(route('super.remote-support.session.signal.poll', [$tenant, $device, $sessionId]).'?since=0')
            ->assertOk();

        $this->assertCount(1, $response->json('signals'));
        $this->assertSame('offer', $response->json('signals.0.type'));
    }

    private function extractBetween(string $haystack, string $start, string $end): string
    {
        $startPos = strpos($haystack, $start);
        $endPos = strpos($haystack, $end, $startPos);

        return substr($haystack, $startPos, $endPos - $startPos);
    }
}
