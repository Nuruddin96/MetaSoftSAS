<?php

namespace Tests\Feature\SuperAdmin;

use App\Models\MobileDevice;
use App\Models\RemoteSupportSetting;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithRemoteSupportSchema;
use Tests\TestCase;

/**
 * "Wake & Start Remote Support" — RemoteSupportController::wakeAndStart().
 * Keeps the bounded poll fast in tests (1s timeout/interval, see setUp)
 * rather than the production default (40s/3s, config/remote_support.php).
 */
class RemoteSupportWakeTest extends TestCase
{
    use InteractsWithRemoteSupportSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRemoteSupportSchema();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        config([
            'remote_support.wake_timeout_seconds' => 1,
            'remote_support.wake_poll_interval_seconds' => 1,
        ]);
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

    /**
     * Throwaway RSA key, generated once via `openssl genrsa` purely so
     * RemoteSupportService::fetchFcmAccessToken()'s real openssl_sign() call
     * has valid PEM to sign with — not a real credential, never used against
     * a real Google endpoint (Http::fake() intercepts both calls below).
     * `openssl_pkey_new()` needs an openssl.cnf this test environment
     * doesn't reliably have, which is why this is a static fixture instead.
     */
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

    /** Points config at a throwaway but structurally valid service-account JSON (real RSA key, fake identity) — see TEST_ONLY_PRIVATE_KEY's doc comment. */
    protected function fakeFcmConfigured(): void
    {
        $path = storage_path('framework/testing/fake_fcm_service_account.json');
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

    public function test_wake_fails_immediately_when_device_has_no_fcm_token(): void
    {
        $admin = $this->makeSuperAdmin();
        $tenant = $this->makeTenant();
        RemoteSupportSetting::create(['tenant_id' => $tenant->id, 'enabled' => true]);
        $user = $this->makeUser($tenant->id);
        $device = $this->makeDevice($tenant->id, $user->id, ['status' => 'off']);

        $response = $this->actingAs($admin, 'super_admin')
            ->post(route('super.remote-support.devices.wake', [$tenant, $device]));

        $response->assertRedirect();
        $this->assertStringContainsString('FCM টোকেন নেই', session('error'));
        Http::assertNothingSent();
    }

    public function test_wake_fails_immediately_when_fcm_not_configured(): void
    {
        $admin = $this->makeSuperAdmin();
        $tenant = $this->makeTenant();
        RemoteSupportSetting::create(['tenant_id' => $tenant->id, 'enabled' => true]);
        $user = $this->makeUser($tenant->id);
        $device = $this->makeDevice($tenant->id, $user->id, ['status' => 'off', 'fcm_token' => 'tok-123']);

        // Deliberately leaves remote_support.fcm_project_id/fcm_service_account_path unset.
        $response = $this->actingAs($admin, 'super_admin')
            ->post(route('super.remote-support.devices.wake', [$tenant, $device]));

        $response->assertRedirect();
        $this->assertStringContainsString('FCM কনফিগারেশন', session('error'));
        Http::assertNothingSent();
    }

    public function test_wake_times_out_and_returns_a_clear_error_when_device_never_becomes_ready(): void
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
            'status' => 'off', 'remote_support_enabled' => true, 'fcm_token' => 'tok-123',
        ]);

        $response = $this->actingAs($admin, 'super_admin')
            ->post(route('super.remote-support.devices.wake', [$tenant, $device]));

        $response->assertRedirect();
        $this->assertStringContainsString('জাগানো যায়নি', session('error'));
        Http::assertSentCount(2);
    }

    public function test_wake_starts_the_existing_session_flow_once_the_device_reports_itself_ready(): void
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
            'status' => 'off', 'remote_support_enabled' => true, 'fcm_token' => 'tok-123',
        ]);

        // Simulates the device's own heartbeat flipping it to on_ready
        // WHILE the admin's request is inside its poll loop — exactly what
        // a real device resuming via HeadlessEngineHost would eventually
        // report through the ordinary heartbeat endpoint, not something
        // this test fakes through any new path.
        MobileDevice::where('id', $device->id)->update([
            'status' => 'on_ready',
            'foreground_service_running' => true,
            'permissions' => ['notifications' => true, 'battery_optimization_exempt' => true],
            'last_seen_at' => now(),
        ]);

        $response = $this->actingAs($admin, 'super_admin')
            ->post(route('super.remote-support.devices.wake', [$tenant, $device]));

        $response->assertRedirect(route('super.remote-support.session.viewer', [
            $tenant, $device, $device->sessions()->latest('id')->first()->id,
        ]));
    }
}
