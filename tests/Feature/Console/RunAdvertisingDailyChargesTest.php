<?php

namespace Tests\Feature\Console;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\InteractsWithCommerceSchema;
use Tests\TestCase;

/**
 * Covers RunAdvertisingDailyCharges' formula: the daily automatic charge is
 * daily_budget (USD/day) × the tenant's *current* billing_rate, read fresh
 * on every run — so editing Billing Amount (billing_rate) via Super Admin
 * changes the very next automatic charge with no separate recalculation
 * step. Mirrors SuperAdmin\AdvertisingController::storeCharge()'s existing
 * meta_spend_usd × billing_rate formula; not a new calculation path.
 */
class RunAdvertisingDailyChargesTest extends TestCase
{
    use InteractsWithCommerceSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCommerceSchema();

        if (! Schema::hasTable('ad_billing_accounts')) {
            Schema::create('ad_billing_accounts', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->unique();
                $table->decimal('balance', 12, 2)->default(0);
                $table->decimal('daily_budget', 12, 2)->default(0);
                $table->decimal('billing_rate', 10, 4)->default(0);
                $table->decimal('low_balance_threshold', 12, 2)->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('ad_billing_ledger')) {
            Schema::create('ad_billing_ledger', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->string('type', 30);
                $table->decimal('amount', 12, 2);
                $table->decimal('balance_after', 12, 2);
                $table->decimal('meta_spend_usd', 12, 2)->nullable();
                $table->date('charge_date')->nullable();
                $table->string('note')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
                $table->unique(['tenant_id', 'charge_date']);
            });
        }
    }

    protected function makeAccount(array $attrs = []): int
    {
        $tenant = $this->makeTenant();

        DB::table('ad_billing_accounts')->insert(array_merge([
            'tenant_id' => $tenant->id,
            'balance' => 1000,
            'daily_budget' => 10,
            'billing_rate' => 130,
            'low_balance_threshold' => 0,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ], $attrs));

        return $tenant->id;
    }

    public function test_daily_charge_amount_is_daily_budget_times_billing_rate(): void
    {
        $tenantId = $this->makeAccount(['daily_budget' => 10, 'billing_rate' => 130]);

        $this->artisan('advertising:run-daily-charges')->assertExitCode(0);

        $ledger = DB::table('ad_billing_ledger')->where('tenant_id', $tenantId)->first();
        $this->assertSame('charge', $ledger->type);
        $this->assertEqualsWithDelta(1300.0, (float) $ledger->amount, 0.0001);
        $this->assertEqualsWithDelta(10.0, (float) $ledger->meta_spend_usd, 0.0001);

        $account = DB::table('ad_billing_accounts')->where('tenant_id', $tenantId)->first();
        $this->assertEqualsWithDelta(1000.0 - 1300.0, (float) $account->balance, 0.0001);
    }

    public function test_changing_billing_rate_changes_the_next_automatic_charge(): void
    {
        $tenantId = $this->makeAccount(['daily_budget' => 10, 'billing_rate' => 130]);

        $service = app(\App\Services\Advertising\AdvertisingBalanceService::class);
        $service->setBillingRate($tenantId, 150);

        $this->artisan('advertising:run-daily-charges')->assertExitCode(0);

        $ledger = DB::table('ad_billing_ledger')->where('tenant_id', $tenantId)->first();
        $this->assertEqualsWithDelta(1500.0, (float) $ledger->amount, 0.0001);
    }

    public function test_command_is_idempotent_for_the_same_dhaka_day(): void
    {
        $tenantId = $this->makeAccount();

        $this->artisan('advertising:run-daily-charges')->assertExitCode(0);
        $this->artisan('advertising:run-daily-charges')->assertExitCode(0);

        $this->assertSame(1, DB::table('ad_billing_ledger')->where('tenant_id', $tenantId)->count());
    }

    public function test_inactive_accounts_are_not_charged(): void
    {
        $tenantId = $this->makeAccount(['is_active' => 0]);

        $this->artisan('advertising:run-daily-charges')->assertExitCode(0);

        $this->assertSame(0, DB::table('ad_billing_ledger')->where('tenant_id', $tenantId)->count());
    }
}
