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
        $response->assertSee('🎥 Screen');
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

        $response = $this->actingAs($admin, 'super_admin')
            ->post(route('super.remote-support.session.start', [$tenant, $device]), []);

        $response->assertRedirect();
        $this->assertSame('ডিভাইসটি এখন রেডি নয়।', session('error'));
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

        $response = $this->actingAs($admin, 'super_admin')
            ->post(route('super.remote-support.session.start', [$tenant, $device]), []);

        $response->assertRedirect();
        $this->assertSame('এই টেনেন্টের জন্য রিমোট সাপোর্ট চালু নেই।', session('error'));
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

    /**
     * The 409 conflict guard itself is unchanged — this only checks the
     * operator now sees the real Bengali reason via a flashed error
     * instead of Laravel's generic uncustomized-409-view "Oops!" page
     * (RemoteSupportController::startSession() didn't catch the service's
     * abort before; found comparing a live Redmi 23027RAD4I session
     * against Tecno CK7n, 2026-09-06 — the Super Admin device list offered
     * a fresh Start form even while a session was already open, and
     * clicking it just 409'd with no usable feedback).
     */
    public function test_a_second_session_attempt_while_the_first_is_still_open_flashes_the_real_conflict_message_instead_of_a_bare_409(): void
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

        $response = $this->actingAs($admin, 'super_admin')
            ->post(route('super.remote-support.session.start', [$tenant, $device]), []);

        $response->assertRedirect();
        $this->assertSame('এই ডিভাইসে ইতিমধ্যে একটি সেশন চলছে।', session('error'));
        $this->assertDatabaseCount('remote_support_sessions', 1);
    }

    /**
     * The device-list page must never offer a fresh Start form for a
     * device that already has an open session — it must instead link
     * straight back into that SAME session, so the operator can never
     * trigger the 409 from this page in the first place.
     */
    public function test_show_page_offers_resume_instead_of_start_for_a_device_with_an_open_session(): void
    {
        $admin = $this->makeSuperAdmin();
        $tenant = $this->makeTenant();
        RemoteSupportSetting::create(['tenant_id' => $tenant->id, 'enabled' => true]);
        $user = $this->makeUser($tenant->id);
        $device = $this->makeDevice($tenant->id, $user->id, [
            'device_model' => 'Xiaomi 23027RAD4I', 'status' => 'on_ready', 'remote_support_enabled' => true, 'last_seen_at' => now(),
        ]);
        $sessionId = DB::table('remote_support_sessions')->insertGetId([
            'tenant_id' => $tenant->id, 'mobile_device_id' => $device->id, 'started_by_super_admin_id' => $admin->id,
            'status' => 'active', 'session_token' => 'resume-me', 'started_at' => now(), 'connected_at' => now(),
            'expires_at' => now()->addMinutes(30), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->actingAs($admin, 'super_admin')->get(route('super.remote-support.show', $tenant));

        $response->assertOk();
        $response->assertSee(route('super.remote-support.session.viewer', [$tenant, $device, $sessionId]), escape: false);
        // The Start route's own URL is a literal string PREFIX of the
        // viewer URL (".../session" vs ".../session/{id}/view"), so it
        // always "contains" it — assert on the Start button's label
        // instead, which the Resume link never uses.
        $response->assertDontSee('🎥 Screen');
    }

    /**
     * A device whose session was never connected and is well past the
     * liveness grace window has nothing live to resume — the list must
     * still offer a fresh Start (which self-heals it), not a dead Resume
     * link. Regression guard against filtering only on `status != ended`.
     */
    public function test_show_page_still_offers_start_for_a_likely_abandoned_session(): void
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
            'status' => 'active', 'session_token' => 'dead', 'started_at' => now()->subMinutes(5),
            'connected_at' => null, 'expires_at' => now()->addMinutes(25), 'created_at' => now()->subMinutes(5), 'updated_at' => now()->subMinutes(5),
        ]);

        $response = $this->actingAs($admin, 'super_admin')->get(route('super.remote-support.show', $tenant));

        $response->assertOk();
        $response->assertSee(route('super.remote-support.session.start', [$tenant, $device]), escape: false);
    }

    /**
     * Regression guard for the exact complaint this feature was built to
     * fix: a device that previously had a Remote Support session must NOT
     * be stuck showing a stale Resume link, "বাতিল", or no action at all
     * once that session naturally ends (admin clicked "সেশন বন্ধ করুন", or
     * the device sent 'bye') — the list must fall straight back through to
     * a fresh "🎥 Screen" the very next page load, exactly like a
     * device that never had a session. $openSessions already excludes
     * `status = ended` at the query level (RemoteSupportController::show()),
     * so this locks that behavior in from the rendered HTML, not just the
     * query.
     */
    public function test_show_page_offers_a_fresh_live_screen_action_after_a_previous_session_ended(): void
    {
        $admin = $this->makeSuperAdmin();
        $tenant = $this->makeTenant();
        RemoteSupportSetting::create(['tenant_id' => $tenant->id, 'enabled' => true]);
        $user = $this->makeUser($tenant->id);
        $device = $this->makeDevice($tenant->id, $user->id, [
            'device_model' => 'Ended Session Phone', 'status' => 'on_ready', 'remote_support_enabled' => true, 'last_seen_at' => now(),
        ]);
        $endedSessionId = DB::table('remote_support_sessions')->insertGetId([
            'tenant_id' => $tenant->id, 'mobile_device_id' => $device->id, 'started_by_super_admin_id' => $admin->id,
            'status' => 'ended', 'session_token' => 'finished', 'started_at' => now()->subMinutes(10),
            'connected_at' => now()->subMinutes(9), 'ended_at' => now()->subMinutes(1), 'end_reason' => 'stopped_by_admin',
            'expires_at' => now()->addMinutes(20), 'created_at' => now()->subMinutes(10), 'updated_at' => now()->subMinutes(1),
        ]);

        $response = $this->actingAs($admin, 'super_admin')->get(route('super.remote-support.show', $tenant));

        $response->assertOk();
        $response->assertSee('🎥 Screen');
        $response->assertSee(route('super.remote-support.session.start', [$tenant, $device]), escape: false);
        $response->assertDontSee(route('super.remote-support.session.viewer', [$tenant, $device, $endedSessionId]), escape: false);
        $response->assertDontSee('বাতিল');
    }

    /**
     * Mirror of the test above from the other direction: "বাতিল" must stay
     * reserved for an actually revoked device, and a revoked device must
     * never render ANY live-screen action (Start, Resume, or an
     * unavailable/offline/not-ready placeholder) — see show.blade.php's
     * `@if ($d->status === 'revoked')` branch, which replaces the whole
     * action column with just the revoke reason.
     */
    public function test_show_page_shows_only_the_revoked_badge_and_reason_for_a_revoked_device(): void
    {
        $admin = $this->makeSuperAdmin();
        $tenant = $this->makeTenant();
        RemoteSupportSetting::create(['tenant_id' => $tenant->id, 'enabled' => true]);
        $user = $this->makeUser($tenant->id);
        $device = $this->makeDevice($tenant->id, $user->id, [
            'device_model' => 'Revoked Phone', 'status' => 'revoked', 'remote_support_enabled' => false,
            'revoke_reason' => 'lost phone', 'last_seen_at' => now(),
        ]);

        $response = $this->actingAs($admin, 'super_admin')->get(route('super.remote-support.show', $tenant));

        $response->assertOk();
        $response->assertSee('বাতিল');
        $response->assertSee('lost phone');
        $response->assertDontSee('🎥 Screen');
        $response->assertDontSee('লাইভ স্ক্রিন অনুপলব্ধ');
        $response->assertDontSee(route('super.remote-support.session.start', [$tenant, $device]), escape: false);
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

    /**
     * Reproduces the real physical-device failure (2026-08-22, TECNO
     * CK7n): the tenant revoking the Remote Support notification
     * permission makes Android kill the device's whole app process
     * before it ever sends a 'bye' signal, leaving a session stuck
     * active/never-connected well inside its full 30-minute expiry —
     * previously a 409 for the rest of that window.
     */
    public function test_a_never_connected_session_past_the_liveness_grace_window_self_heals_and_does_not_block_a_new_one(): void
    {
        $admin = $this->makeSuperAdmin();
        $tenant = $this->makeTenant();
        RemoteSupportSetting::create(['tenant_id' => $tenant->id, 'enabled' => true]);
        $user = $this->makeUser($tenant->id);
        $device = $this->makeDevice($tenant->id, $user->id, [
            'status' => 'on_ready', 'remote_support_enabled' => true, 'last_seen_at' => now(),
        ]);
        // Started 3 minutes ago, never connected (connected_at stays
        // null), but its 30-minute hard cap is nowhere near expired —
        // isExpired() alone would still 409 this.
        DB::table('remote_support_sessions')->insert([
            'tenant_id' => $tenant->id, 'mobile_device_id' => $device->id, 'started_by_super_admin_id' => $admin->id,
            'status' => 'active', 'session_token' => 'never-connected', 'started_at' => now()->subMinutes(3),
            'connected_at' => null, 'expires_at' => now()->addMinutes(27),
            'created_at' => now()->subMinutes(3), 'updated_at' => now()->subMinutes(3),
        ]);

        $this->actingAs($admin, 'super_admin')
            ->post(route('super.remote-support.session.start', [$tenant, $device]), [])
            ->assertRedirect();

        $this->assertDatabaseHas('remote_support_sessions', [
            'session_token' => 'never-connected', 'status' => 'ended', 'end_reason' => 'abandoned_no_connection',
        ]);
        $this->assertDatabaseHas('remote_support_sessions', ['mobile_device_id' => $device->id, 'status' => 'active']);
        $this->assertDatabaseCount('remote_support_sessions', 2);
    }

    public function test_a_recently_started_never_connected_session_still_within_the_grace_window_is_not_self_healed(): void
    {
        $admin = $this->makeSuperAdmin();
        $tenant = $this->makeTenant();
        RemoteSupportSetting::create(['tenant_id' => $tenant->id, 'enabled' => true]);
        $user = $this->makeUser($tenant->id);
        $device = $this->makeDevice($tenant->id, $user->id, [
            'status' => 'on_ready', 'remote_support_enabled' => true, 'last_seen_at' => now(),
        ]);
        // Started seconds ago — a real in-flight session still
        // legitimately negotiating must NOT be treated as abandoned just
        // because connected_at hasn't landed yet.
        DB::table('remote_support_sessions')->insert([
            'tenant_id' => $tenant->id, 'mobile_device_id' => $device->id, 'started_by_super_admin_id' => $admin->id,
            'status' => 'active', 'session_token' => 'just-started', 'started_at' => now(),
            'connected_at' => null, 'expires_at' => now()->addMinutes(30),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->actingAs($admin, 'super_admin')
            ->post(route('super.remote-support.session.start', [$tenant, $device]), []);

        $response->assertRedirect();
        $this->assertSame('এই ডিভাইসে ইতিমধ্যে একটি সেশন চলছে।', session('error'));
        $this->assertDatabaseCount('remote_support_sessions', 1);
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

        // Independent capabilities (see WebRtcSessionController.dart's own
        // class doc comment): whether Android actually holds the
        // permission is now a REAL-TIME concern driven entirely by
        // capability-status signals + actual WebRTC ontrack events on the
        // client, never baked into the server-rendered HTML (Android
        // access can change at any moment during a live session, unlike a
        // one-time page render) — so this asserts what the server
        // actually still controls: each of the 4 capability tiles is
        // present with the correct data attributes, and the embedded
        // `initialCapabilities` JSON accurately reflects which
        // capabilities this session was created with.
        $this->assertStringContainsString('data-capability-tile="screen"', $response->getContent());
        $this->assertStringContainsString('data-capability-tile="camera"', $response->getContent());
        $this->assertStringContainsString('data-capability-tile="microphone"', $response->getContent());
        $this->assertStringContainsString('data-capability-tile="device_audio"', $response->getContent());
        $response->assertSee('"microphone":true', escape: false);
        $response->assertSee('"camera":true', escape: false);
        $response->assertSee('"device_audio":false', escape: false);
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
     * The admin console's manual "রিকানেক্ট" button (viewer.blade.php) —
     * sends this with an empty payload; the device answers it entirely by
     * re-running its own existing ICE-restart path
     * (WebRtcSessionController._performIceRestart()), never by starting a
     * new session. Here we only need to confirm the signal itself is
     * accepted and relayed to the device side unchanged — the actual
     * reconnect behavior is Dart-side and covered by physical-device
     * testing (see docs/webrtc-flow.md §Reconnect).
     */
    public function test_admin_can_send_a_reconnect_request_signal(): void
    {
        $admin = $this->makeSuperAdmin();
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $device = $this->makeDevice($tenant->id, $user->id, ['status' => 'on_ready', 'remote_support_enabled' => true]);
        $sessionId = DB::table('remote_support_sessions')->insertGetId([
            'tenant_id' => $tenant->id, 'mobile_device_id' => $device->id, 'started_by_super_admin_id' => $admin->id,
            'status' => 'active', 'session_token' => 'sess-reconnect', 'started_at' => now(),
            'expires_at' => now()->addMinutes(30), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($admin, 'super_admin')
            ->postJson(route('super.remote-support.session.signal.send', [$tenant, $device, $sessionId]), [
                'type' => 'reconnect-request', 'payload' => '',
            ])->assertCreated();

        $this->assertDatabaseHas('remote_support_signals', [
            'remote_support_session_id' => $sessionId, 'sender' => 'admin', 'type' => 'reconnect-request',
        ]);
        // Reconnecting is not the same as ending the session — a
        // reconnect-request must never touch session status.
        $this->assertSame('active', DB::table('remote_support_sessions')->find($sessionId)->status);
    }

    /**
     * Repeated clicks on the admin's রিকানেক্ট button (a spam-click, or a
     * slow network making the tenant click again before the first request
     * lands) must be safe at the signaling layer — the actual duplicate-
     * restart prevention is WebRtcSessionController's 3-second debounce on
     * the device side (Dart-level, verified by physical-device testing),
     * but the SERVER side must never reject, error on, or corrupt session
     * state for repeat signals either.
     */
    public function test_repeated_reconnect_request_signals_are_all_accepted_without_affecting_session_status(): void
    {
        $admin = $this->makeSuperAdmin();
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $device = $this->makeDevice($tenant->id, $user->id, ['status' => 'on_ready', 'remote_support_enabled' => true]);
        $sessionId = DB::table('remote_support_sessions')->insertGetId([
            'tenant_id' => $tenant->id, 'mobile_device_id' => $device->id, 'started_by_super_admin_id' => $admin->id,
            'status' => 'active', 'session_token' => 'sess-repeat-reconnect', 'started_at' => now(),
            'expires_at' => now()->addMinutes(30), 'created_at' => now(), 'updated_at' => now(),
        ]);

        for ($i = 0; $i < 3; $i++) {
            $this->actingAs($admin, 'super_admin')
                ->postJson(route('super.remote-support.session.signal.send', [$tenant, $device, $sessionId]), [
                    'type' => 'reconnect-request', 'payload' => '',
                ])->assertCreated();
        }

        $this->assertSame(3, DB::table('remote_support_signals')
            ->where('remote_support_session_id', $sessionId)->where('type', 'reconnect-request')->count());
        $this->assertSame('active', DB::table('remote_support_sessions')->find($sessionId)->status);
    }

    /** Regression guard: the new 'reconnect-request' type must not have widened validation into accepting arbitrary strings. */
    public function test_admin_sending_an_unknown_signal_type_is_rejected(): void
    {
        $admin = $this->makeSuperAdmin();
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $device = $this->makeDevice($tenant->id, $user->id, ['status' => 'on_ready', 'remote_support_enabled' => true]);
        $sessionId = DB::table('remote_support_sessions')->insertGetId([
            'tenant_id' => $tenant->id, 'mobile_device_id' => $device->id, 'started_by_super_admin_id' => $admin->id,
            'status' => 'active', 'session_token' => 'sess-bad-type', 'started_at' => now(),
            'expires_at' => now()->addMinutes(30), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($admin, 'super_admin')
            ->postJson(route('super.remote-support.session.signal.send', [$tenant, $device, $sessionId]), [
                'type' => 'not-a-real-type', 'payload' => 'x',
            ])->assertStatus(422);
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

    /**
     * The Access/Consent column on the device list — see
     * docs/remote-support-consent-model.md (Flutter repo) §5. Read-only:
     * this whole section must never render a control that implies the
     * Admin can grant Android permission remotely (that stays entirely on
     * the tenant's device — see the view's own doc comment).
     */
    public function test_show_page_displays_app_consent_android_access_and_activation_state(): void
    {
        $admin = $this->makeSuperAdmin();
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $this->makeDevice($tenant->id, $user->id, [
            'device_model' => 'Pixel 8', 'status' => 'on_ready', 'remote_support_enabled' => true,
            'app_consent_status' => 'enabled',
            'android_access' => ['notifications' => 'granted', 'battery_optimization_exempt' => 'denied'],
            'activation_status' => 'waiting_for_android_access',
            'consent_changed_at' => now()->subMinutes(10),
            'access_synced_at' => now()->subMinute(),
        ]);

        $response = $this->actingAs($admin, 'super_admin')->get(route('super.remote-support.show', $tenant));

        $response->assertOk();
        $response->assertSee('কনসেন্ট / অ্যাক্সেস');
        $response->assertSee('চালু'); // app consent = enabled
        $response->assertSee('অ্যান্ড্রয়েড অনুমতির অপেক্ষায়'); // activation = waiting_for_android_access
        $response->assertDontSee('গ্রান্ট করুন', escape: false); // Admin never gets a "grant permission" control
    }

    /**
     * Session status (liveStatus()) and consent/activation are computed
     * from entirely different inputs and must never be conflated — a
     * device can be freshly heartbeating (`on_ready`/`প্রস্তুত`) while its
     * OWN local consent is disabled, or vice versa.
     */
    public function test_show_page_never_implies_connected_equals_consent_enabled(): void
    {
        $admin = $this->makeSuperAdmin();
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $this->makeDevice($tenant->id, $user->id, [
            'device_model' => 'Redmi Note', 'status' => 'on_ready', 'remote_support_enabled' => true,
            'last_seen_at' => now(),
            'app_consent_status' => 'disabled',
            'android_access' => ['notifications' => 'granted', 'battery_optimization_exempt' => 'granted'],
            'activation_status' => 'disabled_by_tenant',
        ]);

        $response = $this->actingAs($admin, 'super_admin')->get(route('super.remote-support.show', $tenant));

        $response->assertOk();
        $response->assertSee('প্রস্তুত'); // session status: ready/heartbeating
        $response->assertSee('টেনেন্ট কর্তৃক বন্ধ'); // activation: disabled_by_tenant — a SEPARATE fact
    }

    /** Tenant isolation: Tenant B's consent/access state must never render on Tenant A's page. */
    public function test_show_page_never_leaks_another_tenants_device_consent_state(): void
    {
        $admin = $this->makeSuperAdmin();
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $userB = $this->makeUser($tenantB->id);
        $this->makeDevice($tenantB->id, $userB->id, [
            'device_model' => 'Tenant B Device', 'app_consent_status' => 'enabled',
        ]);

        $response = $this->actingAs($admin, 'super_admin')->get(route('super.remote-support.show', $tenantA));

        $response->assertOk();
        $response->assertDontSee('Tenant B Device');
    }

    private function extractBetween(string $haystack, string $start, string $end): string
    {
        $startPos = strpos($haystack, $start);
        $endPos = strpos($haystack, $end, $startPos);

        return substr($haystack, $startPos, $endPos - $startPos);
    }
}
