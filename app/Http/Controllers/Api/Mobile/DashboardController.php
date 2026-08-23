<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\OrderResource;
use App\Services\Api\DashboardSummaryService;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request, DashboardSummaryService $service)
    {
        $tenant = app('currentTenant');
        $summary = $service->summary($tenant);

        return response()->json([
            'today_orders' => $summary['today_orders'],
            'today_sales' => $summary['today_sales'],
            'pending_orders' => $summary['pending_orders'],
            'courier_pending_count' => $summary['courier_pending_count'],
            'total_customers' => $summary['total_customers'],
            'today_expenses' => $summary['today_expenses'],
            'low_stock_count' => $summary['low_stock_count'],
            'by_channel' => $summary['by_channel'],
            'top_districts' => $summary['top_districts'],
            'more_districts_count' => $summary['more_districts_count'],
            'recent_orders' => OrderResource::collection($summary['recent_orders']),
            'checklist' => $summary['checklist'],
            'new_messages' => $summary['new_messages'],
            'new_incomplete' => $summary['new_incomplete'],
            'total_products' => $summary['total_products'],
        ]);
    }
}
