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
 * Covers SuperAdmin\AdvertisingController::updateBillingRate() and
 * AdvertisingBalanceService::setBillingRate() — the "set billing_rate even
 * before the module is activated" flow requested on top of the existing
 * Advertising Billing module (d653fbe). Not a re-test of activate()/
 * updateSettings()/the ledger, which already work (see prior production
 * diagnosis) and are untouched here.
 */
class AdvertisingBillingRateTest extends TestCase
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

    public function test_super_admin_can_set_billing_rate_for_a_tenant_without_an_account(): void
    {
        $tenant = $this->makeTenant();
        $admin = $this->makeSuperAdmin();

        $this->actingAs($admin, 'super_admin')
            ->put(route('super.advertising.billing-rate', $tenant), ['billing_rate' => '135.5'])
            ->assertRedirect();

        $row = DB::table('ad_billing_accounts')->where('tenant_id', $tenant->id)->first();
        $this->assertNotNull($row);
        $this->assertEqualsWithDelta(135.5, (float) $row->billing_rate, 0.0001);
    }

    public function test_setting_billing_rate_does_not_activate_the_module(): void
    {
        $tenant = $this->makeTenant();
        $admin = $this->makeSuperAdmin();

        $this->actingAs($admin, 'super_admin')
            ->put(route('super.advertising.billing-rate', $tenant), ['billing_rate' => '100']);

        $row = DB::table('ad_billing_accounts')->where('tenant_id', $tenant->id)->first();
        $this->assertSame(0, (int) $row->is_active);
    }

    public function test_super_admin_can_edit_an_existing_tenants_billing_rate(): void
    {
        $tenant = $this->makeTenant();
        $admin = $this->makeSuperAdmin();
        DB::table('ad_billing_accounts')->insert([
            'tenant_id' => $tenant->id, 'balance' => 0, 'daily_budget' => 500,
            'billing_rate' => 120, 'low_balance_threshold' => 0, 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($admin, 'super_admin')
            ->put(route('super.advertising.billing-rate', $tenant), ['billing_rate' => '150'])
            ->assertRedirect();

        $row = DB::table('ad_billing_accounts')->where('tenant_id', $tenant->id)->first();
        $this->assertEqualsWithDelta(150.0, (float) $row->billing_rate, 0.0001);
        // untouched fields prove this is a targeted update, not a replace
        $this->assertEqualsWithDelta(500.0, (float) $row->daily_budget, 0.0001);
        $this->assertSame(1, (int) $row->is_active);
    }

    public function test_resubmitting_billing_rate_never_creates_a_duplicate_account(): void
    {
        $tenant = $this->makeTenant();
        $admin = $this->makeSuperAdmin();

        $this->actingAs($admin, 'super_admin')
            ->put(route('super.advertising.billing-rate', $tenant), ['billing_rate' => '100']);
        $this->actingAs($admin, 'super_admin')
            ->put(route('super.advertising.billing-rate', $tenant), ['billing_rate' => '110']);

        $this->assertSame(1, DB::table('ad_billing_accounts')->where('tenant_id', $tenant->id)->count());
        $this->assertEqualsWithDelta(110.0, (float) DB::table('ad_billing_accounts')->where('tenant_id', $tenant->id)->value('billing_rate'), 0.0001);
    }

    public function test_guest_cannot_set_billing_rate(): void
    {
        $tenant = $this->makeTenant();

        $this->put(route('super.advertising.billing-rate', $tenant), ['billing_rate' => '100'])
            ->assertRedirect(); // to super admin login, not through

        $this->assertSame(0, DB::table('ad_billing_accounts')->where('tenant_id', $tenant->id)->count());
    }

    public function test_tenant_guard_cannot_set_billing_rate(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);

        $this->actingAs($user, 'tenant')
            ->put(route('super.advertising.billing-rate', $tenant), ['billing_rate' => '100'])
            ->assertRedirect(); // super_admin guard rejects a tenant-guard session too

        $this->assertSame(0, DB::table('ad_billing_accounts')->where('tenant_id', $tenant->id)->count());
    }

    public function test_billing_rate_is_required_and_numeric(): void
    {
        $tenant = $this->makeTenant();
        $admin = $this->makeSuperAdmin();

        $this->actingAs($admin, 'super_admin')
            ->put(route('super.advertising.billing-rate', $tenant), ['billing_rate' => 'not-a-number'])
            ->assertSessionHasErrors('billing_rate');

        $this->assertSame(0, DB::table('ad_billing_accounts')->where('tenant_id', $tenant->id)->count());
    }

    public function test_show_page_renders_the_billing_rate_editor_before_activation(): void
    {
        $tenant = $this->makeTenant();
        $admin = $this->makeSuperAdmin();

        $response = $this->actingAs($admin, 'super_admin')->get(route('super.advertising.show', $tenant));

        $response->assertOk();
        $response->assertSee(route('super.advertising.billing-rate', $tenant), false);
        // the tenant-side controller/views are untouched — no store/update
        // method exists there at all, so no assertion needed beyond that
        // static fact already verified by inspection.
    }
}
