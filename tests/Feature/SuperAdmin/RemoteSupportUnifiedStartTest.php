<?php

namespace Tests\Feature\SuperAdmin;

use App\Models\MobileDevice;
use App\Models\RemoteSupportSetting;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithDeviceIntelligenceSchema;
use Tests\TestCase;

/**
 * The unified "Start Live Screen" button —
 * RemoteSupportController::startSession() (now
 * RemoteSupportService::requestSessionStart()-backed) and its companion
 * status() polling endpoint. Merges what used to be two separate admin
 * actions (plain Start for an already-`on_ready` device, the blocking
 * "Wake & Start" for an offline one — still covered unchanged by
 * RemoteSupportWakeTest.php) into one JSON-driven decision, without a
 * server-side sleep() wait. See RemoteSupportService::requestSessionStart's
 * own doc comment for the exact state contract these tests lock in.
 */
class RemoteSupportUnifiedStartTest extends TestCase
{
    use InteractsWithDeviceIntelligenceSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDeviceIntelligenceSchema();
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

    /** Same throwaway-but-structurally-valid fixture RemoteSupportWakeTest uses — see that class's own doc comment. */
    private const TEST_ONLY_PRIVATE_KEY = <<<'PEM'
    -----BEGIN PRIVATE KEY-----
    MIIEvAIBADANBgkqhkiG9w0BAQEFAASCBKYwggSiAgEAAoIBAQCZl9v6oIweO/Da
    SYbJsYfoa7ugloBE9lzn63D3mng15OPreCOjb3V+/0sXKIKZk9VALb8J4Ndhyr1h
    WaHpACGJXf0HiU5YFb9RWLU6DMNFscyhAn4kRxmjjbAlYmxOdZv2aiwtCHxwXpZH
    p+4jXToqHcn3ov+QliMWuu0enypDixoXFyjCqWQKT0UwpngxM+MVs04yfXnDx/6r
    2lTkEcX2slfYf6sF7U3go6uesvP9qr7WF9jffjJHqlYC2xWRoi3WC3Fk24aJMy99
    qVUGWaVXveKRIwFb+/fFvbq2zQq+wRfpI0LB5mtr2y0twTxT80eKFBDvXBZgLS5G
    C9tVBLENAgMBAAECggEAEWPijgi1/KlcPpbjGjyNzwi9pHPN36EGSWL8tigo8q8x
    CCTg4h0ZUFD+80cMrG9SzpKvZeKteD7QfPB9VsiDQ46e+sa21l3V/NOep00xIde8
    +8Dwv9JGCqDdAAqaCTMjPr3sNQgYMM/g04mlup7QWlrnlnB/36LEI8tz1AsA6cct
    /GGsUOMfuwHyYftg+F5tA/+klYbedx+Oiu6A5KDStnvD0JurSurIqoD2DAbB91pC
    lGUwJP0E9zUpY0kwRfzcC+oOOGjMM9a3PZejnjsxZiX7hUehReiJQHkU8MMmOi8J
    w+Z4suxcjfdP+sw35KAkraE4qjS4FnxmFXB5p84AyQKBgQDHqyfEQ9Downy95wFd
    mboBG5ktA8NaTgeKCReyXVj5lWmO/VdXajg8New7G2FcXM23+ZoemGty1awfwTMX
    ZokkIeuWlmE1B6KbfQYRP3VhKQAoqKy5C3WiUL80yLxmxcMQa/NqWzYnuHHWdZik
    Veyyb5VHuT7vpjDWrZyjh4rdZQKBgQDE7PXYjVIOYE2CFtfP6oe/RbrJFs2DI9Al
    wGK/Zft77HNArfrm4vTHhBtBYtonJTT4WT5BKtIPwzO6IGmj7VIVMPxXO5EX4KIB
    sZ6FuEmQIa/XuXNJqtgcaR+PYTcDgmfwHgTCgXnQt7hPwVH2I9qZ+dGEESADZl3J
    GRecMEL+iQKBgFzTUVWJQaI0aNHQh24Afdzt+7RVv4hIJnEh8QZXJ3pA4CY92VEm
    bKZxWzXPcOADtRAG1oPK0Ik+lRreMuZ6IN/PdTs5BlAlEI/A/AUgv21AJnbAlCwj
    C7k+WOYtPIl6/0ivJycW6IJm2WWeniwoVjZOcdQ6Zuv7E1F6vQu5g/GRAoGAFmpy
    wae0g3Zq3DgFcOFdemRRkrpJwne9Jc70bp0JN13+8IndWZLUEjHzeEnsUzBlCy/9
    aiWZq7molbNC0yocgdUFpFhfd7xWNKMnTElh9kofXgr6GJSd7P36XbPPM1MQycBh
    AlMCrsN683kp/z6tj4FkXAgnALwSSOXWTi0gMyECgYBL7VZc3Cy1zovDqXqDbpX1
    z7AX4JobLd7QOAktXPGvH/VHvc0BJvbzZjupCIh7KK2M0to6D5wWFvYlH/7DnGrk
    zW99rRdIvEt8mxheGZlFzPw4e8G3Jn7yr1pDKINDXWsG0pk95N6R232nz+eldcP4
    TEnJEbtSUjV/HVdceauwJA==
    -----END PRIVATE KEY-----
    PEM;

