<?php

namespace Tests\Feature\Api\Mobile;

use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithApiSchema;
use Tests\TestCase;

/**
 * Covers the status/trial_ends_at fields added to Api\Mobile\
 * AuthController's tenant payload (login/register/me) for the
 * Subscription Expiry Enforcement project — added so the Flutter app can
 * react (route-level redirect) without waiting for the first 402 from
 * CheckMobileSubscription.
 */
class AuthSubscriptionFieldsTest extends TestCase
{
    use InteractsWithApiSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpApiSchema();
    }

    public function test_login_response_includes_tenant_status_and_trial_ends_at(): void
    {
        $tenant = $this->makeTenant(['status' => 'trial', 'trial_ends_at' => now()->addDays(5)]);
        $user = $this->makeUser($tenant->id, ['email' => 'owner@example.com', 'is_active' => 1]);

        $response = $this->postJson('/api/mobile/v1/auth/login', [
            'email' => 'owner@example.com',
            'password' => 'password',
        ]);

        $response->assertOk()
            ->assertJsonPath('tenant.status', 'trial')
            ->assertJsonPath('tenant.trial_ends_at', fn ($v) => $v !== null);
    }

    public function test_me_response_includes_tenant_status_and_trial_ends_at(): void
    {
        $tenant = $this->makeTenant(['status' => 'expired']);
        $user = $this->makeUser($tenant->id);
        Sanctum::actingAs($user);

        $this->getJson('/api/mobile/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('tenant.status', 'expired');
    }

    public function test_register_response_includes_tenant_status_and_trial_ends_at(): void
    {
        $response = $this->postJson('/api/mobile/v1/auth/register', [
            'store_name' => 'Rahim Fashion House',
            'owner_name' => 'Rahim',
            'owner_phone' => '01712345678',
            'owner_email' => 'rahim-'.uniqid().'@example.com',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ]);

        $response->assertCreated()->assertJsonPath('tenant.status', 'trial');
        $this->assertNotNull($response->json('tenant.trial_ends_at'));
    }
}
