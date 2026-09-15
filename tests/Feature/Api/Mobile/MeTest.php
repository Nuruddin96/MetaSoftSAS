<?php

namespace Tests\Feature\Api\Mobile;

use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithApiSchema;
use Tests\TestCase;

class MeTest extends TestCase
{
    use InteractsWithApiSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpApiSchema();
    }

    public function test_me_returns_the_authenticated_user_and_their_own_tenant(): void
    {
        $tenant = $this->makeTenant(['subdomain' => 'me-shop', 'store_name' => 'Me Shop']);
        $user = $this->makeUser($tenant->id, ['name' => 'Me Owner', 'email' => 'me-owner@example.com', 'role' => 'owner']);

        Sanctum::actingAs($user);

        $this->getJson('/api/mobile/v1/auth/me')
            ->assertOk()
            ->assertJsonStructure(['user' => ['id', 'name', 'email', 'role'], 'tenant' => ['id', 'business_name']])
            ->assertJson([
                'user' => ['id' => $user->id, 'name' => 'Me Owner', 'email' => 'me-owner@example.com', 'role' => 'owner'],
                'tenant' => ['id' => $tenant->id, 'business_name' => 'Me Shop'],
            ]);
    }

    public function test_me_rejects_a_request_with_no_token(): void
    {
        $this->getJson('/api/mobile/v1/auth/me')->assertUnauthorized();
    }

    public function test_me_rejects_an_invalid_token(): void
    {
        $this->withHeader('Authorization', 'Bearer not-a-real-token')
            ->getJson('/api/mobile/v1/auth/me')
            ->assertUnauthorized();
    }
}
