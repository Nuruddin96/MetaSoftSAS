<?php

namespace Tests\Feature\Api\Mobile;

use App\Models\Plan;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithCommerceSchema;
use Tests\TestCase;

/**
 * Covers Api\Mobile\AdvertisingController — mirrors Tenant\AdvertisingController's
 * real capability exactly (the read-only Ad Billing wallet: balance/
 * daily-budget/billing-rate + payment/charge ledger).
 */
class AdvertisingApiTest extends TestCase
{
    use InteractsWithCommerceSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCommerceSchema();

        if (! Schema::hasColumn('plans', 'allow_meta_ads')) {
            Schema::table('plans', fn (Blueprint $t) => $t->boolean('allow_meta_ads')->default(true));
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
                $table->decimal('meta_spend_usd', 10, 2)->nullable();
                $table->date('charge_date')->nullable();
                $table->string('note')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamp('created_at')->nullable();
            });
        }
    }

    protected function activateAccount(int $tenantId, array $overrides = []): void
    {
        DB::table('ad_billing_accounts')->insert(array_merge([
            'tenant_id' => $tenantId, 'balance' => 500, 'daily_budget' => 100,
            'billing_rate' => 120, 'low_balance_threshold' => 50, 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ], $overrides));
    }

    public function test_overview_reports_disabled_when_module_not_activated(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/mobile/v1/advertising')->assertOk();
        $response->assertJsonPath('enabled', false);
    }

    public function test_overview_returns_account_and_recent_ledger_when_enabled(): void
    {
        $plan = Plan::create(['name' => 'Growth', 'slug' => 'growth', 'is_active' => 1, 'allow_meta_ads' => true]);
        $tenant = $this->makeTenant();
        $tenant->update(['plan_id' => $plan->id]);
        $user = $this->makeUser($tenant->id);
        $this->activateAccount($tenant->id);
        DB::table('ad_billing_ledger')->insert([
            'tenant_id' => $tenant->id, 'type' => 'payment', 'amount' => 500, 'balance_after' => 500,
            'note' => 'Bank transfer', 'created_at' => now(),
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/mobile/v1/advertising')->assertOk();
        $response->assertJsonPath('enabled', true);
        $this->assertEquals(500.0, $response->json('account.balance'));
        $response->assertJsonPath('account.status', 'active');
        $response->assertJsonPath('recent.0.type', 'payment');
        // meta_spend_usd / created_by must never leak to the tenant-facing response.
        $response->assertJsonMissingPath('recent.0.meta_spend_usd');
    }

    public function test_overview_status_is_suspended_when_balance_is_zero(): void
    {
        $plan = Plan::create(['name' => 'Growth', 'slug' => 'growth', 'is_active' => 1, 'allow_meta_ads' => true]);
        $tenant = $this->makeTenant();
        $tenant->update(['plan_id' => $plan->id]);
        $user = $this->makeUser($tenant->id);
        $this->activateAccount($tenant->id, ['balance' => 0]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/mobile/v1/advertising')->assertOk();
        $response->assertJsonPath('account.status', 'suspended');
    }

    public function test_ledger_filters_by_type(): void
    {
        $plan = Plan::create(['name' => 'Growth', 'slug' => 'growth', 'is_active' => 1, 'allow_meta_ads' => true]);
        $tenant = $this->makeTenant();
        $tenant->update(['plan_id' => $plan->id]);
        $user = $this->makeUser($tenant->id);
        $this->activateAccount($tenant->id);
        DB::table('ad_billing_ledger')->insert([
            ['tenant_id' => $tenant->id, 'type' => 'payment', 'amount' => 500, 'balance_after' => 500, 'created_at' => now()],
            ['tenant_id' => $tenant->id, 'type' => 'charge', 'amount' => 50, 'balance_after' => 450, 'created_at' => now()],
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/mobile/v1/advertising/ledger?type=charge')->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.type', 'charge');
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/mobile/v1/advertising')->assertUnauthorized();
    }
}
