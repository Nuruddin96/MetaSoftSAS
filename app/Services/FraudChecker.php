<?php

namespace App\Services;

use App\Models\FraudCheckLog;
use App\Models\Order;
use App\Services\Courier\CourierManager;

class FraudChecker
{
    /**
     * This tenant's OWN order history for a phone number — complements
     * check()'s courier-wide history. Nothing else in the project computes
     * this yet, so it lives here as a small additive method rather than a
     * separate service, next to the courier-based check it's shown
     * alongside on the order/pending-order screen.
     *
     * @return array{phone: string, total: int, delivered: int, cancelled: int, returned: int, pending: int}
     */
    public function internalHistory(string $phone): array
    {
        $phone = $this->normalizePhone($phone);

        $counts = Order::where('customer_phone', $phone)
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        return [
            'phone' => $phone,
            'total' => (int) $counts->sum(),
            'delivered' => (int) ($counts['delivered'] ?? 0),
            'cancelled' => (int) ($counts['cancelled'] ?? 0),
            'returned' => (int) ($counts['returned'] ?? 0),
            'pending' => (int) ($counts['pending'] ?? 0),
        ];
    }

    protected function normalizePhone(string $phone): string
    {
        $phone = preg_replace('/\D/', '', $phone);
        if (str_starts_with($phone, '880')) {
            $phone = '0'.substr($phone, 3);
        }

        return $phone;
    }

    /**
     * $checkedBy defaults to the `tenant` session guard for the web panel's
     * caller; the mobile API has no session guard (Sanctum-only — see
     * BindTenantFromSanctumUser's docblock) so its controller must pass
     * $request->user()->id explicitly or every mobile-originated log row
     * silently loses attribution.
     */
    public function check(string $phone, ?int $checkedBy = null): array
    {
        $phone = $this->normalizePhone($phone);

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
            'checked_by' => $checkedBy ?? auth('tenant')->id(),
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
