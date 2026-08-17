<?php

namespace App\Services\Marketing;

use App\Models\Order;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * FEATURE: Facebook Conversion API (server-side events).
 *
 * Works together with the browser Pixel:
 *  - Browser fires Purchase with eventID = order->fb_event_id
 *  - Server fires the SAME event_id here -> Meta deduplicates
 * So ad reporting stays accurate even with iOS/ad-blockers.
 *
 * GTM: tenant's GTM container id is injected in the storefront layout;
 * if they only use GTM, that alone works. Pixel/CAPI fields are separate.
 *
 * access_token is sent in the POST body (never the URL) so it can never
 * end up in a Guzzle exception message or request-logged URL.
 */
class MetaCapiService
{
    protected string $base;

    public function __construct(
        protected string $pixelId,
        protected string $accessToken,
        protected ?string $testEventCode = null,
    ) {
        $this->base = 'https://graph.facebook.com/'.config('facebook.graph_version');
    }

    /**
     * @return array{success: bool, http_status: ?int, error_code: ?int, error_type: ?string, error_message: ?string}
     */
    public function sendPurchase(Order $order, ?string $clientIp = null, ?string $userAgent = null, ?string $fbp = null, ?string $fbc = null): array
    {
        $payload = [
            'access_token' => $this->accessToken,
            'data' => [[
                'event_name' => 'Purchase',
                'event_time' => now()->timestamp,
                'event_id' => $order->fb_event_id,          // dedup with browser pixel
                'action_source' => 'website',
                'event_source_url' => app('currentTenant')->url(),
                'user_data' => array_filter([
                    'ph' => [hash('sha256', $this->normalizePhone($order->customer_phone))],
                    'fn' => [hash('sha256', mb_strtolower(trim($order->customer_name)))],
                    'client_ip_address' => $clientIp,
                    'client_user_agent' => $userAgent,
                    'fbp' => $fbp,
                    'fbc' => $fbc,
                ]),
                'custom_data' => [
                    'currency' => 'BDT',
                    'value' => (float) $order->total,
                    'order_id' => $order->order_number,
                    'contents' => $order->items->map(fn ($i) => [
                        'id' => $i->sku,
                        'quantity' => $i->quantity,
                        'item_price' => (float) $i->unit_price,
                    ])->all(),
                    'content_type' => 'product',
                ],
            ]],
        ];

        if ($this->testEventCode) {
            $payload['test_event_code'] = $this->testEventCode;
        }

        $context = [
            'tenant_id' => $order->tenant_id,
            'order_id' => $order->id,
            'event_id' => $order->fb_event_id,
            'timestamp' => now()->toIso8601String(),
        ];

        try {
            // Retries only fire on transient connection failures (timeout,
            // DNS, TLS) — never on a 4xx/5xx application response, since
            // retrying a bad token or malformed payload just wastes calls.
            // Safe to retry at all because Meta dedups by event_id, so a
            // retried send can never double-count the conversion.
            $response = Http::retry(2, 200, function (\Throwable $e) {
                return $e instanceof ConnectionException;
            }, throw: false)->post("{$this->base}/{$this->pixelId}/events", $payload);
        } catch (\Throwable $e) {
            $result = [
                'success' => false,
                'http_status' => null,
                'error_code' => null,
                'error_type' => null,
                'error_message' => $this->sanitize($e->getMessage()),
            ];

            Log::warning('[MetaCAPI] Purchase send failed (exception)', $context + [
                'error_message' => $result['error_message'],
            ]);

            return $result;
        }

        $json = $response->json() ?? [];
        $metaError = $json['error'] ?? null; // Meta can return an application-level error even on a 200

        $result = [
            'success' => $response->successful() && ! $metaError,
            'http_status' => $response->status(),
            'error_code' => $metaError['code'] ?? null,
            'error_type' => $metaError['type'] ?? null,
            'error_message' => $metaError ? $this->sanitize((string) ($metaError['message'] ?? 'Unknown Meta error')) : null,
        ];

        if ($result['success']) {
            Log::info('[MetaCAPI] Purchase sent', $context + ['http_status' => $result['http_status']]);
        } else {
            Log::warning('[MetaCAPI] Purchase send failed', $context + [
                'http_status' => $result['http_status'],
                'error_code' => $result['error_code'],
                'error_type' => $result['error_type'],
                'error_message' => $result['error_message'],
            ]);
        }

        return $result;
    }

