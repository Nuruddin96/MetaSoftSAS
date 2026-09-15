<?php

namespace Tests\Feature\Api\Mobile;

use Tests\Concerns\InteractsWithApiSchema;
use Tests\TestCase;

class LogoutTest extends TestCase
{
    use InteractsWithApiSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpApiSchema();
    }

    public function test_logout_revokes_the_token_used(): void
    {
        $tenant = $this->makeTenant(['subdomain' => 'logout-shop']);
        $this->makeUser($tenant->id, ['email' => 'owner@example.com']);

        $login = $this->postJson('/api/mobile/v1/auth/login', [
            'email' => 'owner@example.com', 'password' => 'password',
        ])->assertOk();

        $token = $login->json('token');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/mobile/v1/auth/me')->assertOk();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/mobile/v1/auth/logout')->assertOk();

        // Laravel's RequestGuard caches the resolved user on the guard
        // instance, which persists across simulated requests within one
        // test method (unlike real separate HTTP requests) — forget it so
        // this assertion actually re-resolves the (now-deleted) token.
        $this->app['auth']->forgetGuards();

        // Same token must no longer authenticate anything after logout.
        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/mobile/v1/auth/me')->assertUnauthorized();
    }
}
