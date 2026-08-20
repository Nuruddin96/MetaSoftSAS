<?php

namespace Tests\Feature\Api\Mobile;

use Tests\Concerns\InteractsWithApiSchema;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use InteractsWithApiSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpApiSchema();
    }

    public function test_login_succeeds_with_correct_email_and_password_and_no_subdomain_field(): void
    {
        $tenant = $this->makeTenant();
        $this->makeUser($tenant->id, ['email' => 'owner@example.com']);

        $response = $this->postJson('/api/mobile/v1/auth/login', [
            'email' => 'owner@example.com',
            'password' => 'password',
        ]);

        $response->assertOk()
            ->assertJsonPath('user.email', 'owner@example.com')
            ->assertJsonPath('tenant.id', $tenant->id)
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email', 'role'], 'tenant' => ['id', 'business_name']]);
    }

    public function test_login_fails_for_unknown_email(): void
    {
        $response = $this->postJson('/api/mobile/v1/auth/login', [
            'email' => 'nobody@example.com',
            'password' => 'password',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_login_fails_with_wrong_password(): void
    {
        $tenant = $this->makeTenant();
        $this->makeUser($tenant->id, ['email' => 'owner2@example.com']);

        $response = $this->postJson('/api/mobile/v1/auth/login', [
            'email' => 'owner2@example.com',
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_login_fails_for_inactive_user(): void
    {
        $tenant = $this->makeTenant();
        $this->makeUser($tenant->id, ['email' => 'inactive@example.com', 'is_active' => 0]);

        $response = $this->postJson('/api/mobile/v1/auth/login', [
            'email' => 'inactive@example.com',
            'password' => 'password',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('email');
    }

    /**
     * The critical regression test for the per-tenant-email finding: two
     * different tenants each have a staff user with the SAME email but a
     * DIFFERENT password. With no subdomain to disambiguate up front, the
     * password itself must be what selects the right tenant — the wrong
     * tenant's row must never authenticate, and the right one always must.
     */
    public function test_login_disambiguates_same_email_across_different_tenants_by_password(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();

        $this->makeUser($tenantA->id, ['email' => 'shared@example.com', 'password' => bcrypt('password-a')]);
        $this->makeUser($tenantB->id, ['email' => 'shared@example.com', 'password' => bcrypt('password-b')]);

        $responseA = $this->postJson('/api/mobile/v1/auth/login', [
            'email' => 'shared@example.com', 'password' => 'password-a',
        ]);
        $responseA->assertOk()->assertJsonPath('tenant.id', $tenantA->id);

        // A real production request never carries state from a previous
        // one, but sequential ->postJson() calls within a single test
        // method share one app container — login's own
        // app()->instance('currentTenant', ...) from responseA above would
        // otherwise still be bound here and wrongly scope the next query to
        // tenant A only, hiding tenant B's row entirely (same class of
        // cross-request state leak LogoutTest's forgetGuards() call guards
        // against for the auth guard).
        $this->app->forgetInstance('currentTenant');

        $responseB = $this->postJson('/api/mobile/v1/auth/login', [
            'email' => 'shared@example.com', 'password' => 'password-b',
        ]);
        $responseB->assertOk()->assertJsonPath('tenant.id', $tenantB->id);

        $this->app->forgetInstance('currentTenant');

        // Neither password may authenticate as the other tenant.
        $this->postJson('/api/mobile/v1/auth/login', [
            'email' => 'shared@example.com', 'password' => 'a-password-nobody-has',
        ])->assertStatus(422);
    }

    /**
     * Same email AND same password valid at two different tenants —
     * genuinely ambiguous which shop was intended. Must fail closed
     * (never silently pick one), since the mobile app has no shop picker.
     */
    public function test_login_rejects_ambiguous_credentials_valid_at_multiple_tenants(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();

        $this->makeUser($tenantA->id, ['email' => 'dupe@example.com', 'password' => bcrypt('same-password')]);
        $this->makeUser($tenantB->id, ['email' => 'dupe@example.com', 'password' => bcrypt('same-password')]);

        $response = $this->postJson('/api/mobile/v1/auth/login', [
            'email' => 'dupe@example.com', 'password' => 'same-password',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('email');
    }
}
