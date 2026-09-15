<?php

namespace App\Services\Courier;

use App\Models\Order;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
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
            'Api-Key' => $this->apiKey,
            'Secret-Key' => $this->secretKey,
            'Content-Type' => 'application/json',
        ];
    }

    public function createShipment(Order $order): array
    {
        $response = Http::withHeaders($this->headers())
            ->post($this->baseUrl.'/create_order', [
                'invoice' => $order->order_number,
                'recipient_name' => $order->customer_name,
                'recipient_phone' => $order->customer_phone,
                'recipient_address' => $order->customer_address,
                'cod_amount' => $order->payment_method === 'cod'
                                        ? (float) $order->total - (float) $order->paid_amount
                                        : 0,
                'note' => $order->note,
            ])->throw()->json();

        return [
            'consignment_id' => $response['consignment']['consignment_id'] ?? null,
            'tracking_code' => $response['consignment']['tracking_code'] ?? null,
        ];
    }

    public function getStatus(Order $order): string
    {
        $response = Http::withHeaders($this->headers())
            ->get($this->baseUrl.'/status_by_invoice/'.$order->order_number)
            ->throw()->json();

        return $response['delivery_status'] ?? 'unknown';
    }

    public function checkPhoneHistory(string $phone): array
    {
        // Steadfast fraud-check endpoint (verify current path in their docs;
        // they have changed it between versions).
        $response = Http::withHeaders($this->headers())
            ->get($this->baseUrl.'/fraud_check/'.$phone)
            ->json();

        return [
            'total' => (int) ($response['total_delivered'] ?? 0) + (int) ($response['total_cancelled'] ?? 0),
            'delivered' => (int) ($response['total_delivered'] ?? 0),
            'returned' => (int) ($response['total_cancelled'] ?? 0),
        ];
    }

    /** Current Steadfast account balance (documented `GET /get_balance` endpoint). */
    public function getBalance(): float
    {
        $response = Http::withHeaders($this->headers())
            ->get($this->baseUrl.'/get_balance')
            ->throw()->json();

        return (float) ($response['current_balance'] ?? 0);
    }

    /**
     * Balance cached per tenant for a few minutes — this hits a live 3rd-party
     * API, so the dashboard/Steadfast Center must never block on it (or fail
     * the whole page) if Steadfast is slow/down. $forceRefresh bypasses the
     * cache for an explicit user-initiated "Refresh" click.
     *
     * @return array{balance: ?float, updated_at: ?Carbon, error: ?string}
     */
    public function cachedBalance(int $tenantId, bool $forceRefresh = false): array
    {
        $cacheKey = "steadfast_balance_{$tenantId}";

        if ($forceRefresh) {
            Cache::forget($cacheKey);
        }

        try {
            $cached = Cache::remember($cacheKey, now()->addMinutes(5), fn () => [
                'balance' => $this->getBalance(),
                'at' => now()->toIso8601String(),
            ]);

            return [
                'balance' => $cached['balance'],
                'updated_at' => Carbon::parse($cached['at']),
                'error' => null,
            ];
        } catch (\Throwable $e) {
            return ['balance' => null, 'updated_at' => null, 'error' => $e->getMessage()];
        }
    }

    /**
     * Settlement payments Steadfast has already made to this merchant
     * (documented `GET /payments` — read-only history, not a request/submit
     * endpoint; see SteadfastService docblock note on why there is no
     * requestPayment()/withdraw() method here).
     */
    public function getPayments(): array
    {
        $response = Http::withHeaders($this->headers())
            ->get($this->baseUrl.'/payments')
            ->throw()->json();

        return $response['payments'] ?? $response['data'] ?? (is_array($response) ? array_values(array_filter($response, 'is_array')) : []);
    }

    /**
     * Buckets Steadfast's `delivery_status` values (pending,
     * delivered_approval_pending, partial_delivered_approval_pending,
     * cancelled_approval_pending, unknown_approval_pending, delivered,
     * partial_delivered, cancelled, hold, in_review, unknown — per Steadfast's
     * own API docs) into the 3 outcomes we can honestly distinguish. Steadfast
     * does NOT expose a granular pickup/in-transit/at-hub/out-for-delivery
     * timeline via its public API — only this single coarse status field — so
     * nothing finer-grained than this should ever be displayed as if it came
     * from Steadfast.
     */
    public static function statusBucket(?string $status): string
    {
        return match ($status) {
            'delivered', 'delivered_approval_pending', 'partial_delivered', 'partial_delivered_approval_pending' => 'delivered',
            'cancelled', 'cancelled_approval_pending' => 'cancelled',
            default => 'processing',
        };
    }
}
