<?php

namespace App\Services\AI\Tools;

use App\Models\Order;
use Carbon\Carbon;

/**
 * Read-only sales summary — mirrors Tenant\ReportController::sales()'s
 * aggregation exactly (same base query shape: date range, excludes
 * cancelled/returned, grouped by status/source) rather than inventing a
 * new one. Explicitly scoped by the server-supplied $tenantId — see
 * OrderLookupTool's docblock for why.
 *
 * Deliberately NOT wired into the public Messenger auto-reply flow (see
 * the Phase 3 report) — revenue/order-count is business-sensitive data
 * that must never be reachable by an anonymous Messenger visitor. This
 * tool exists for a future tenant-authenticated AI surface only.
 */
class SalesReportTool implements AiTool
{
    public function name(): string
    {
        return 'sales_report';
    }

    public function description(): string
    {
        return "Summarize the current store's sales for a date range (defaults to the current calendar month): order count, total revenue, average order value, and a breakdown by status and by sales channel. Read-only.";
    }

    public function parametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'from' => ['type' => 'string', 'description' => 'Start date, YYYY-MM-DD (default: start of current month)'],
                'to' => ['type' => 'string', 'description' => 'End date, YYYY-MM-DD (default: today)'],
            ],
        ];
    }

    public function isMutating(): bool
    {
        return false;
    }

    public function handle(int $tenantId, array $args): array
    {
        $from = ! empty($args['from']) ? Carbon::parse($args['from'])->startOfDay() : now()->startOfMonth();
        $to = ! empty($args['to']) ? Carbon::parse($args['to'])->endOfDay() : now()->endOfDay();

        $base = Order::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereBetween('created_at', [$from, $to])
            ->whereNotIn('status', ['cancelled', 'returned']);

        $byStatus = (clone $base)->selectRaw('status, COUNT(*) as c, SUM(total) as t')
            ->groupBy('status')->get()
            ->map(fn ($row) => ['status' => $row->status, 'orders' => (int) $row->c, 'revenue' => (float) $row->t])
            ->all();

        $bySource = (clone $base)->selectRaw('source, COUNT(*) as c, SUM(total) as t')
            ->groupBy('source')->get()
            ->map(fn ($row) => ['source' => $row->source, 'orders' => (int) $row->c, 'revenue' => (float) $row->t])
            ->all();

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'total_orders' => (clone $base)->count(),
            'total_revenue' => (float) (clone $base)->sum('total'),
            'average_order_value' => (float) (clone $base)->avg('total'),
            'by_status' => $byStatus,
            'by_source' => $bySource,
        ];
    }
}
