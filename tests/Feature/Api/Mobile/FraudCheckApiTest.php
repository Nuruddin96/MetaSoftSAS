<?php

namespace Tests\Feature\Api\Mobile;

use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithApiSchema;
use Tests\TestCase;

class FraudCheckApiTest extends TestCase
{
    use InteractsWithApiSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpApiSchema();
    }

    public function test_check_reports_unconfigured_when_no_courier_is_connected(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        Sanctum::actingAs($user);
        app()->instance('currentTenant', $tenant);

        $response = $this->postJson('/api/mobile/v1/fraud-check', ['phone' => '01712345678']);

        $response->assertOk()
            ->assertJsonPath('verdict', 'unconfigured')
            ->assertJsonPath('phone', '01712345678')
            ->assertJsonPath('internal.phone', '01712345678')
            ->assertJsonPath('internal.total', 0);
    }

    public function test_check_requires_a_phone(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        Sanctum::actingAs($user);
        app()->instance('currentTenant', $tenant);

        $this->postJson('/api/mobile/v1/fraud-check', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('phone');
    }
}
