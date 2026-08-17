<?php

namespace App\Services\AI\Tools;

use App\Models\Order;
use App\Services\Courier\CourierManager;
use Illuminate\Support\Str;

/**
 * Sends an existing order to a courier (Steadfast/Pathao). HIGH RISK —
 * see AiMutatingTool docblock. Reuses CourierManager exactly like
 * Tenant\CourierController::send() does — same BLOCKED_STATUSES guard,
 * same "already sent" check, same friendly-error mapping for common
 * courier API failures.
 *
 * One deliberate exception to AiTool::handle()'s "never rely on ambient
 * app('currentTenant')" rule: CourierManager::forProvider() itself reads
 * CourierSetting through the ambient BelongsToTenant scope, not an
 * explicit tenant_id parameter — reusing it as-is (rather than
 * duplicating its provider-instantiation logic here) means this tool
 * only resolves the correct tenant's courier credentials when
 * app('currentTenant') is bound to $tenantId, which is true for its only
 * real caller today (Tenant\AiChatController::confirm(), always inside
 * an authenticated tenant request). If this tool is ever invoked from a
 * context where that doesn't hold, courier credential lookup would be
 * unscoped — the order lookup itself, above, is NOT affected (it is
 * always explicitly tenant-scoped) and correctly refuses a
 * cross-tenant order regardless.
 */
class CourierActionTool implements AiMutatingTool
{
    protected const BLOCKED_STATUSES = ['delivered', 'cancelled', 'returned'];

    protected const ALLOWED_PROVIDERS = ['steadfast', 'pathao'];

    public function name(): string
    {
        return 'send_order_to_courier';
    }

    public function description(): string
    {
        return 'Sends an existing, already-confirmed order to a courier (steadfast or pathao) for delivery. HIGH RISK — only proposes the action for the store owner to explicitly confirm; never executed immediately. Use lookup_orders first to find the exact order_number.';
    }

    public function parametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'order_number' => ['type' => 'string', 'description' => 'Exact order number, e.g. ORD-000123'],
                'provider' => ['type' => 'string', 'enum' => self::ALLOWED_PROVIDERS],
            ],
            'required' => ['order_number', 'provider'],
        ];
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function preview(int $tenantId, array $args): array
    {
        $orderNumber = trim((string) ($args['order_number'] ?? ''));
        $provider = $args['provider'] ?? null;

        if (! in_array($provider, self::ALLOWED_PROVIDERS, true)) {
            return ['error' => 'কুরিয়ার প্রোভাইডার steadfast অথবা pathao হতে হবে।'];
        }

        $order = Order::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('order_number', $orderNumber)->first();

        if (! $order) {
            return ['error' => "\"{$orderNumber}\" নম্বরে কোনো অর্ডার পাওয়া যায়নি।"];
        }

        if ($order->courier_consignment_id) {
            return ['error' => "এই অর্ডার আগেই কুরিয়ারে পাঠানো হয়েছে ({$order->courier_provider})।"];
        }

        if (in_array($order->status, self::BLOCKED_STATUSES, true)) {
            return ['error' => "এই অর্ডারের স্ট্যাটাস ইতিমধ্যে চূড়ান্ত ({$order->status}) — কুরিয়ারে পাঠানো যাবে না।"];
        }

        if (! CourierManager::forProvider($provider)) {
            return ['error' => 'কুরিয়ারের API সেটিংস পাওয়া যায়নি — Settings পেজে ক্রেডেনশিয়াল দিন।'];
        }

        $resolvedArgs = ['order_id' => $order->id, 'order_number' => $order->order_number, 'provider' => $provider];

        $summary = "অর্ডার {$order->order_number} কে ".ucfirst($provider)." কুরিয়ারে পাঠানো হবে (কাস্টমার: {$order->customer_name}, ৳".number_format((float) $order->total, 2).')।';

        return ['summary' => $summary, 'resolved_args' => $resolvedArgs];
    }

    public function handle(int $tenantId, array $args): array
    {
        $order = Order::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('id', $args['order_id'])->first();

        if (! $order) {
            return ['success' => false, 'message' => 'অর্ডারটি আর পাওয়া যাচ্ছে না।'];
        }

        // Re-verify at execution time — state may have changed since
        // preview() ran (e.g. sent to a courier through the panel in the
        // meantime, or the order was cancelled).
        if ($order->courier_consignment_id) {
            return ['success' => false, 'message' => "এই অর্ডার ইতিমধ্যে কুরিয়ারে পাঠানো হয়েছে ({$order->courier_provider})।"];
        }

        if (in_array($order->status, self::BLOCKED_STATUSES, true)) {
            return ['success' => false, 'message' => "এই অর্ডারের স্ট্যাটাস ইতিমধ্যে চূড়ান্ত ({$order->status}) — কুরিয়ারে পাঠানো যায়নি।"];
        }

        $provider = $args['provider'];
        $service = CourierManager::forProvider($provider);

        if (! $service) {
            return ['success' => false, 'message' => 'কুরিয়ারের API সেটিংস পাওয়া যায়নি।'];
        }

        try {
            $result = $service->createShipment($order);
        } catch (\Throwable $e) {
            // Never surface $e->getMessage() raw — same caution
            // Tenant\CourierController::send() already applies (a courier
            // API error can echo request/credential details).
            $msg = $e->getMessage();

            $friendly = match (true) {
                str_contains($msg, '401') || str_contains($msg, 'not active') => 'কুরিয়ার অ্যাকাউন্ট এখনো সক্রিয় নয়।',
                str_contains($msg, '403') => 'এই API ব্যবহারের অনুমতি নেই।',
                str_contains($msg, '422') => 'অর্ডারের তথ্যে সমস্যা — ঠিকানা বা ফোন নাম্বার যাচাই করুন।',
                default => 'কুরিয়ারে পাঠানো যায়নি: '.Str::limit($msg, 120),
            };

            return ['success' => false, 'message' => $friendly];
        }

        $order->update([
            'courier_provider' => $provider,
            'courier_consignment_id' => $result['consignment_id'],
            'courier_tracking_code' => $result['tracking_code'],
            'courier_status' => 'pending',
            'status' => $order->status === 'pending' ? 'processing' : $order->status,
        ]);

        return [
            'success' => true,
            'message' => "অর্ডার {$order->order_number} ".ucfirst($provider)."-এ পাঠানো হয়েছে। কনসাইনমেন্ট: {$result['consignment_id']}",
            'consignment_id' => $result['consignment_id'],
        ];
    }
}
