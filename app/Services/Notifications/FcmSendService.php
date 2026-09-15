<?php

namespace App\Services\Notifications;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sends push notifications to registered Android/iOS app installs via
 * Firebase Cloud Messaging's HTTP v1 API. No Firebase Admin SDK /
 * `google/apiclient` dependency — same "don't add a new production
 * dependency without asking" posture WebPushService's own docblock states;
 * the OAuth2 service-account JWT-bearer exchange this needs is small
 * enough in plain PHP (openssl_sign) that a whole package isn't warranted.
 *
 * Entirely inert (isConfigured() false, sendToTokens() a clean no-op) until
 * a real Firebase project's service account key is set via
 * FCM_SERVICE_ACCOUNT_JSON/FCM_PROJECT_ID — see config/services.php's
 * 'fcm' block. Never fabricates a project id or key.
 *
 * Always sends a DATA-ONLY message (never FCM's "notification" payload
 * shape) — the Flutter app renders the heads-up notification itself via
 * flutter_local_notifications in every app state (foreground/background/
 * terminated) for one consistent code path, rather than relying on
 * Android's own auto-display of a "notification" payload, which only
 * happens while the app is backgrounded/terminated and never while
 * foregrounded.
 */
class FcmSendService
{
    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    private const TOKEN_CACHE_KEY = 'fcm_oauth_access_token';

    public function isConfigured(): bool
    {
        return ! empty(config('services.fcm.project_id')) && $this->serviceAccount() !== null;
    }

    /**
     * @param  string[]  $tokens
     * @param  array<string,string>  $data  All values must already be strings — FCM's data payload requires it.
     * @return string[] tokens FCM reported as invalid/unregistered — the caller should deactivate these.
     */
    public function sendToTokens(array $tokens, array $data): array
    {
        if (! $this->isConfigured() || empty($tokens)) {
            return [];
        }

        $accessToken = $this->accessToken();
        if (! $accessToken) {
            return [];
        }

        $projectId = config('services.fcm.project_id');
        $invalid = [];

        foreach (array_unique($tokens) as $token) {
            try {
                $response = Http::timeout(10)
                    ->withToken($accessToken)
                    ->post("https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send", [
                        'message' => [
                            'token' => $token,
                            'data' => $data,
                            'android' => ['priority' => 'high'],
                        ],
                    ]);
            } catch (ConnectionException $e) {
                Log::error('FcmSendService: connection failure calling FCM.', ['project_id' => $projectId]);

                continue;
            }

            // A dead/uninstalled-app token — HTTP 404, or a 400 carrying
            // error.status UNREGISTERED, per FCM v1's documented error
            // shapes. Same "deactivate rather than keep retrying a dead
            // target" reasoning as WebPushService::send()'s
            // isSubscriptionExpired() branch.
            if ($response->status() === 404 || $response->json('error.status') === 'UNREGISTERED') {
                $invalid[] = $token;

                continue;
            }

            if (! $response->successful()) {
                Log::warning('FcmSendService: send failed.', [
                    'status' => $response->status(),
                    'error_status' => $response->json('error.status'),
                ]);
            }
        }

        return $invalid;
    }

    /**
     * Cached ~55 minutes (Google-issued access tokens live 1 hour) so a
     * batch of sends mints one OAuth2 token, not one per device.
     */
    private function accessToken(): ?string
    {
        $token = Cache::remember(self::TOKEN_CACHE_KEY, 3300, function () {
            $account = $this->serviceAccount();
            if (! $account) {
                return null;
            }

            $jwt = $this->buildSignedJwt($account);
            if (! $jwt) {
                return null;
            }

            $tokenUri = $account['token_uri'] ?? 'https://oauth2.googleapis.com/token';

            try {
                $response = Http::asForm()->timeout(10)->post($tokenUri, [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => $jwt,
                ]);
            } catch (ConnectionException $e) {
                Log::error('FcmSendService: connection failure fetching an OAuth2 access token.');

                return null;
            }

            if (! $response->successful()) {
                Log::warning('FcmSendService: OAuth2 token exchange failed.', ['status' => $response->status()]);

                return null;
            }

            return $response->json('access_token');
        });

        return $token ?: null;
    }

    /** @return array{client_email:string,private_key:string,token_uri?:string}|null */
    private function serviceAccount(): ?array
    {
        $json = config('services.fcm.service_account_json');
        if (empty($json)) {
            return null;
        }

        $decoded = json_decode($json, true);
        if (! is_array($decoded) || empty($decoded['client_email']) || empty($decoded['private_key'])) {
            return null;
        }

        return $decoded;
    }

    private function buildSignedJwt(array $account): ?string
    {
        $now = time();
        $header = $this->base64UrlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claims = $this->base64UrlEncode(json_encode([
            'iss' => $account['client_email'],
            'scope' => self::SCOPE,
            'aud' => $account['token_uri'] ?? 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $now + 3600,
        ]));

        $signingInput = "{$header}.{$claims}";
        $signature = '';
        $signed = openssl_sign($signingInput, $signature, $account['private_key'], OPENSSL_ALGO_SHA256);

        if (! $signed) {
            Log::error("FcmSendService: failed to sign the OAuth2 JWT — check FCM_SERVICE_ACCOUNT_JSON's private_key.");

            return null;
        }

        return $signingInput.'.'.$this->base64UrlEncode($signature);
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
