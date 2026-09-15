<?php

namespace Tests\Feature\Notifications;

use App\Models\DevicePushToken;
use App\Models\NotificationLog;
use App\Services\Notifications\WebPushService;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithPushSchema;
use Tests\TestCase;

/**
 * WebPushService::sendToUser() is the single per-user "notify" entrypoint
 * — this covers its FCM fan-out (new for the FCM push notifications task):
 * exactly one NotificationLog row per call regardless of how many channels
 * exist, only the target user's own active device tokens are pushed to,
 * and a token FCM reports as dead gets deactivated automatically.
 */
class WebPushServiceFcmFanOutTest extends TestCase
{
    use InteractsWithPushSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpPushSchema();

        config([
            'services.fcm.project_id' => 'test-project',
            'services.fcm.service_account_json' => null, // isConfigured() stays false; see the not-configured test below for the real send path
        ]);
    }

    private function fakeDevicePushToken(int $tenantId, int $userId, string $token, bool $active = true): DevicePushToken
    {
        return DevicePushToken::withoutGlobalScopes()->create([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'token' => $token,
            'platform' => 'android',
            'is_active' => $active,
        ]);
    }

    public function test_an_unconfigured_fcm_setup_still_writes_exactly_one_notification_log_row(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $this->fakeDevicePushToken($tenant->id, $user->id, 'tok-1');

        app(WebPushService::class)->sendToUser($user, ['title' => 'নতুন মেসেজ', 'body' => 'Hi'], category: 'messages');

        $this->assertSame(1, NotificationLog::withoutGlobalScopes()->where('user_id', $user->id)->count());
    }

    public function test_fcm_send_is_skipped_cleanly_when_the_user_has_no_registered_devices(): void
    {
        config(['services.fcm.service_account_json' => $this->fakeServiceAccountJson()]);
        Http::fake();

        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);

        app(WebPushService::class)->sendToUser($user, ['title' => 'নতুন মেসেজ', 'body' => 'Hi'], category: 'messages');

        Http::assertNothingSent();
    }

    public function test_only_the_targeted_users_own_active_tokens_are_pushed_to(): void
    {
        config(['services.fcm.service_account_json' => $this->fakeServiceAccountJson()]);

        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $otherUser = $this->makeUser($tenant->id);

        $this->fakeDevicePushToken($tenant->id, $user->id, 'tok-active');
        $this->fakeDevicePushToken($tenant->id, $user->id, 'tok-inactive', active: false);
        $this->fakeDevicePushToken($tenant->id, $otherUser->id, 'tok-other-user');

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'fake-token'], 200),
            'https://fcm.googleapis.com/*' => Http::response(['name' => 'ok'], 200),
        ]);

        app(WebPushService::class)->sendToUser($user, ['title' => 'নতুন মেসেজ', 'body' => 'Hi'], category: 'messages');

        Http::assertSentCount(2); // 1 oauth mint + exactly 1 FCM send (tok-active only)
        Http::assertSent(fn ($r) => str_contains($r->url(), 'fcm.googleapis.com') && $r['message']['token'] === 'tok-active');
    }

    public function test_a_token_fcm_reports_as_unregistered_is_deactivated(): void
    {
        config(['services.fcm.service_account_json' => $this->fakeServiceAccountJson()]);

        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $this->fakeDevicePushToken($tenant->id, $user->id, 'dead-token');

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'fake-token'], 200),
            'https://fcm.googleapis.com/*' => Http::response(['error' => ['status' => 'UNREGISTERED']], 400),
        ]);

        app(WebPushService::class)->sendToUser($user, ['title' => 'নতুন মেসেজ', 'body' => 'Hi'], category: 'messages');

        $this->assertFalse(DevicePushToken::withoutGlobalScopes()->where('token', 'dead-token')->first()->is_active);
    }

    public function test_deep_link_channel_and_external_id_reach_the_fcm_data_payload(): void
    {
        config(['services.fcm.service_account_json' => $this->fakeServiceAccountJson()]);

        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $this->fakeDevicePushToken($tenant->id, $user->id, 'tok-1');

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'fake-token'], 200),
            'https://fcm.googleapis.com/*' => Http::response(['name' => 'ok'], 200),
        ]);

        app(WebPushService::class)->sendToUser($user, [
            'title' => 'নতুন মেসেজ',
            'body' => 'Hi',
            'channel' => 'whatsapp',
            'external_id' => '8801700000000',
        ], category: 'messages');

        Http::assertSent(function ($r) {
            if (! str_contains($r->url(), 'fcm.googleapis.com')) {
                return false;
            }

            $data = $r['message']['data'];

            return $data['channel'] === 'whatsapp' && $data['external_id'] === '8801700000000' && $data['category'] === 'messages';
        });
    }

    public function test_a_disabled_category_sends_no_fcm_push_either(): void
    {
        config(['services.fcm.service_account_json' => $this->fakeServiceAccountJson()]);
        Http::fake();

        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $this->fakeDevicePushToken($tenant->id, $user->id, 'tok-1');

        app(\App\Services\Notifications\NotificationPreferenceService::class)->setEnabled($user, 'messages', false);

        app(WebPushService::class)->sendToUser($user, ['title' => 'নতুন মেসেজ', 'body' => 'Hi'], category: 'messages');

        Http::assertNothingSent();
    }

    // Mirrors FcmSendServiceTest's own fixture — see that test's doc comment.
    private const TEST_PRIVATE_KEY_PEM = <<<'PEM'
    -----BEGIN PRIVATE KEY-----
    MIIEvgIBADANBgkqhkiG9w0BAQEFAASCBKgwggSkAgEAAoIBAQDmgvJUgS7Vdo6N
    D56wpUnj/LI8VsVfV0E2eOtjvRozfOx/J/UpIhoWqWd7OWVnFWgnvdKNfsnTX/u+
    TLK1IfA0ryixBDZDWKXN9XDoWMCsDvSX5m7Rsl4FfBqqU4rC4cBUOoh+X+hPqEqx
    gcmUd3xLQUGZGgCLOg5hDaHfPtAa6BEgHh1zyGqScQn8YdGrmZ0+O3ed+EEjIYyz
    agpkGJGPvPkbRRiibs+S8RwCzIbBqudpVmn3p+iSkyvnTY7Mb7BFH9SKTusdP5Gu
    w+nvmt2ZtwBnSs6BtJD3f83aTXwOxcrCZ4dlwY1PhtzGo+GXvOy7EvSAj3o0UqKC
    hEO79+UPAgMBAAECggEACIN4mMdmp+qnjC0yArswFfQMzy6zPni2B2GC7B2dXJ52
    C6I5q0m/peuez03I4XxIawNXRfOTV7O5VAd4KDl3KjL38UXDDUy2Xvt8LpCsmQ46
    WWvg1uzcDR7Oy1CnlNgKpvG8fdJj/aEtFQ5CmDGrjQn9dr6fm4TK6Cm9O0YSIJQ0
    r2KJ88OlmgCO7j9PJTShdy6O8bzE2XtSHwNoO1q+Ic8sWM4/O8g2uHBisFQKLLeS
    lUrcS1hcNF9957xr4aqIZQYw2kNRJRq2Fa1cpCeuqu+fRgKqDY88NSe6l2BMBIWl
    M3pDS3HN+0nZsNdDpdnLNlP8vLFYjlz77v+po8QA5QKBgQD3BqYKkkHI+Q22rXJl
    TgVyu68q/pNwfMEgHB1Oy3I15XfIf4gkrJLOWo2vNfj6QdOl5VB2IrC631Ol4hGg
    3PbjSBjuicRpZGo7ZqacMz51pnBQa1y15rgqM0uCEH+igyyKdMD/nzix7nvp6coC
    To6o/SPXyczSbfUwXwZ7YoK8wwKBgQDu4rZ9xou42fCGyGEGP+IK6BQIetR96qD7
    VgpyOMcy8NXActnE8g63ze0rC62vbU5d0ljPDrIpqBHAYK9OAxmbfDLAF0MgUoXI
    dwawhFM5ML8uujRsB2prfAcOYbElcxjiREtVaQeEKttbfseGAm5nQ/fHIePJLIcw
    4m/OpmKhxQKBgQCsA5Ez40yz6dnGz1jNelsI3fDIe6WnuvewqGMwLzNEnJmgoE3p
    W9KOpzfqPic1/QioiNpSqS1vs3vIE3g7ECNLeTUDRiPjT+05l+2E75oayt+C4IAa
    mqK7oCSAWYTHYZhugYazeeg83tiitg3ZNWLaAgwng3qBPdhy6njVCnAHiQKBgH/k
    0w0tkjqKO/L9LqzY4N0z+R29HSy4xC0rmHYknclRFS9ujdaaPXT8hABqxTdJjw4+
    ApwAYzRYLgDQAqsCj+Als0oSajbQ151G1EcG4UOaLJEI0e4QXlJjWafCd8P0BhuF
    sstsasDA7SXkD1BY1uDki7CKHVjkRRDP+kop3F59AoGBAN9XAbzTT8qhGObIJUGe
    tEQgb5LB6LCF/vlEqCYT0i3b8O39thXlEO2U0wjOwFTB7eyqzVD0QS5EmGq4DZ4j
    bgvtmt+AXE0ckoRpOMx/c+zIUcdEu4aPC3y2Q4K4OeYb3T+MGpzp2mVTT5idmvJY
    wO8WTBx8nqIb58I91B9eSkvA
    -----END PRIVATE KEY-----
    PEM;

    private function fakeServiceAccountJson(): string
    {
        return json_encode([
            'client_email' => 'fcm-sender@test-project.iam.gserviceaccount.com',
            'private_key' => self::TEST_PRIVATE_KEY_PEM,
            'token_uri' => 'https://oauth2.googleapis.com/token',
        ]);
    }

    protected function tearDown(): void
    {
        \Illuminate\Support\Facades\Cache::forget('fcm_oauth_access_token');
        parent::tearDown();
    }
}
