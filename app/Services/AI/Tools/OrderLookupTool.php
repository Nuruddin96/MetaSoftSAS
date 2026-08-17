<?php

namespace App\Services\AI\Tools;

use App\Models\Order;

/**
 * Read-only order lookup — reuses Order/OrderItem exactly as they're
 * already stored (see Tenant\OrderController::index() for the equivalent
 * panel query this mirrors), no new query logic invented. Every query is
 * explicitly scoped by the server-supplied $tenantId via
 * withoutGlobalScopes(), the same pattern App\Jobs\ProcessAiAgentMessage
 * already established for non-HTTP-request contexts where
 * app('currentTenant') is never bound.
 */
class OrderLookupTool implements AiTool
{
    protected const ALLOWED_STATUSES = ['pending', 'confirmed', 'processing', 'shipped', 'delivered', 'cancelled', 'returned'];

    public function name(): string
    {
        return 'lookup_orders';
    }

    public function description(): string
    {
        return 'Look up orders for the current store by exact order number, customer phone, and/or status. Read-only — never creates, updates, or cancels anything. Returns order details including line items, totals, payment status, and courier status.';
    }

    public function parametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'order_number' => ['type' => 'string', 'description' => 'Exact order number, e.g. ORD-000123'],
                'customer_phone' => ['type' => 'string', 'description' => 'Customer phone number to search orders for'],
                'status' => ['type' => 'string', 'enum' => self::ALLOWED_STATUSES, 'description' => 'Filter by order status'],
                'limit' => ['type' => 'integer', 'description' => 'Maximum number of orders to return (default 5, max 20)'],
            ],
        ];
    }

    public function isMutating(): bool
    {
        return false;
    }

    public function handle(int $tenantId, array $args): array
    {
        $limit = min(max((int) ($args['limit'] ?? 5), 1), 20);

        $status = $args['status'] ?? null;
        $status = in_array($status, self::ALLOWED_STATUSES, true) ? $status : null;

        $orders = Order::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->when(! empty($args['order_number']), fn ($q) => $q->where('order_number', $args['order_number']))
            ->when(! empty($args['customer_phone']), fn ($q) => $q->where('customer_phone', $args['customer_phone']))
            ->when($status, fn ($q) => $q->where('status', $status))
            ->with('items')
            ->latest()
            ->limit($limit)
            ->get();

        return [
            'count' => $orders->count(),
            'orders' => $orders->map(fn (Order $order) => [
                'order_number' => $order->order_number,
                'status' => $order->status,
                'payment_status' => $order->payment_status,
                'payment_method' => $order->payment_method,
                'customer_name' => $order->customer_name,
                'customer_phone' => $order->customer_phone,
                'subtotal' => (float) $order->subtotal,
                'discount' => (float) $order->discount,
                'delivery_charge' => (float) $order->delivery_charge,
                'total' => (float) $order->total,
                'courier_provider' => $order->courier_provider,
                'courier_status' => $order->courier_status,
                'created_at' => $order->created_at?->toDateTimeString(),
                'items' => $order->items->map(fn ($item) => [
                    'product_name' => $item->product_name,
                    'variant_name' => $item->variant_name,
                    'quantity' => $item->quantity,
                    'unit_price' => (float) $item->unit_price,
                    'line_total' => (float) $item->line_total,
                ])->all(),
            ])->all(),
        ];
    }
}
