<?php

namespace App\Services\Marketing;

use App\Models\Order;
use Illuminate\Support\Facades\Http;

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
 */
class MetaCapiService
{
    public function __construct(
        protected string $pixelId,
        protected string $accessToken,
        protected ?string $testEventCode = null,
    ) {}

    public function sendPurchase(Order $order, ?string $clientIp = null, ?string $userAgent = null, ?string $fbp = null, ?string $fbc = null): array
    {
        $payload = [
            'data' => [[
                'event_name'       => 'Purchase',
                'event_time'       => now()->timestamp,
                'event_id'         => $order->fb_event_id,          // dedup with browser pixel
                'action_source'    => 'website',
                'event_source_url' => app('currentTenant')->url(),
                'user_data'        => array_filter([
                    'ph'                => [hash('sha256', $this->normalizePhone($order->customer_phone))],
                    'fn'                => [hash('sha256', mb_strtolower(trim($order->customer_name)))],
                    'client_ip_address' => $clientIp,
                    'client_user_agent' => $userAgent,
                    'fbp'               => $fbp,
                    'fbc'               => $fbc,
                ]),
                'custom_data' => [
                    'currency'  => 'BDT',
                    'value'     => (float) $order->total,
                    'order_id'  => $order->order_number,
                    'contents'  => $order->items->map(fn ($i) => [
                        'id'         => $i->sku,
                        'quantity'   => $i->quantity,
                        'item_price' => (float) $i->unit_price,
                    ])->all(),
                    'content_type' => 'product',
                ],
            ]],
        ];

        if ($this->testEventCode) {
            $payload['test_event_code'] = $this->testEventCode;
        }

        return Http::post(
            "https://graph.facebook.com/v19.0/{$this->pixelId}/events?access_token={$this->accessToken}",
            $payload
        )->json();
    }

    protected function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone);

        // 01712345678 -> 8801712345678
        if (str_starts_with($digits, '01')) {
            $digits = '88' . $digits;
        }

        return $digits;
    }
}