    protected function fakeFcmConfigured(): void
    {
        $path = storage_path('framework/testing/fake_fcm_service_account_unified.json');
        file_put_contents($path, json_encode([
            'client_email' => 'test@example.iam.gserviceaccount.com',
            'private_key' => self::TEST_ONLY_PRIVATE_KEY,
            'token_uri' => 'https://oauth2.googleapis.com/token',
        ]));

        config([
            'remote_support.fcm_project_id' => 'test-project',
            'remote_support.fcm_service_account_path' => $path,
        ]);
    }

    // --- READY device -----------------------------------------------------

    public function test_ready_device_starts_a_session_immediately_via_json(): void
    {
        $admin = $this->makeSuperAdmin();
        $tenant = $this->makeTenant();
        RemoteSupportSetting::create(['tenant_id' => $tenant->id, 'enabled' => true]);
        $user = $this->makeUser($tenant->id);
        $device = $this->makeDevice($tenant->id, $user->id, [
            'status' => 'on_ready', 'remote_support_enabled' => true, 'last_seen_at' => now(),
        ]);

        $response = $this->actingAs($admin, 'super_admin')
            ->postJson(route('super.remote-support.session.start', [$tenant, $device]), ['include_screen' => '1']);

        $response->assertOk()->assertJson(['state' => 'started']);
        $this->assertNotEmpty($response->json('redirect'));
        $this->assertDatabaseHas('remote_support_sessions', ['mobile_device_id' => $device->id, 'status' => 'active']);
        Http::assertNothingSent(); // no wake attempted for an already-ready device.
    }

    // --- NOT READY (on_not_ready — already heartbeating) ------------------

    public function test_not_ready_device_returns_not_ready_without_attempting_a_wake(): void
    {
        $admin = $this->makeSuperAdmin();
        $tenant = $this->makeTenant();
        RemoteSupportSetting::create(['tenant_id' => $tenant->id, 'enabled' => true]);
        $user = $this->makeUser($tenant->id);
        $device = $this->makeDevice($tenant->id, $user->id, [
            'status' => 'on_not_ready', 'remote_support_enabled' => true, 'last_seen_at' => now(), 'fcm_token' => 'tok-123',
        ]);

        $response = $this->actingAs($admin, 'super_admin')
            ->postJson(route('super.remote-support.session.start', [$tenant, $device]), []);

        $response->assertOk()->assertJson(['state' => 'not_ready']);
        $this->assertDatabaseCount('remote_support_sessions', 0);
        // An on_not_ready device is already heartbeating — waking it would
        // just no-op server-side, so no FCM send should even be attempted.
        Http::assertNothingSent();
    }

    // --- OFFLINE (stale heartbeat) with an fcm_token: wake, then ready ----

