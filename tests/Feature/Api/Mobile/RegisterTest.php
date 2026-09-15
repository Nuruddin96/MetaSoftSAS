<?php

namespace Tests\Feature\Api\Mobile;

use App\Models\Tenant;
use Tests\Concerns\InteractsWithApiSchema;
use Tests\TestCase;

class RegisterTest extends TestCase
{
    use InteractsWithApiSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpApiSchema();
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'store_name' => 'Rahim Fashion House',
            'owner_name' => 'Rahim Uddin',
            'owner_phone' => '01712345678',
            'owner_email' => 'rahim@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ], $overrides);
    }

    public function test_register_creates_a_tenant_and_owner_and_returns_a_token(): void
    {
        $response = $this->postJson('/api/mobile/v1/auth/register', $this->payload());

        $response->assertCreated()
            ->assertJsonPath('user.email', 'rahim@example.com')
            ->assertJsonPath('user.role', 'owner')
            ->assertJsonPath('tenant.business_name', 'Rahim Fashion House')
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email', 'role'], 'tenant' => ['id', 'business_name']]);

        $this->assertDatabaseHas('tenants', ['owner_email' => 'rahim@example.com', 'status' => 'trial', 'plan_id' => 1]);
        $this->assertDatabaseHas('users', ['email' => 'rahim@example.com', 'role' => 'owner']);
    }

    /**
     * A brand-new tenant has never completed the wizard —
     * `onboarding_completed_at` is NULL by construction (only `makeTenant()`
     * defaults it to "already done" for unrelated tests), so needs_onboarding
     * must be true and step must default to business_type.
     */
    public function test_register_response_routes_a_fresh_tenant_into_onboarding(): void
    {
        $response = $this->postJson('/api/mobile/v1/auth/register', $this->payload());

        $response->assertCreated()
            ->assertJsonPath('tenant.needs_onboarding', true)
            ->assertJsonPath('tenant.onboarding_step', 'business_type');
    }

    public function test_register_token_authenticates_subsequent_requests(): void
    {
        $response = $this->postJson('/api/mobile/v1/auth/register', $this->payload());
        $token = $response->json('token');

        $me = $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/mobile/v1/auth/me');

        $me->assertOk()->assertJsonPath('tenant.business_name', 'Rahim Fashion House');
    }

    public function test_register_fails_when_email_already_used_by_a_tenant(): void
    {
        $this->makeTenant(['owner_email' => 'taken@example.com']);

        $response = $this->postJson('/api/mobile/v1/auth/register', $this->payload(['owner_email' => 'taken@example.com']));

        $response->assertStatus(422)->assertJsonValidationErrors('owner_email');
    }

    public function test_register_fails_for_invalid_bangladeshi_phone(): void
    {
        $response = $this->postJson('/api/mobile/v1/auth/register', $this->payload(['owner_phone' => '123']));

        $response->assertStatus(422)->assertJsonValidationErrors('owner_phone');
    }

    public function test_register_fails_when_passwords_do_not_match(): void
    {
        $response = $this->postJson(
            '/api/mobile/v1/auth/register',
            $this->payload(['password_confirmation' => 'something-else']),
        );

        $response->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_register_assigns_a_unique_generated_subdomain(): void
    {
        $this->postJson('/api/mobile/v1/auth/register', $this->payload())->assertCreated();
        $second = $this->postJson('/api/mobile/v1/auth/register', $this->payload([
            'owner_email' => 'rahim2@example.com',
        ]))->assertCreated();

        $tenant = Tenant::where('owner_email', 'rahim2@example.com')->first();
        $this->assertSame('rahim2', $tenant->subdomain);
    }
}
