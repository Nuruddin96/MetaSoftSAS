<?php

namespace App\Services\Courier;

use App\Models\Order;
use Illuminate\Support\Facades\Http;

class SteadfastService implements CourierService
{
    protected string $baseUrl = 'https://portal.packzy.com/api/v1';

    public function __construct(
        protected string $apiKey,
        protected string $secretKey,
    ) {}

    protected function headers(): array
    {
        return [
            'Api-Key'      => $this->apiKey,
            'Secret-Key'   => $this->secretKey,
            'Content-Type' => 'application/json',
        ];
    }

    public function createShipment(Order $order): array
    {
        $response = Http::withHeaders($this->headers())
            ->post($this->baseUrl . '/create_order', [
                'invoice'           => $order->order_number,
                'recipient_name'    => $order->customer_name,
                'recipient_phone'   => $order->customer_phone,
                'recipient_address' => $order->customer_address,
                'cod_amount'        => $order->payment_method === 'cod'
                                        ? (float) $order->total - (float) $order->paid_amount
                                        : 0,
                'note'              => $order->note,
            ])->throw()->json();

        return [
            'consignment_id' => $response['consignment']['consignment_id'] ?? null,
            'tracking_code'  => $response['consignment']['tracking_code'] ?? null,
        ];
    }

    public function getStatus(Order $order): string
    {
        $response = Http::withHeaders($this->headers())
            ->get($this->baseUrl . '/status_by_invoice/' . $order->order_number)
            ->throw()->json();

        return $response['delivery_status'] ?? 'unknown';
    }

    public function checkPhoneHistory(string $phone): array
    {
        // Steadfast fraud-check endpoint (verify current path in their docs;
        // they have changed it between versions).
        $response = Http::withHeaders($this->headers())
            ->get($this->baseUrl . '/fraud_check/' . $phone)
            ->json();

        return [
            'total'     => (int) ($response['total_delivered'] ?? 0) + (int) ($response['total_cancelled'] ?? 0),
            'delivered' => (int) ($response['total_delivered'] ?? 0),
            'returned'  => (int) ($response['total_cancelled'] ?? 0),
        ];
    }
}
