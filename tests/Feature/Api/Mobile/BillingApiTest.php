<?php

namespace Tests\Feature\Api\Mobile;

use App\Models\Plan;
use App\Models\Product;
use App\Models\SubscriptionPayment;
use App\Models\Tenant;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithCommerceSchema;
use Tests\TestCase;

/**
 * Covers Api\Mobile\BillingController — mirrors Tenant\BillingController::
 * index()'s real capability exactly (plan/subscription status, usage,
 * plan comparison, payment history), read-only.
 */
class BillingApiTest extends TestCase
{
    use InteractsWithCommerceSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCommerceSchema();

        foreach (['max_products' => 'integer', 'max_staff' => 'integer', 'max_warehouses' => 'integer'] as $col => $type) {
            if (! Schema::hasColumn('plans', $col)) {
                Schema::table('plans', fn (Blueprint $t) => $t->{$type}($col)->nullable());
            }
        }
        if (! Schema::hasColumn('plans', 'allow_pos')) {
            Schema::table('plans', fn (Blueprint $t) => $t->boolean('allow_pos')->default(true));
        }
        if (! Schema::hasColumn('plans', 'sort_order')) {
            Schema::table('plans', fn (Blueprint $t) => $t->integer('sort_order')->default(0));
        }

        if (! Schema::hasTable('subscription_payments')) {
            Schema::create('subscription_payments', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('subscription_id')->nullable();
                $table->string('gateway', 30);
                $table->decimal('amount', 10, 2);
                $table->string('status', 20)->default('pending');
                $table->string('trx_id')->nullable();
                $table->text('gateway_response')->nullable();
                $table->timestamp('paid_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function test_index_returns_current_plan_usage_and_plan_comparison(): void
    {
        $plan = Plan::create([
            'name' => 'Growth', 'slug' => 'growth', 'price_monthly' => 999, 'price_yearly' => 9999,
            'is_active' => 1, 'max_products' => 100, 'max_staff' => 5, 'allow_pos' => true,
        ]);
        $tenant = $this->makeTenant();
        $tenant->update(['plan_id' => $plan->id, 'status' => 'active', 'subscription_ends_at' => now()->addDays(20)]);
        $user = $this->makeUser($tenant->id);
        app()->instance('currentTenant', Tenant::find($tenant->id));
        Product::create(['tenant_id' => $tenant->id, 'name' => 'Shirt', 'is_active' => 1]);
        Product::create(['tenant_id' => $tenant->id, 'name' => 'Pant', 'is_active' => 1]);
        app()->forgetInstance('currentTenant');

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/mobile/v1/billing')->assertOk();
        $response->assertJsonPath('status', 'active');
        $response->assertJsonPath('current_plan.name', 'Growth');
        $response->assertJsonPath('usage.products.used', 2);
        $response->assertJsonPath('usage.products.limit', 100);
        $response->assertJsonStructure(['plans', 'payments', 'payment_url']);
    }

    public function test_index_includes_payment_history(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        SubscriptionPayment::create([
            'tenant_id' => $tenant->id, 'gateway' => 'bkash', 'amount' => 999, 'status' => 'completed', 'trx_id' => 'TRX1',
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/mobile/v1/billing')->assertOk();
        $response->assertJsonPath('payments.0.gateway', 'bkash');
        $response->assertJsonPath('payments.0.status', 'completed');
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/mobile/v1/billing')->assertUnauthorized();
    }
}
