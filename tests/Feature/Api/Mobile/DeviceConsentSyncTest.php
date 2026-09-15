<?php

namespace Tests\Feature\Api\Mobile;

use App\Models\DeviceEvent;
use App\Models\MobileDevice;
use Tests\Concerns\InteractsWithRemoteSupportSchema;
use Tests\TestCase;

/**
 * The two-layer app-consent/Android-access sync endpoint
 * (devices/consent-sync) — see DeviceController::syncConsent() and
 * RemoteSupportService::syncConsentState(). Purely informational for the
 * Admin Dashboard; never an eligibility input — see
 * DeviceApiTest::test_heartbeat_updates_presence_and_flips_status_to_on_ready_once_preconditions_are_met
 * for the actual on_ready gate, entirely untouched here.
 */
class DeviceConsentSyncTest extends TestCase
{
    use InteractsWithRemoteSupportSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRemoteSupportSchema();
    }

    private function makeDeviceWithToken(): array
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $uuid = 'uuid-'.uniqid();
        $token = $user->createToken('device:'.$uuid, ['device:heartbeat']);
        $device = MobileDevice::create([
            'tenant_id' => $tenant->id, 'user_id' => $user->id, 'device_uuid' => $uuid,
            'status' => 'on_ready', 'remote_support_enabled' => true,
            'credential_token_id' => $token->accessToken->id,
        ]);

        return [$tenant, $user, $device, $token->plainTextToken];
    }

    // --- API authentication --------------------------------------------

    public function test_sync_requires_the_device_credential_not_the_users_login_token(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        \Laravel\Sanctum\Sanctum::actingAs($user); // ordinary login token, no device abilities

        $this->postJson('/api/mobile/v1/devices/consent-sync', ['app_consent_status' => 'enabled'])
            ->assertStatus(403);
    }

    public function test_sync_rejects_an_unauthenticated_request(): void
    {
        $this->postJson('/api/mobile/v1/devices/consent-sync', ['app_consent_status' => 'enabled'])
            ->assertStatus(401);
    }

    public function test_a_revoked_devices_credential_cannot_sync(): void
    {
        [, , $device, $plainToken] = $this->makeDeviceWithToken();
        $device->update(['status' => 'revoked']);

        // The token row itself would normally be deleted by revokeDevice()
        // (see RemoteSupportService::revokeDevice()) — this test exercises
        // the defense-in-depth status guard directly, matching
        // DeviceApiTest::test_revoked_device_cannot_heartbeat's own shape.
        $this->withHeader('Authorization', 'Bearer '.$plainToken)
            ->postJson('/api/mobile/v1/devices/consent-sync', ['app_consent_status' => 'enabled'])
            ->assertStatus(403);
    }

    // --- Tenant/device authorization ------------------------------------

    public function test_sync_only_ever_updates_the_device_bound_to_the_presented_token(): void
    {
        [, , $deviceA, $tokenA] = $this->makeDeviceWithToken();
        [, , $deviceB] = $this->makeDeviceWithToken();

        $this->withHeader('Authorization', 'Bearer '.$tokenA)
            ->postJson('/api/mobile/v1/devices/consent-sync', ['app_consent_status' => 'enabled'])
            ->assertOk();

        $this->assertSame('enabled', $deviceA->fresh()->app_consent_status);
        $this->assertSame('not_asked', $deviceB->fresh()->app_consent_status);
    }

    // --- Consent state persistence + the A/B/C/D activation matrix ------

    public function test_a_android_access_denied_consent_disabled_is_inactive(): void
    {
        [, , , $token] = $this->makeDeviceWithToken();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/mobile/v1/devices/consent-sync', [
                'app_consent_status' => 'disabled',
                'android_access' => ['notifications' => 'denied', 'battery_optimization_exempt' => 'denied'],
            ])->assertOk();

        $response->assertJsonPath('activation_status', 'inactive');
    }

    public function test_b_android_access_denied_consent_enabled_is_waiting_for_android_access(): void
    {
        [, , , $token] = $this->makeDeviceWithToken();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/mobile/v1/devices/consent-sync', [
                'app_consent_status' => 'enabled',
                'android_access' => ['notifications' => 'granted', 'battery_optimization_exempt' => 'denied'],
            ])->assertOk();

        $response->assertJsonPath('activation_status', 'waiting_for_android_access');
    }

    public function test_c_android_access_granted_consent_disabled_is_disabled_by_tenant(): void
    {
        [, , , $token] = $this->makeDeviceWithToken();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/mobile/v1/devices/consent-sync', [
                'app_consent_status' => 'disabled',
                'android_access' => ['notifications' => 'granted', 'battery_optimization_exempt' => 'granted'],
            ])->assertOk();

        $response->assertJsonPath('activation_status', 'disabled_by_tenant');
    }

    public function test_d_android_access_granted_consent_enabled_is_active(): void
    {
        [, , $device, $token] = $this->makeDeviceWithToken();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/mobile/v1/devices/consent-sync', [
                'app_consent_status' => 'enabled',
                'android_access' => ['notifications' => 'granted', 'battery_optimization_exempt' => 'granted'],
            ])->assertOk();

        $response->assertJsonPath('activation_status', 'active');
        $this->assertNotNull($device->fresh()->remote_support_last_active_at);
    }

    public function test_sync_stamps_consent_changed_at_only_when_consent_actually_changes(): void
    {
        [, , $device, $token] = $this->makeDeviceWithToken();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/mobile/v1/devices/consent-sync', ['app_consent_status' => 'enabled'])
            ->assertOk();
        $firstChangedAt = $device->fresh()->consent_changed_at;
        $this->assertNotNull($firstChangedAt);

        $this->travel(5)->minutes();

        // Same consent value again, only the access map differs — must
        // NOT bump consent_changed_at a second time.
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/mobile/v1/devices/consent-sync', [
                'app_consent_status' => 'enabled',
                'android_access' => ['notifications' => 'granted'],
            ])->assertOk();

        $this->assertTrue($firstChangedAt->equalTo($device->fresh()->consent_changed_at));
    }

    // --- Idempotency / stale-duplicate handling --------------------------

    public function test_a_duplicate_identical_sync_does_not_log_a_second_device_event(): void
    {
        [, , $device, $token] = $this->makeDeviceWithToken();
        $payload = [
            'app_consent_status' => 'enabled',
            'android_access' => ['notifications' => 'granted', 'battery_optimization_exempt' => 'granted'],
        ];

        $this->withHeader('Authorization', 'Bearer '.$token)->postJson('/api/mobile/v1/devices/consent-sync', $payload)->assertOk();
        $this->withHeader('Authorization', 'Bearer '.$token)->postJson('/api/mobile/v1/devices/consent-sync', $payload)->assertOk();
        $this->withHeader('Authorization', 'Bearer '.$token)->postJson('/api/mobile/v1/devices/consent-sync', $payload)->assertOk();

        $this->assertSame(
            1,
            DeviceEvent::where('mobile_device_id', $device->id)
                ->where('event_type', 'remote_support_consent_synced')
                ->count(),
        );
    }

    /**
     * STALE/OUT-OF-ORDER HARDENING: a delayed retry carrying an OLDER
     * `observed_at` than what's already stored must be REJECTED outright
     * — the currently-stored (newer) state is left completely untouched.
     * Reproduces the exact race SetupController's fire-and-forget sync can
     * produce: request 1 ("enabled", captured at T1) is slow; request 2
     * ("disabled", captured at T2 > T1) races ahead and lands first;
     * request 1 finally arrives after it.
     */
    public function test_older_state_is_rejected_and_does_not_regress_current_state(): void
    {
        [, , $device, $token] = $this->makeDeviceWithToken();
        $t1 = now()->subMinutes(5);
        $t2 = now();

        // T2 (the genuinely newer "disabled") lands FIRST.
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/mobile/v1/devices/consent-sync', [
                'app_consent_status' => 'disabled',
                'observed_at' => $t2->toIso8601String(),
            ])->assertOk()->assertJsonPath('app_consent_status', 'disabled');

        // T1 (the older "enabled") arrives AFTER it — must be ignored.
        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/mobile/v1/devices/consent-sync', [
                'app_consent_status' => 'enabled',
                'observed_at' => $t1->toIso8601String(),
            ])->assertOk();

        // The response reflects the CURRENT (still "disabled") stored
        // state, not the rejected payload.
        $response->assertJsonPath('app_consent_status', 'disabled');
        $this->assertSame('disabled', $device->fresh()->app_consent_status);
        // Second-precision comparison — the timestamp column truncates
        // sub-second precision, unlike the in-memory Carbon instance.
        $this->assertLessThan(2, abs($t2->diffInSeconds($device->fresh()->state_observed_at)));
    }

    /** The mirror case: a genuinely newer request must always be accepted, regardless of what arrived before it. */
    public function test_newer_state_is_accepted_over_a_previously_stored_older_one(): void
    {
        [, , $device, $token] = $this->makeDeviceWithToken();
        $t1 = now()->subMinutes(5);
        $t2 = now();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/mobile/v1/devices/consent-sync', [
                'app_consent_status' => 'enabled',
                'observed_at' => $t1->toIso8601String(),
            ])->assertOk();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/mobile/v1/devices/consent-sync', [
                'app_consent_status' => 'disabled',
                'observed_at' => $t2->toIso8601String(),
            ])->assertOk();

        $response->assertJsonPath('app_consent_status', 'disabled');
        $this->assertSame('disabled', $device->fresh()->app_consent_status);
    }

    /** A duplicate carrying the SAME observed_at as what's already stored (a plain network-level retry) must be treated as stale too, not applied a second time. */
    public function test_a_request_with_the_same_observed_at_as_the_stored_one_is_treated_as_stale(): void
    {
        [, , $device, $token] = $this->makeDeviceWithToken();
        $t = now();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/mobile/v1/devices/consent-sync', [
                'app_consent_status' => 'enabled',
                'observed_at' => $t->toIso8601String(),
            ])->assertOk();
        $firstChangedAt = $device->fresh()->consent_changed_at;

        $this->travel(1)->minutes();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/mobile/v1/devices/consent-sync', [
                'app_consent_status' => 'disabled',
                'observed_at' => $t->toIso8601String(),
            ])->assertOk();

        // Same observed_at as what's stored -> not strictly newer -> the
        // "disabled" value here must NOT have been applied.
        $this->assertSame('enabled', $device->fresh()->app_consent_status);
        $this->assertTrue($firstChangedAt->equalTo($device->fresh()->consent_changed_at));
    }

    /** A stale/rejected sync must still be logged for audit visibility, as a DISTINCT event type from an applied one — see class doc comment. */
    public function test_a_stale_sync_is_logged_distinctly_without_touching_the_synced_event_count(): void
    {
        [, , $device, $token] = $this->makeDeviceWithToken();
        $t1 = now()->subMinutes(5);
        $t2 = now();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/mobile/v1/devices/consent-sync', ['app_consent_status' => 'enabled', 'observed_at' => $t2->toIso8601String()])
            ->assertOk();
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/mobile/v1/devices/consent-sync', ['app_consent_status' => 'disabled', 'observed_at' => $t1->toIso8601String()])
            ->assertOk();

        $this->assertSame(1, DeviceEvent::where('mobile_device_id', $device->id)->where('event_type', 'remote_support_consent_synced')->count());
        $this->assertSame(1, DeviceEvent::where('mobile_device_id', $device->id)->where('event_type', 'remote_support_consent_sync_stale_ignored')->count());
    }

    /** Backward compatibility: a request with no observed_at at all is always applied — ordering protection only activates when the client participates in it. */
    public function test_a_request_without_observed_at_is_always_applied(): void
    {
        [, , $device, $token] = $this->makeDeviceWithToken();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/mobile/v1/devices/consent-sync', ['app_consent_status' => 'enabled', 'observed_at' => now()->toIso8601String()])
            ->assertOk();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/mobile/v1/devices/consent-sync', ['app_consent_status' => 'disabled'])
            ->assertOk()
            ->assertJsonPath('app_consent_status', 'disabled');

        $this->assertSame('disabled', $device->fresh()->app_consent_status);
    }

    public function test_access_synced_at_always_advances_even_when_values_are_unchanged(): void
    {
        [, , $device, $token] = $this->makeDeviceWithToken();
        $payload = ['app_consent_status' => 'enabled'];

        $this->withHeader('Authorization', 'Bearer '.$token)->postJson('/api/mobile/v1/devices/consent-sync', $payload)->assertOk();
        $first = $device->fresh()->access_synced_at;

        $this->travel(1)->minutes();

        $this->withHeader('Authorization', 'Bearer '.$token)->postJson('/api/mobile/v1/devices/consent-sync', $payload)->assertOk();
        $second = $device->fresh()->access_synced_at;

        $this->assertTrue($second->gt($first));
    }
}
