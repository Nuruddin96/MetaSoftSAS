<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Courier\CourierManager;
use Illuminate\Http\Request;

class CourierController extends Controller
{
    public function send(Request $request, Order $order)
    {
        $data = $request->validate(['provider' => 'required|in:steadfast,pathao']);

        if ($order->courier_consignment_id) {
            return back()->with('error', 'এই অর্ডার আগেই কুরিয়ারে পাঠানো হয়েছে (' . $order->courier_provider . ')।');
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
                str_contains($msg, '401') || str_contains($msg, 'not active')
                    => 'কুরিয়ার অ্যাকাউন্ট এখনো সক্রিয় নয়। ' . ucfirst($data['provider']) . '-এর সাপোর্টে যোগাযোগ করে API অ্যাক্সেস চালু করান, অথবা Key দুটো আবার মিলিয়ে দেখুন।',
                str_contains($msg, '403') => 'এই API ব্যবহারের অনুমতি নেই।',
                str_contains($msg, '422') => 'অর্ডারের তথ্যে সমস্যা — ঠিকানা বা ফোন নাম্বার যাচাই করুন।',
                default => 'কুরিয়ারে পাঠানো যায়নি: ' . \Illuminate\Support\Str::limit($msg, 120),
            };

            return back()->with('error', $friendly);
        }

        $order->update([
            'courier_provider'       => $data['provider'],
            'courier_consignment_id' => $result['consignment_id'],
            'courier_tracking_code'  => $result['tracking_code'],
            'courier_status'         => 'pending',
            'status'                 => $order->status === 'pending' ? 'processing' : $order->status,
        ]);

        return back()->with('success', 'অর্ডারটি ' . ucfirst($data['provider']) . '-এ পাঠানো হয়েছে। কনসাইনমেন্ট: ' . $result['consignment_id']);
    }
}
