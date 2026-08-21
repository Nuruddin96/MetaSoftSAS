<?php

namespace Tests\Feature\Api\Mobile;

use App\Models\IncompleteOrder;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithApiSchema;
use Tests\TestCase;

class IncompleteOrderApiTest extends TestCase
{
    use InteractsWithApiSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpApiSchema();
    }

    protected function makeItem(int $tenantId, array $attrs = []): IncompleteOrder
    {
        return IncompleteOrder::create(array_merge([
            'tenant_id' => $tenantId,
            'customer_name' => 'Karim',
            'customer_phone' => '01712345678',
            'total' => 500,
            'status' => 'abandoned',
            'last_activity_at' => now(),
        ], $attrs));
    }

    public function test_index_excludes_recovered_and_returns_pagination_meta(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $this->makeItem($tenant->id, ['status' => 'abandoned']);
        $this->makeItem($tenant->id, ['status' => 'recovered']);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/mobile/v1/incomplete-orders')->assertOk();
        $response->assertJsonStructure(['data', 'meta' => ['current_page', 'last_page', 'per_page', 'total']]);
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('abandoned', $response->json('data.0.status'));
    }

    public function test_index_handles_a_null_last_activity_at_without_crashing(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $this->makeItem($tenant->id, ['last_activity_at' => null]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/mobile/v1/incomplete-orders')->assertOk();
        $this->assertNull($response->json('data.0.last_activity_at'));
    }

    public function test_update_status_accepts_staff_settable_values(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $item = $this->makeItem($tenant->id);

        Sanctum::actingAs($user);

        $this->patchJson("/api/mobile/v1/incomplete-orders/{$item->id}/status", ['status' => 'contacted'])
            ->assertOk()->assertJsonPath('status', 'contacted');
    }

    public function test_update_status_rejects_recovered_as_a_manual_value(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $item = $this->makeItem($tenant->id);

        Sanctum::actingAs($user);

        $this->patchJson("/api/mobile/v1/incomplete-orders/{$item->id}/status", ['status' => 'recovered'])
            ->assertStatus(422)->assertJsonValidationErrors('status');
    }

    public function test_tenant_cannot_update_another_tenants_item(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $userB = $this->makeUser($tenantB->id);
        $itemA = $this->makeItem($tenantA->id);

        Sanctum::actingAs($userB);

        $this->patchJson("/api/mobile/v1/incomplete-orders/{$itemA->id}/status", ['status' => 'contacted'])
            ->assertNotFound();
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/mobile/v1/incomplete-orders')->assertUnauthorized();
    }
}
