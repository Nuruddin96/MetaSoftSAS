<?php

namespace App\Services\Courier;

use App\Models\Order;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class PathaoService implements CourierService
{
    protected string $baseUrl = 'https://api-hermes.pathao.com';

    public function __construct(
        protected string $clientId,
        protected string $clientSecret,
        protected string $username,
        protected string $password,
        protected string $storeId,
    ) {}

    protected function token(): string
    {
        $cacheKey = 'pathao_token_' . app('currentTenant')->id;

        return Cache::remember($cacheKey, 3600 * 6, function () {
            $response = Http::post($this->baseUrl . '/aladdin/api/v1/issue-token', [
                'client_id'     => $this->clientId,
                'client_secret' => $this->clientSecret,
                'username'      => $this->username,
                'password'      => $this->password,
                'grant_type'    => 'password',
            ])->throw()->json();

            return $response['access_token'];
        });
    }

    public function createShipment(Order $order): array
    {
        // NOTE: Pathao requires numeric city/zone ids. We send the address as-is
        // and let the merchant panel resolve it; for full automation, city/zone
        // lookup endpoints can be wired later.
        $response = Http::withToken($this->token())
            ->post($this->baseUrl . '/aladdin/api/v1/orders', [
                'store_id'            => $this->storeId,
                'merchant_order_id'   => $order->order_number,
                'recipient_name'      => $order->customer_name,
                'recipient_phone'     => $order->customer_phone,
                'recipient_address'   => $order->customer_address,
                'delivery_type'       => 48,
                'item_type'           => 2,
                'item_quantity'       => $order->items->sum('quantity'),
                'item_weight'         => 0.5,
                'amount_to_collect'   => $order->payment_method === 'cod'
                                            ? (float) $order->total - (float) $order->paid_amount : 0,
                'item_description'    => $order->items->pluck('product_name')->implode(', '),
            ])->throw()->json();

        return [
            'consignment_id' => $response['data']['consignment_id'] ?? null,
            'tracking_code'  => $response['data']['consignment_id'] ?? null,
        ];
    }

    public function getStatus(Order $order): string
    {
        return $order->courier_status ?? 'unknown';
    }

    public function checkPhoneHistory(string $phone): array
    {
        // Pathao has no public fraud-check endpoint
        return ['total' => 0, 'delivered' => 0, 'returned' => 0];
    }
}
