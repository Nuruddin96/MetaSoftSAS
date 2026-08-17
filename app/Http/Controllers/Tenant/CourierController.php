<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Courier\CourierManager;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CourierController extends Controller
{
    /** Orders in these states must never be pushed to a courier — sending an already-closed order is a data-integrity bug, not a valid retry. */
    protected const BLOCKED_STATUSES = ['delivered', 'cancelled', 'returned'];

    public function send(Request $request, Order $order)
    {
        // Defense-in-depth: implicit route-model binding on {order} is
        // expected to already be scoped to the current tenant via Order's
        // BelongsToTenant global scope, but this explicit check is kept
        // regardless — never trust a bound model's tenant_id implicitly for
        // an action this consequential (dispatches a real courier shipment).
        // Same pattern OrderController::complete() already uses.
        abort_if(! app()->bound('currentTenant') || $order->tenant_id !== app('currentTenant')->id, 404);

        $data = $request->validate(['provider' => 'required|in:steadfast,pathao']);

        if ($order->courier_consignment_id) {
            return back()->with('error', 'এই অর্ডার আগেই কুরিয়ারে পাঠানো হয়েছে ('.$order->courier_provider.')।');
        }

        if (in_array($order->status, self::BLOCKED_STATUSES, true)) {
            return back()->with('error', 'এই অর্ডারের স্ট্যাটাস ইতিমধ্যে চূড়ান্ত ('.$order->status.') — কুরিয়ারে পাঠানো যাবে না।');
        }

        $service = CourierManager::forProvider($data['provider']);

        if (! $service) {
            return back()->with('error', 'কুরিয়ারের API সেটিংস পাওয়া যায়নি — সেটিংস পেজে ক্রেডেনশিয়াল দিন।');
        }

        try {
            $result = $service->createShipment($order);
        } catch (\Throwable $e) {
            $msg = $e->getMessage();

            $friendly = match (true) {
                str_contains($msg, '401') || str_contains($msg, 'not active') => 'কুরিয়ার অ্যাকাউন্ট এখনো সক্রিয় নয়। '.ucfirst($data['provider']).'-এর সাপোর্টে যোগাযোগ করে API অ্যাক্সেস চালু করান, অথবা Key দুটো আবার মিলিয়ে দেখুন।',
                str_contains($msg, '403') => 'এই API ব্যবহারের অনুমতি নেই।',
                str_contains($msg, '422') => 'অর্ডারের তথ্যে সমস্যা — ঠিকানা বা ফোন নাম্বার যাচাই করুন।',
                default => 'কুরিয়ারে পাঠানো যায়নি: '.Str::limit($msg, 120),
            };

            return back()->with('error', $friendly);
        }

        $order->update([
            'courier_provider' => $data['provider'],
            'courier_consignment_id' => $result['consignment_id'],
            'courier_tracking_code' => $result['tracking_code'],
            'courier_status' => 'pending',
            'status' => $order->status === 'pending' ? 'processing' : $order->status,
        ]);

        return back()->with('success', 'অর্ডারটি '.ucfirst($data['provider']).'-এ পাঠানো হয়েছে। কনসাইনমেন্ট: '.$result['consignment_id']);
    }

    /**
     * Calls the courier's own getStatus() — genuinely live for Steadfast
     * (hits /status_by_invoice/{invoice}); Pathao's implementation is a
     * stub that just echoes the already-stored value back (Pathao has no
     * public status-lookup endpoint currently wired), so this action is a
     * no-op there beyond confirming the current value. Previously
     * getStatus() existed on both providers but was never called from
     * anywhere in the app — courier_status was write-once at send time and
     * could never actually change.
     */
    public function refreshStatus(Order $order)
    {
        abort_if(! app()->bound('currentTenant') || $order->tenant_id !== app('currentTenant')->id, 404);

        if (! $order->courier_provider || ! $order->courier_consignment_id) {
            return back()->with('error', 'এই অর্ডার এখনো কুরিয়ারে পাঠানো হয়নি।');
        }

        $service = CourierManager::forProvider($order->courier_provider);

        if (! $service) {
            return back()->with('error', 'কুরিয়ারের API সেটিংস পাওয়া যায়নি — সেটিংস পেজে ক্রেডেনশিয়াল দিন।');
        }

        try {
            $status = $service->getStatus($order);
        } catch (\Throwable $e) {
            return back()->with('error', 'স্ট্যাটাস রিফ্রেশ করা যায়নি: '.Str::limit($e->getMessage(), 120));
        }

        $order->update(['courier_status' => $status]);

        return back()->with('success', 'কুরিয়ার স্ট্যাটাস আপডেট হয়েছে: '.$status);
    }
}
