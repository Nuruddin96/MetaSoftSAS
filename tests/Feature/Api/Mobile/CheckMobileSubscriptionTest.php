<?php

namespace Tests\Feature\Api\Mobile;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithApiSchema;
use Tests\TestCase;

/**
 * Covers CheckMobileSubscription — the mobile API's equivalent of the web
 * CheckSubscription middleware (see that class's docblock for why it can't
 * be reused directly). Uses GET /dashboard as a representative "ordinary"
 * protected endpoint; the point under test is the middleware, not
 * DashboardController itself.
 */
class CheckMobileSubscriptionTest extends TestCase
{
    use InteractsWithApiSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpApiSchema();

        // Only /billing exercises this table among these tests — not part
        // of InteractsWithApiSchema's own stub set.
        if (! Schema::hasTable('subscription_payments')) {
            Schema::create('subscription_payments', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('subscription_id')->nullable();
                $table->string('gateway', 20);
                $table->string('trx_id', 100)->nullable();
                $table->decimal('amount', 10, 2);
                $table->string('status', 20)->default('pending');
                $table->json('gateway_response')->nullable();
                $table->timestamp('paid_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function test_active_tenant_with_a_future_subscription_keeps_full_access(): void
    {
        $tenant = $this->makeTenant(['status' => 'active', 'subscription_ends_at' => now()->addDays(10)]);
        $user = $this->makeUser($tenant->id);
        Sanctum::actingAs($user);

        $this->getJson('/api/mobile/v1/dashboard')->assertOk();

        $this->assertSame('active', $tenant->fresh()->status);
    }

    public function test_trial_tenant_still_within_the_trial_period_keeps_full_access(): void
    {
        $tenant = $this->makeTenant(['status' => 'trial', 'trial_ends_at' => now()->addDays(3), 'subscription_ends_at' => null]);
        $user = $this->makeUser($tenant->id);
        Sanctum::actingAs($user);

        $this->getJson('/api/mobile/v1/dashboard')->assertOk();
    }

    public function test_expired_active_subscription_is_blocked_with_402(): void
    {
        $tenant = $this->makeTenant(['status' => 'active', 'subscription_ends_at' => now()->subDay()]);
        $user = $this->makeUser($tenant->id);
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/mobile/v1/dashboard');

        $response->assertStatus(402)->assertJsonPath('code', 'subscription_expired');
        $this->assertSame('expired', $tenant->fresh()->status, 'middleware must flip active->expired, mirroring web CheckSubscription');
    }

    public function test_lapsed_trial_is_blocked_with_402(): void
    {
        $tenant = $this->makeTenant(['status' => 'trial', 'trial_ends_at' => now()->subDay(), 'subscription_ends_at' => null]);
        $user = $this->makeUser($tenant->id);
        Sanctum::actingAs($user);

        $this->getJson('/api/mobile/v1/dashboard')->assertStatus(402);
    }

    public function test_suspended_tenant_is_blocked_with_402_and_status_is_not_overwritten(): void
    {
        $tenant = $this->makeTenant(['status' => 'suspended', 'subscription_ends_at' => now()->addDays(10)]);
        $user = $this->makeUser($tenant->id);
        Sanctum::actingAs($user);

        $this->getJson('/api/mobile/v1/dashboard')->assertStatus(402);

        $this->assertSame('suspended', $tenant->fresh()->status, 'suspended is a distinct terminal state — must never be silently relabeled expired');
    }

    public function test_expired_tenant_can_still_reach_billing(): void
    {
        $tenant = $this->makeTenant(['status' => 'active', 'subscription_ends_at' => now()->subDay()]);
        $user = $this->makeUser($tenant->id);
        Sanctum::actingAs($user);

        $this->getJson('/api/mobile/v1/billing')->assertOk();
    }

    public function test_expired_tenant_can_still_reach_auth_me_and_logout(): void
    {
        $tenant = $this->makeTenant(['status' => 'active', 'subscription_ends_at' => now()->subDay()]);
        $user = $this->makeUser($tenant->id);
        Sanctum::actingAs($user);

        $this->getJson('/api/mobile/v1/auth/me')->assertOk();
        $this->postJson('/api/mobile/v1/auth/logout')->assertOk();
    }

    public function test_one_tenants_expiry_never_blocks_another_tenant(): void
    {
        $expiredTenant = $this->makeTenant(['status' => 'active', 'subscription_ends_at' => now()->subDay()]);
        $expiredUser = $this->makeUser($expiredTenant->id);
        $activeTenant = $this->makeTenant(['status' => 'active', 'subscription_ends_at' => now()->addDays(10)]);
        $activeUser = $this->makeUser($activeTenant->id);

        Sanctum::actingAs($expiredUser);
        $this->getJson('/api/mobile/v1/dashboard')->assertStatus(402);

        Sanctum::actingAs($activeUser);
        $this->getJson('/api/mobile/v1/dashboard')->assertOk();

        $this->assertSame('expired', $expiredTenant->fresh()->status);
        $this->assertSame('active', $activeTenant->fresh()->status);
    }
}
