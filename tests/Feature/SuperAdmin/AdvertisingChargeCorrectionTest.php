<?php

namespace Tests\Feature\SuperAdmin;

use App\Models\SuperAdmin;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\InteractsWithCommerceSchema;
use Tests\TestCase;

/**
 * Covers SuperAdmin\AdvertisingController::updateCharge() and
 * AdvertisingBalanceService::correctChargeAmount() — the "historical
 * actual-charge amount correction" flow. Not a re-test of
 * activate()/updateSettings()/storeCharge()/the plain ledger read, which
 * already work (see AdvertisingBillingRateTest) and are untouched here.
 */
class AdvertisingChargeCorrectionTest extends TestCase
{
    use InteractsWithCommerceSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCommerceSchema();
        $this->withoutMiddleware(ValidateCsrfToken::class);

        if (! Schema::hasTable('super_admins')) {
            Schema::create('super_admins', function (Blueprint $table) {
                $table->id();
                $table->string('name', 150);
                $table->string('email', 150)->unique();
                $table->string('password');
                $table->rememberToken();
                $table->timestamps();
            });
        }

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
            });
        }
    }

    protected function makeSuperAdmin(): SuperAdmin
    {
        return SuperAdmin::create([
            'name' => 'Admin',
            'email' => 'admin-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
        ]);
    }

    protected function makeAccount(int $tenantId, array $attrs = []): void
    {
        DB::table('ad_billing_accounts')->insert(array_merge([
            'tenant_id' => $tenantId, 'balance' => 0, 'daily_budget' => 500,
            'billing_rate' => 120, 'low_balance_threshold' => 0, 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ], $attrs));
    }

    protected function insertLedger(int $tenantId, array $attrs = []): int
    {
        return DB::table('ad_billing_ledger')->insertGetId(array_merge([
            'tenant_id' => $tenantId,
            'type' => 'charge',
            'amount' => 1300,
            'balance_after' => 0,
            'note' => 'দৈনিক চার্জ (স্বয়ংক্রিয়)',
            'created_at' => now(),
            'updated_at' => now(),
        ], $attrs));
    }

    public function test_super_admin_can_correct_a_historical_charge_amount(): void
    {
        $tenant = $this->makeTenant();
        $admin = $this->makeSuperAdmin();
        $this->makeAccount($tenant->id, ['balance' => -1300]);
        $ledgerId = $this->insertLedger($tenant->id, ['balance_after' => -1300]);

        $this->actingAs($admin, 'super_admin')
            ->put(route('super.advertising.charges.update', [$tenant, $ledgerId]), ['amount' => '1250'])
            ->assertRedirect();

        $row = DB::table('ad_billing_ledger')->find($ledgerId);
        $this->assertEqualsWithDelta(1250.0, (float) $row->amount, 0.001);
        $this->assertEqualsWithDelta(-1250.0, (float) $row->balance_after, 0.001);
        $this->assertStringContainsString('৳1,300.00', $row->note);
        $this->assertStringContainsString('৳1,250.00', $row->note);

        $account = DB::table('ad_billing_accounts')->where('tenant_id', $tenant->id)->first();
        $this->assertEqualsWithDelta(-1250.0, (float) $account->balance, 0.001);
    }

    public function test_correction_shifts_balance_after_on_every_later_ledger_row(): void
    {
        $tenant = $this->makeTenant();
        $admin = $this->makeSuperAdmin();
        $this->makeAccount($tenant->id, ['balance' => -1800]);
        $chargeId = $this->insertLedger($tenant->id, ['amount' => 1300, 'balance_after' => -1300]);
        $paymentId = DB::table('ad_billing_ledger')->insertGetId([
            'tenant_id' => $tenant->id, 'type' => 'payment', 'amount' => 1000,
            'balance_after' => -300, 'note' => 'পেমেন্ট', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $laterChargeId = $this->insertLedger($tenant->id, ['amount' => 1500, 'balance_after' => -1800]);

        $this->actingAs($admin, 'super_admin')
            ->put(route('super.advertising.charges.update', [$tenant, $chargeId]), ['amount' => '1250']);

        // Edited row: amount + balance_after both corrected.
        $charge = DB::table('ad_billing_ledger')->find($chargeId);
        $this->assertEqualsWithDelta(1250.0, (float) $charge->amount, 0.001);
        $this->assertEqualsWithDelta(-1250.0, (float) $charge->balance_after, 0.001);

        // Later rows: balance_after shifted by the same +50 delta, amounts untouched.
        $payment = DB::table('ad_billing_ledger')->find($paymentId);
        $this->assertEqualsWithDelta(1000.0, (float) $payment->amount, 0.001);
        $this->assertEqualsWithDelta(-250.0, (float) $payment->balance_after, 0.001);

        $laterCharge = DB::table('ad_billing_ledger')->find($laterChargeId);
        $this->assertEqualsWithDelta(1500.0, (float) $laterCharge->amount, 0.001);
        $this->assertEqualsWithDelta(-1750.0, (float) $laterCharge->balance_after, 0.001);

        // Account balance shifted by the same delta.
        $account = DB::table('ad_billing_accounts')->where('tenant_id', $tenant->id)->first();
        $this->assertEqualsWithDelta(-1750.0, (float) $account->balance, 0.001);
    }

    public function test_correction_never_inserts_a_new_ledger_row(): void
    {
        $tenant = $this->makeTenant();
        $admin = $this->makeSuperAdmin();
        $this->makeAccount($tenant->id, ['balance' => -1300]);
        $ledgerId = $this->insertLedger($tenant->id, ['balance_after' => -1300]);

        $this->actingAs($admin, 'super_admin')
            ->put(route('super.advertising.charges.update', [$tenant, $ledgerId]), ['amount' => '1250']);

        $this->assertSame(1, DB::table('ad_billing_ledger')->where('tenant_id', $tenant->id)->count());
    }

    public function test_billing_rate_and_daily_budget_are_never_touched_by_a_correction(): void
    {
        $tenant = $this->makeTenant();
        $admin = $this->makeSuperAdmin();
        $this->makeAccount($tenant->id, ['balance' => -1300, 'billing_rate' => 120, 'daily_budget' => 500]);
        $ledgerId = $this->insertLedger($tenant->id, ['balance_after' => -1300]);

        $this->actingAs($admin, 'super_admin')
            ->put(route('super.advertising.charges.update', [$tenant, $ledgerId]), ['amount' => '1250']);

        $account = DB::table('ad_billing_accounts')->where('tenant_id', $tenant->id)->first();
        $this->assertEqualsWithDelta(120.0, (float) $account->billing_rate, 0.0001);
        $this->assertEqualsWithDelta(500.0, (float) $account->daily_budget, 0.001);
    }

    public function test_a_payment_row_cannot_be_corrected_through_this_endpoint(): void
    {
        $tenant = $this->makeTenant();
        $admin = $this->makeSuperAdmin();
        $this->makeAccount($tenant->id, ['balance' => 1000]);
        $paymentId = DB::table('ad_billing_ledger')->insertGetId([
            'tenant_id' => $tenant->id, 'type' => 'payment', 'amount' => 1000,
            'balance_after' => 1000, 'note' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($admin, 'super_admin')
            ->put(route('super.advertising.charges.update', [$tenant, $paymentId]), ['amount' => '500'])
            ->assertNotFound();

        $row = DB::table('ad_billing_ledger')->find($paymentId);
        $this->assertEqualsWithDelta(1000.0, (float) $row->amount, 0.001);
    }

    public function test_a_charge_belonging_to_another_tenant_cannot_be_corrected(): void
    {
        $tenant = $this->makeTenant();
        $otherTenant = $this->makeTenant();
        $admin = $this->makeSuperAdmin();
        $this->makeAccount($tenant->id, ['balance' => -1300]);
        $this->makeAccount($otherTenant->id, ['balance' => -1300]);
        $ledgerId = $this->insertLedger($otherTenant->id, ['balance_after' => -1300]);

        $this->actingAs($admin, 'super_admin')
            ->put(route('super.advertising.charges.update', [$tenant, $ledgerId]), ['amount' => '1250'])
            ->assertNotFound();

        $row = DB::table('ad_billing_ledger')->find($ledgerId);
        $this->assertEqualsWithDelta(1300.0, (float) $row->amount, 0.001);
    }

    public function test_amount_is_required_and_must_be_a_positive_number(): void
    {
        $tenant = $this->makeTenant();
        $admin = $this->makeSuperAdmin();
        $this->makeAccount($tenant->id, ['balance' => -1300]);
        $ledgerId = $this->insertLedger($tenant->id, ['balance_after' => -1300]);

        $this->actingAs($admin, 'super_admin')
            ->put(route('super.advertising.charges.update', [$tenant, $ledgerId]), ['amount' => '0'])
            ->assertSessionHasErrors('amount');

        $row = DB::table('ad_billing_ledger')->find($ledgerId);
        $this->assertEqualsWithDelta(1300.0, (float) $row->amount, 0.001);
    }

    public function test_guest_cannot_correct_a_charge(): void
    {
        $tenant = $this->makeTenant();
        $this->makeAccount($tenant->id, ['balance' => -1300]);
        $ledgerId = $this->insertLedger($tenant->id, ['balance_after' => -1300]);

        $this->put(route('super.advertising.charges.update', [$tenant, $ledgerId]), ['amount' => '1250'])
            ->assertRedirect(); // to super admin login, not through

        $row = DB::table('ad_billing_ledger')->find($ledgerId);
        $this->assertEqualsWithDelta(1300.0, (float) $row->amount, 0.001);
    }

    public function test_tenant_guard_cannot_correct_a_charge(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $this->makeAccount($tenant->id, ['balance' => -1300]);
        $ledgerId = $this->insertLedger($tenant->id, ['balance_after' => -1300]);

        $this->actingAs($user, 'tenant')
            ->put(route('super.advertising.charges.update', [$tenant, $ledgerId]), ['amount' => '1250'])
            ->assertRedirect(); // super_admin guard rejects a tenant-guard session too

        $row = DB::table('ad_billing_ledger')->find($ledgerId);
        $this->assertEqualsWithDelta(1300.0, (float) $row->amount, 0.001);
    }

    public function test_resubmitting_the_same_amount_is_a_no_op(): void
    {
        $tenant = $this->makeTenant();
        $admin = $this->makeSuperAdmin();
        $this->makeAccount($tenant->id, ['balance' => -1300]);
        $ledgerId = $this->insertLedger($tenant->id, ['balance_after' => -1300, 'note' => 'দৈনিক চার্জ (স্বয়ংক্রিয়)']);

        $this->actingAs($admin, 'super_admin')
            ->put(route('super.advertising.charges.update', [$tenant, $ledgerId]), ['amount' => '1300']);

        $row = DB::table('ad_billing_ledger')->find($ledgerId);
        $this->assertSame('দৈনিক চার্জ (স্বয়ংক্রিয়)', $row->note);

        $account = DB::table('ad_billing_accounts')->where('tenant_id', $tenant->id)->first();
        $this->assertEqualsWithDelta(-1300.0, (float) $account->balance, 0.001);
    }
}
