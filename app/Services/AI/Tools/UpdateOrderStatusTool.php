<?php

namespace App\Services\AI\Tools;

use App\Models\Order;

/**
 * Phase 11 — updates an existing order's status (e.g. "ORD-000123 কে
 * shipped করে দাও"). HIGH RISK — see AiMutatingTool docblock. Mirrors
 * Tenant\OrderController::updateStatus() exactly: same valid status list,
 * same confirmed_at/delivered_at side effects, no extra business rules
 * invented on top (that controller allows any status -> any status
 * freely, with no transition restriction, so this tool does too — adding
 * a stricter rule here than the human panel already enforces would be
 * new, undiscussed behavior, not a mirror of an existing capability).
 */
class UpdateOrderStatusTool implements AiMutatingTool
{
    protected const VALID_STATUSES = ['pending', 'confirmed', 'processing', 'shipped', 'delivered', 'cancelled', 'returned'];

    public function name(): string
    {
        return 'update_order_status';
    }

    public function description(): string
    {
        return 'Updates an existing order\'s status (pending, confirmed, processing, shipped, delivered, cancelled, or returned). HIGH RISK — only proposes the change for the store owner to explicitly confirm; never executed immediately. Use lookup_orders first to find the exact order_number and its current status.';
    }

    public function parametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'order_number' => ['type' => 'string', 'description' => 'Exact order number, e.g. ORD-000123'],
                'status' => ['type' => 'string', 'enum' => self::VALID_STATUSES],
            ],
            'required' => ['order_number', 'status'],
        ];
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function preview(int $tenantId, array $args): array
    {
        $orderNumber = trim((string) ($args['order_number'] ?? ''));
        $status = $args['status'] ?? null;

        if (! in_array($status, self::VALID_STATUSES, true)) {
            return ['error' => 'অর্ডার স্ট্যাটাস অবশ্যই এই তালিকা থেকে হতে হবে: '.implode(', ', self::VALID_STATUSES)];
        }

        $order = Order::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('order_number', $orderNumber)->first();

        if (! $order) {
            return ['error' => "\"{$orderNumber}\" নম্বরে কোনো অর্ডার পাওয়া যায়নি।"];
        }

        if ($order->status === $status) {
            return ['error' => "এই অর্ডারের স্ট্যাটাস ইতিমধ্যে \"{$status}\"।"];
        }

        $resolvedArgs = ['order_id' => $order->id, 'order_number' => $order->order_number, 'status' => $status];

        $summary = "অর্ডার {$order->order_number} এর স্ট্যাটাস \"{$order->status}\" থেকে \"{$status}\" এ পরিবর্তন করা হবে (কাস্টমার: {$order->customer_name})।";

        return ['summary' => $summary, 'resolved_args' => $resolvedArgs];
    }

    public function handle(int $tenantId, array $args): array
    {
        $order = Order::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('id', $args['order_id'] ?? null)->first();

        if (! $order) {
            return ['success' => false, 'message' => 'অর্ডারটি আর পাওয়া যাচ্ছে না।'];
        }

        $status = $args['status'] ?? null;

        // Re-validated at execution time too — resolved_args is trusted
        // (see AiMutatingTool::preview()'s docblock), but this stays cheap
        // defense-in-depth against a malformed stored pending action.
        if (! in_array($status, self::VALID_STATUSES, true)) {
            return ['success' => false, 'message' => 'অবৈধ স্ট্যাটাস।'];
        }

        $order->update([
            'status' => $status,
            'confirmed_at' => $status === 'confirmed' ? now() : $order->confirmed_at,
            'delivered_at' => $status === 'delivered' ? now() : $order->delivered_at,
        ]);

        return [
            'success' => true,
            'message' => "অর্ডার {$order->order_number} এর স্ট্যাটাস \"{$status}\" এ আপডেট হয়েছে।",
        ];
    }
}
