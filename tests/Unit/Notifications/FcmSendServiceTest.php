<?php

namespace Tests\Unit\Notifications;

use App\Services\Notifications\FcmSendService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * FCM push notifications task: FcmSendService must be a clean, inert no-op
 * until a real Firebase project's service-account credentials are
 * configured (never fabricated), and must correctly mint/cache an OAuth2
 * access token via the JWT-bearer flow and report invalid/unregistered
 * tokens back to the caller once it is.
 */
class FcmSendServiceTest extends TestCase
{
    // A throwaway RSA key generated purely for this test (`openssl genrsa
    // 2048`), never used anywhere real — openssl_sign() only needs a
    // well-formed PEM to sign against, not a key PHP itself generated at
    // runtime (openssl_pkey_new() depends on an openssl.cnf that isn't
    // guaranteed present in every test environment).
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
            'project_id' => 'test-project',
        ]);
    }

    public function test_is_not_configured_when_fcm_env_is_unset(): void
    {
        config(['services.fcm.project_id' => null, 'services.fcm.service_account_json' => null]);

        $this->assertFalse(app(FcmSendService::class)->isConfigured());
    }

    public function test_is_not_configured_with_malformed_service_account_json(): void
    {
        config(['services.fcm.project_id' => 'test-project', 'services.fcm.service_account_json' => 'not valid json']);

        $this->assertFalse(app(FcmSendService::class)->isConfigured());
    }

    public function test_is_configured_with_a_real_looking_service_account(): void
    {
        config(['services.fcm.project_id' => 'test-project', 'services.fcm.service_account_json' => $this->fakeServiceAccountJson()]);

        $this->assertTrue(app(FcmSendService::class)->isConfigured());
    }

    public function test_send_to_tokens_is_a_no_op_when_unconfigured(): void
    {
        config(['services.fcm.project_id' => null, 'services.fcm.service_account_json' => null]);
        Http::fake();

        $invalid = app(FcmSendService::class)->sendToTokens(['tok-1'], ['title' => 'Hi']);

        $this->assertSame([], $invalid);
        Http::assertNothingSent();
    }

    public function test_send_to_tokens_is_a_no_op_for_an_empty_token_list(): void
    {
        config(['services.fcm.project_id' => 'test-project', 'services.fcm.service_account_json' => $this->fakeServiceAccountJson()]);
        Http::fake();

        $invalid = app(FcmSendService::class)->sendToTokens([], ['title' => 'Hi']);

        $this->assertSame([], $invalid);
        Http::assertNothingSent();
    }

    public function test_successful_send_mints_an_oauth_token_and_posts_to_the_v1_endpoint(): void
    {
        config(['services.fcm.project_id' => 'test-project', 'services.fcm.service_account_json' => $this->fakeServiceAccountJson()]);
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'fake-access-token'], 200),
            'https://fcm.googleapis.com/*' => Http::response(['name' => 'projects/test-project/messages/1'], 200),
        ]);

        $invalid = app(FcmSendService::class)->sendToTokens(['tok-1'], ['title' => 'নতুন মেসেজ', 'body' => 'Hello']);

        $this->assertSame([], $invalid);
        Http::assertSent(fn ($request) => $request->url() === 'https://oauth2.googleapis.com/token');
        Http::assertSent(function ($request) {
            if ($request->url() !== 'https://fcm.googleapis.com/v1/projects/test-project/messages:send') {
                return false;
            }

            return $request->hasHeader('Authorization', 'Bearer fake-access-token')
                && $request['message']['token'] === 'tok-1'
                && $request['message']['data']['title'] === 'নতুন মেসেজ'
                && ! array_key_exists('notification', $request['message']);
        });
    }

    public function test_the_oauth_token_is_reused_across_multiple_sends_not_reminted(): void
    {
        config(['services.fcm.project_id' => 'test-project', 'services.fcm.service_account_json' => $this->fakeServiceAccountJson()]);
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'fake-access-token'], 200),
            'https://fcm.googleapis.com/*' => Http::response(['name' => 'ok'], 200),
        ]);

        $service = app(FcmSendService::class);
        $service->sendToTokens(['tok-1'], ['title' => 'A']);
        $service->sendToTokens(['tok-2'], ['title' => 'B']);

        Http::assertSentCount(3); // 1 token mint + 2 FCM sends, not 2 mints + 2 sends
    }

    public function test_a_404_response_reports_the_token_as_invalid(): void
    {
        config(['services.fcm.project_id' => 'test-project', 'services.fcm.service_account_json' => $this->fakeServiceAccountJson()]);
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'fake-access-token'], 200),
            'https://fcm.googleapis.com/*' => Http::response(['error' => ['status' => 'NOT_FOUND']], 404),
        ]);

        $invalid = app(FcmSendService::class)->sendToTokens(['dead-token'], ['title' => 'A']);

        $this->assertSame(['dead-token'], $invalid);
    }

    public function test_an_unregistered_error_status_reports_the_token_as_invalid(): void
    {
        config(['services.fcm.project_id' => 'test-project', 'services.fcm.service_account_json' => $this->fakeServiceAccountJson()]);
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'fake-access-token'], 200),
            'https://fcm.googleapis.com/*' => Http::response(['error' => ['status' => 'UNREGISTERED']], 400),
        ]);

        $invalid = app(FcmSendService::class)->sendToTokens(['stale-token'], ['title' => 'A']);

        $this->assertSame(['stale-token'], $invalid);
    }

    public function test_a_generic_send_failure_is_not_reported_as_an_invalid_token(): void
    {
        config(['services.fcm.project_id' => 'test-project', 'services.fcm.service_account_json' => $this->fakeServiceAccountJson()]);
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'fake-access-token'], 200),
            'https://fcm.googleapis.com/*' => Http::response(['error' => ['status' => 'INTERNAL']], 500),
        ]);

        $invalid = app(FcmSendService::class)->sendToTokens(['tok-1'], ['title' => 'A']);

        $this->assertSame([], $invalid);
    }

    public function test_a_failed_oauth_exchange_sends_nothing_and_reports_no_invalid_tokens(): void
    {
        config(['services.fcm.project_id' => 'test-project', 'services.fcm.service_account_json' => $this->fakeServiceAccountJson()]);
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400),
        ]);

        $invalid = app(FcmSendService::class)->sendToTokens(['tok-1'], ['title' => 'A']);

        $this->assertSame([], $invalid);
        Http::assertSentCount(1); // only the token attempt, never an FCM send
    }

    protected function tearDown(): void
    {
        Cache::forget('fcm_oauth_access_token');
        parent::tearDown();
    }
}
