<?php

namespace App\Services\Api;

use App\Models\Order;
use App\Services\Courier\CourierManager;
use Illuminate\Support\Str;

/**
 * Mobile-API equivalent of Tenant\CourierController::send() — same guard
 * checks (already-sent, blocked-status), same CourierManager call, same
 * friendly-error mapping. Not wired into the existing CourierController
 * (left untouched) — see OrderCreationService's doc comment for the same
 * "documented duplication over refactor risk" reasoning.
 */
class CourierDispatchService
{
    protected const BLOCKED_STATUSES = ['delivered', 'cancelled', 'returned'];

    /** @throws \RuntimeException with a Bengali, user-facing message on any failure */
    public function dispatch(Order $order, string $provider): Order
    {
        if ($order->courier_consignment_id) {
            throw new \RuntimeException('এই অর্ডার আগেই কুরিয়ারে পাঠানো হয়েছে ('.$order->courier_provider.')।');
        }

        if (in_array($order->status, self::BLOCKED_STATUSES, true)) {
            throw new \RuntimeException('এই অর্ডারের স্ট্যাটাস ইতিমধ্যে চূড়ান্ত ('.$order->status.') — কুরিয়ারে পাঠানো যাবে না।');
        }

        $service = CourierManager::forProvider($provider);

        if (! $service) {
            throw new \RuntimeException('কুরিয়ারের API সেটিংস পাওয়া যায়নি — সেটিংস পেজে ক্রেডেনশিয়াল দিন।');
        }

        try {
            $result = $service->createShipment($order);
        } catch (\Throwable $e) {
            $msg = $e->getMessage();

            $friendly = match (true) {
                str_contains($msg, '401') || str_contains($msg, 'not active') => 'কুরিয়ার অ্যাকাউন্ট এখনো সক্রিয় নয়। '.ucfirst($provider).'-এর সাপোর্টে যোগাযোগ করে API অ্যাক্সেস চালু করান, অথবা Key দুটো আবার মিলিয়ে দেখুন।',
                str_contains($msg, '403') => 'এই API ব্যবহারের অনুমতি নেই।',
                str_contains($msg, '422') => 'অর্ডারের তথ্যে সমস্যা — ঠিকানা বা ফোন নাম্বার যাচাই করুন।',
                default => 'কুরিয়ারে পাঠানো যায়নি: '.Str::limit($msg, 120),
            };

            throw new \RuntimeException($friendly);
        }

        $order->update([
            'courier_provider' => $provider,
            'courier_consignment_id' => $result['consignment_id'],
            'courier_tracking_code' => $result['tracking_code'],
            'courier_status' => 'pending',
            'status' => $order->status === 'pending' ? 'processing' : $order->status,
        ]);

        return $order->fresh();
    }

    /**
     * Mobile-API equivalent of Tenant\CourierController::refreshStatus() —
     * same CourierManager::getStatus() call (genuinely live for Steadfast;
     * a confirming no-op for Pathao, which has no public status-lookup
     * endpoint — see that method's own doc comment on the Tenant side).
     *
     * @throws \RuntimeException with a Bengali, user-facing message on any failure
     */
    public function refreshStatus(Order $order): Order
    {
        if (! $order->courier_provider || ! $order->courier_consignment_id) {
            throw new \RuntimeException('এই অর্ডার এখনো কুরিয়ারে পাঠানো হয়নি।');
        }

        $service = CourierManager::forProvider($order->courier_provider);

        if (! $service) {
            throw new \RuntimeException('কুরিয়ারের API সেটিংস পাওয়া যায়নি — সেটিংস পেজে ক্রেডেনশিয়াল দিন।');
        }

        try {
            $status = $service->getStatus($order);
        } catch (\Throwable $e) {
            throw new \RuntimeException('স্ট্যাটাস রিফ্রেশ করা যায়নি: '.Str::limit($e->getMessage(), 120));
        }

        $order->update(['courier_status' => $status]);

        return $order->fresh();
    }
}
