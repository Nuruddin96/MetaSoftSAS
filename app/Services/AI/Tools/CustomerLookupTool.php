<?php

namespace App\Services\AI\Tools;

use App\Models\Customer;

/**
 * Read-only customer lookup — reuses Customer's own stored aggregates
 * (total_orders/total_spent/due_balance) exactly as
 * Tenant\CustomerController::index()/show() already do, rather than
 * recomputing them from orders here. Explicitly scoped by the
 * server-supplied $tenantId — see OrderLookupTool's docblock for why.
 *
 * Deliberately NOT wired into the public Messenger auto-reply flow (see
 * the Phase 3 report) — customer data (phone, address, due balance,
 * spend history) must never be reachable by an anonymous Messenger
 * visitor asking about a DIFFERENT customer. This tool exists for a
 * future tenant-authenticated AI surface only.
 */
class CustomerLookupTool implements AiTool
{
    public function name(): string
    {
        return 'lookup_customers';
    }

    public function description(): string
    {
        return "Search the current store's customers by phone number or name. Read-only. Returns each matching customer's contact info, total orders, total spent, and any outstanding due balance.";
    }

    public function parametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'phone' => ['type' => 'string', 'description' => 'Exact customer phone number'],
                'name' => ['type' => 'string', 'description' => 'Customer name to search for (partial match)'],
                'limit' => ['type' => 'integer', 'description' => 'Maximum number of customers to return (default 5, max 20)'],
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

        $customers = Customer::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->when(! empty($args['phone']), fn ($q) => $q->where('phone', $args['phone']))
            ->when(! empty($args['name']), fn ($q) => $q->where('name', 'like', '%'.$args['name'].'%'))
            ->latest()
            ->limit($limit)
            ->get();

        return [
            'count' => $customers->count(),
            'customers' => $customers->map(fn (Customer $customer) => [
                'name' => $customer->name,
                'phone' => $customer->phone,
                'total_orders' => (int) $customer->total_orders,
                'total_spent' => (float) $customer->total_spent,
                'due_balance' => (float) $customer->due_balance,
            ])->all(),
        ];
    }
}
