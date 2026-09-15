<?php

namespace App\Services\Api;

use App\Models\Customer;
use App\Models\DueLedger;
use Illuminate\Support\Facades\DB;

/**
 * Mirrors Tenant\CustomerController::addDue()/receiveDue() exactly (same
 * transaction shape, same ledger row shape, same receiveDue clamp to the
 * current balance). Not wired into the existing controller — see
 * OrderCreationService's doc comment for the duplication-vs-refactor-risk
 * reasoning applied consistently across this API layer.
 */
class CustomerDueService
{
    public function addDue(Customer $customer, float $amount, ?string $note, ?int $actingUserId): Customer
    {
        DB::transaction(function () use ($customer, $amount, $note, $actingUserId) {
            $customer->increment('due_balance', $amount);

            DueLedger::create([
                'customer_id' => $customer->id,
                'type' => 'due',
                'amount' => $amount,
                'balance_after' => $customer->fresh()->due_balance,
                'note' => $note ?? 'ম্যানুয়ালি বাকি যোগ করা হয়েছে',
                'user_id' => $actingUserId,
            ]);
        });

        return $customer->fresh();
    }

    /** @throws \RuntimeException if the customer has no due balance to receive against */
    public function receiveDue(Customer $customer, float $amount, ?string $note, ?int $actingUserId): Customer
    {
        $applied = min($amount, (float) $customer->due_balance);

        if ($applied <= 0) {
            throw new \RuntimeException('এই কাস্টমারের কোনো বাকি নেই।');
        }

        DB::transaction(function () use ($customer, $applied, $note, $actingUserId) {
            $customer->decrement('due_balance', $applied);

            DueLedger::create([
                'customer_id' => $customer->id,
                'type' => 'payment',
                'amount' => $applied,
                'balance_after' => $customer->fresh()->due_balance,
                'note' => $note ?? 'বাকি আদায়',
                'user_id' => $actingUserId,
            ]);
        });

        return $customer->fresh();
    }
}
