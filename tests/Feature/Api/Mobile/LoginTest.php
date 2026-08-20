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

    public function test_login_succeeds_with_correct_subdomain_email_password(): void
    {
        $tenant = $this->makeTenant(['subdomain' => 'shop-one']);
        $this->makeUser($tenant->id, ['email' => 'owner@example.com']);

        $response = $this->postJson('/api/mobile/v1/auth/login', [
            'subdomain' => 'shop-one',
            'email' => 'owner@example.com',
            'password' => 'password',
        ]);

        $response->assertOk()
            ->assertJsonPath('user.email', 'owner@example.com')
            ->assertJsonPath('tenant.id', $tenant->id)
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email', 'role'], 'tenant' => ['id', 'business_name']]);
    }

    public function test_login_fails_for_unknown_subdomain(): void
    {
        $response = $this->postJson('/api/mobile/v1/auth/login', [
            'subdomain' => 'no-such-shop',
            'email' => 'owner@example.com',
            'password' => 'password',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('subdomain');
    }

    public function test_login_fails_with_wrong_password(): void
    {
        $tenant = $this->makeTenant(['subdomain' => 'shop-two']);
        $this->makeUser($tenant->id, ['email' => 'owner2@example.com']);

        $response = $this->postJson('/api/mobile/v1/auth/login', [
            'subdomain' => 'shop-two',
            'email' => 'owner2@example.com',
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('email');
    }

    /**
     * The critical regression test for the per-tenant-email finding: two
     * different tenants each have a staff user with the SAME email — login
     * must resolve strictly by (subdomain -> tenant) first, never match the
     * wrong tenant's row for that email.
     */
    public function test_login_disambiguates_same_email_across_different_tenants(): void
    {
        $tenantA = $this->makeTenant(['subdomain' => 'shop-a']);
        $tenantB = $this->makeTenant(['subdomain' => 'shop-b']);

        $this->makeUser($tenantA->id, ['email' => 'shared@example.com', 'password' => bcrypt('password-a')]);
        $this->makeUser($tenantB->id, ['email' => 'shared@example.com', 'password' => bcrypt('password-b')]);

        $responseA = $this->postJson('/api/mobile/v1/auth/login', [
            'subdomain' => 'shop-a', 'email' => 'shared@example.com', 'password' => 'password-a',
        ]);
        $responseA->assertOk()->assertJsonPath('tenant.id', $tenantA->id);

        // Tenant A's password must NOT work against tenant B's subdomain,
        // even though the email matches a real row there.
        $crossResponse = $this->postJson('/api/mobile/v1/auth/login', [
            'subdomain' => 'shop-b', 'email' => 'shared@example.com', 'password' => 'password-a',
        ]);
        $crossResponse->assertStatus(422);

        $responseB = $this->postJson('/api/mobile/v1/auth/login', [
            'subdomain' => 'shop-b', 'email' => 'shared@example.com', 'password' => 'password-b',
        ]);
        $responseB->assertOk()->assertJsonPath('tenant.id', $tenantB->id);
    }
}