    /**
     * Validates the Pixel/Dataset ID + access token pair without ever
     * creating a tracking event (no Purchase, no synthetic event of any
     * kind) — so it cannot pollute a tenant's ad reporting or interact
     * with Test Mode/Test Event Code, both of which only apply to actual
     * events.
     *
     * Deliberately hits the SAME endpoint+verb as sendPurchase()
     * (POST /{pixel_id}/events) rather than a plain GET on the pixel node.
     * A CAPI-only access token (the kind Events Manager's "Generate Access
     * Token" produces) is routinely NOT permissioned to read the pixel
     * node directly — that GET returns "(#100) Missing Permission" even
     * when the token is fully valid for sending real events, which is a
     * false negative. Posting an intentionally empty `data` array reaches
     * the real permission check first; Meta then rejects the payload
     * itself ("data must be non-empty") without creating anything, so a
     * validation-shaped error here means the credentials are good.
     *
     * @return array{success: bool, http_status: ?int, message: string}
     */
    public function testConnection(): array
    {
        try {
            $response = Http::post("{$this->base}/{$this->pixelId}/events", [
                'access_token' => $this->accessToken,
                'data' => [],
            ]);
        } catch (\Throwable $e) {
            Log::warning('[MetaCAPI] Test connection failed (exception)', [
                'tenant_id' => app()->bound('currentTenant') ? app('currentTenant')->id : null,
                'error_message' => $this->sanitize($e->getMessage()),
                'timestamp' => now()->toIso8601String(),
            ]);

            return ['success' => false, 'http_status' => null, 'message' => 'Meta সার্ভারে পৌঁছানো যায়নি।'];
        }

        $json = $response->json() ?? [];
        $metaError = $json['error'] ?? null;
        $message = (string) ($metaError['message'] ?? '');

        // Reaching Meta's payload validation (rather than being rejected
        // for permission) proves the token can authenticate and is
        // authorized to send events for this pixel/dataset.
        $authorizedForEvents = $metaError && str_contains(strtolower($message), 'non-empty');

        if ($authorizedForEvents || ($response->successful() && ! $metaError)) {
            return ['success' => true, 'http_status' => $response->status(), 'message' => 'সংযোগ সফল — Pixel/Dataset ID ও Access Token সঠিক এবং ইভেন্ট পাঠানোর অনুমতি আছে।'];
        }

        $sanitizedMessage = $this->sanitize($message ?: 'Pixel ID অথবা Access Token সঠিক নয়।');

        Log::warning('[MetaCAPI] Test connection rejected by Meta', [
            'tenant_id' => app()->bound('currentTenant') ? app('currentTenant')->id : null,
            'http_status' => $response->status(),
            'error_code' => $metaError['code'] ?? null,
            'error_type' => $metaError['type'] ?? null,
            'error_message' => $sanitizedMessage,
            'timestamp' => now()->toIso8601String(),
        ]);

        return ['success' => false, 'http_status' => $response->status(), 'message' => $sanitizedMessage];
    }

    /** Defense in depth: strip any access_token that could have ended up in a URL/message. */
    protected function sanitize(string $message): string
    {
        return preg_replace('/access_token=[^&\s"]+/i', 'access_token=[redacted]', $message) ?? $message;
    }

    protected function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone);

        // 01712345678 -> 8801712345678
        if (str_starts_with($digits, '01')) {
            $digits = '88'.$digits;
        }

        return $digits;
    }
}
