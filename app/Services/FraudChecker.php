<?php

namespace App\Services;

use App\Models\FraudCheckLog;
use App\Services\Courier\CourierManager;

class FraudChecker
{
    public function check(string $phone): array
    {
        $phone = preg_replace('/\D/', '', $phone);
        if (str_starts_with($phone, '880')) {
            $phone = '0'.substr($phone, 3);
        }

        $services = CourierManager::activeServices();

        if (empty($services)) {
            return [
                'phone' => $phone,
                'verdict' => 'unconfigured',
                'message' => 'কোনো কুরিয়ার API যুক্ত নেই। সেটিংস পেজে Steadfast-এর API Key দিন।',
            ];
        }

        $perCourier = [];
        $errors = [];
        $total = $delivered = $returned = 0;

        foreach ($services as $provider => $service) {
            try {
                $history = $service->checkPhoneHistory($phone);
            } catch (\Throwable $e) {
                $msg = $e->getMessage();

                $errors[$provider] = match (true) {
                    str_contains($msg, '401') || str_contains($msg, 'not active') => 'API অ্যাকাউন্ট সক্রিয় নয় — কুরিয়ার কোম্পানির সাপোর্টে যোগাযোগ করে API অ্যাক্সেস চালু করান।',
                    str_contains($msg, '403') => 'এই API-তে অনুমতি নেই।',
                    str_contains($msg, '404') => 'ফ্রড চেক সেবা এই অ্যাকাউন্টে নেই।',
                    default => 'সংযোগ করা যায়নি।',
                };

                $perCourier[$provider] = ['error' => $errors[$provider]];

                continue;
            }

            $perCourier[$provider] = $history;
            $total += $history['total'] ?? 0;
            $delivered += $history['delivered'] ?? 0;
            $returned += $history['returned'] ?? 0;
        }

        // every courier failed — report the problem instead of pretending it's a new customer
        if (count($errors) === count($services)) {
            return [
                'phone' => $phone,
                'verdict' => 'error',
                'message' => reset($errors),
                'per_courier' => $perCourier,
            ];
        }

        $ratio = $total > 0 ? (int) round($delivered / $total * 100) : null;

        FraudCheckLog::create([
            'phone' => $phone,
            'result' => $perCourier,
            'success_ratio' => $ratio,
            'checked_by' => auth('tenant')->id(),
        ]);

        return [
            'phone' => $phone,
            'total' => $total,
            'delivered' => $delivered,
            'returned' => $returned,
            'success_ratio' => $ratio,
            'per_courier' => $perCourier,
            'errors' => $errors,
            'verdict' => match (true) {
                $ratio === null => 'new',
                $ratio >= 80 => 'safe',
                $ratio >= 50 => 'risky',
                default => 'danger',
            },
        ];
    }
}