    public function test_offline_device_wakes_then_starts_once_it_reports_ready(): void
    {
        $this->fakeFcmConfigured();
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'fake-token'], 200),
            'fcm.googleapis.com/*' => Http::response(['name' => 'projects/test/messages/1'], 200),
        ]);

        $admin = $this->makeSuperAdmin();
        $tenant = $this->makeTenant();
        RemoteSupportSetting::create(['tenant_id' => $tenant->id, 'enabled' => true]);
        $user = $this->makeUser($tenant->id);
        $device = $this->makeDevice($tenant->id, $user->id, [
            'status' => 'on_ready', 'remote_support_enabled' => true, 'fcm_token' => 'tok-123',
            'last_seen_at' => now()->subMinutes(10), // stale -> liveStatus() = offline
        ]);

        $startResponse = $this->actingAs($admin, 'super_admin')
            ->postJson(route('super.remote-support.session.start', [$tenant, $device]), []);

        $startResponse->assertOk()->assertJson(['state' => 'waking']);
        Http::assertSentCount(2); // oauth token fetch + the FCM send itself.
        $this->assertDatabaseCount('remote_support_sessions', 0);

        // The Admin's browser polls status() while waiting — not yet ready.
        $this->actingAs($admin, 'super_admin')
            ->getJson(route('super.remote-support.devices.status', [$tenant, $device]))
            ->assertOk()->assertJson(['state' => 'not_ready']);

        // Device's own heartbeat (unchanged, ordinary path) reports it ready.
        MobileDevice::where('id', $device->id)->update([
            'status' => 'on_ready', 'foreground_service_running' => true,
            'permissions' => ['notifications' => true, 'battery_optimization_exempt' => true],
            'last_seen_at' => now(),
        ]);

        $this->actingAs($admin, 'super_admin')
            ->getJson(route('super.remote-support.devices.status', [$tenant, $device]))
            ->assertOk()->assertJson(['state' => 'ready']);

        // The JS re-POSTs the exact same start endpoint once status() says
        // 'ready' — this time it takes the immediate path, no second wake.
        $secondStart = $this->actingAs($admin, 'super_admin')
            ->postJson(route('super.remote-support.session.start', [$tenant, $device]), []);

        $secondStart->assertOk()->assertJson(['state' => 'started']);
        Http::assertSentCount(2); // still just the one wake from before — no second FCM send.
        $this->assertDatabaseHas('remote_support_sessions', ['mobile_device_id' => $device->id, 'status' => 'active']);
    }

    // --- OFFLINE with no fcm_token: cannot even attempt a wake -------------

    public function test_offline_device_without_an_fcm_token_returns_not_ready_without_a_wake_attempt(): void
    {
        $admin = $this->makeSuperAdmin();
        $tenant = $this->makeTenant();
        RemoteSupportSetting::create(['tenant_id' => $tenant->id, 'enabled' => true]);
        $user = $this->makeUser($tenant->id);
        $device = $this->makeDevice($tenant->id, $user->id, [
            'status' => 'on_ready', 'remote_support_enabled' => true,
            'last_seen_at' => now()->subMinutes(10), // stale -> offline
        ]);

        $response = $this->actingAs($admin, 'super_admin')
            ->postJson(route('super.remote-support.session.start', [$tenant, $device]), []);

        $response->assertOk()->assertJson(['state' => 'not_ready']);
        Http::assertNothingSent();
        $this->assertDatabaseCount('remote_support_sessions', 0);
    }

    // --- status() reports an already-open session so the JS can redirect --

    public function test_status_endpoint_reports_an_open_session_instead_of_ready(): void
    {
        $admin = $this->makeSuperAdmin();
        $tenant = $this->makeTenant();
        RemoteSupportSetting::create(['tenant_id' => $tenant->id, 'enabled' => true]);
        $user = $this->makeUser($tenant->id);
        $device = $this->makeDevice($tenant->id, $user->id, [
            'status' => 'on_ready', 'remote_support_enabled' => true, 'last_seen_at' => now(),
        ]);
        $sessionId = \Illuminate\Support\Facades\DB::table('remote_support_sessions')->insertGetId([
            'tenant_id' => $tenant->id, 'mobile_device_id' => $device->id, 'started_by_super_admin_id' => $admin->id,
            'status' => 'active', 'session_token' => 'open-one', 'started_at' => now(), 'connected_at' => now(),
            'expires_at' => now()->addMinutes(30), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->actingAs($admin, 'super_admin')
            ->getJson(route('super.remote-support.devices.status', [$tenant, $device]));

        $response->assertOk()->assertJson([
            'state' => 'session_open',
            'redirect' => route('super.remote-support.session.viewer', [$tenant, $device, $sessionId]),
        ]);
    }

    // --- 409 conflict still surfaces as JSON for the unified endpoint -----

    public function test_a_concurrent_session_conflict_returns_a_json_failed_state(): void
    {
        $admin = $this->makeSuperAdmin();
        $tenant = $this->makeTenant();
        RemoteSupportSetting::create(['tenant_id' => $tenant->id, 'enabled' => true]);
        $user = $this->makeUser($tenant->id);
        $device = $this->makeDevice($tenant->id, $user->id, [
            'status' => 'on_ready', 'remote_support_enabled' => true, 'last_seen_at' => now(),
        ]);
        \Illuminate\Support\Facades\DB::table('remote_support_sessions')->insert([
            'tenant_id' => $tenant->id, 'mobile_device_id' => $device->id, 'started_by_super_admin_id' => $admin->id,
            'status' => 'active', 'session_token' => 'still-open', 'started_at' => now(),
            'expires_at' => now()->addMinutes(30), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->actingAs($admin, 'super_admin')
            ->postJson(route('super.remote-support.session.start', [$tenant, $device]), []);

        $response->assertStatus(409)->assertJson(['state' => 'failed', 'message' => 'এই ডিভাইসে ইতিমধ্যে একটি সেশন চলছে।']);
        $this->assertDatabaseCount('remote_support_sessions', 1);
    }
}
