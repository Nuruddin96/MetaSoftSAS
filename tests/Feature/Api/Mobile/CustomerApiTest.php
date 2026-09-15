<?php

namespace Tests\Feature\Api\Mobile;

use App\Models\Customer;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithApiSchema;
use Tests\TestCase;

class CustomerApiTest extends TestCase
{
    use InteractsWithApiSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpApiSchema();
    }

    public function test_create_customer(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        Sanctum::actingAs($user);

        $this->postJson('/api/mobile/v1/customers', [
            'name' => 'Karim Sheikh',
            'phone' => '01712345678',
        ])->assertCreated()->assertJsonPath('name', 'Karim Sheikh')->assertJsonPath('due_balance', 0);
    }

    public function test_create_customer_rejects_duplicate_phone_within_tenant(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        Customer::create(['tenant_id' => $tenant->id, 'name' => 'Existing', 'phone' => '01712345678']);

        Sanctum::actingAs($user);

        $this->postJson('/api/mobile/v1/customers', [
            'name' => 'Another Person',
            'phone' => '01712345678',
        ])->assertStatus(422)->assertJsonValidationErrors('phone');
    }

    public function test_add_due_then_receive_due_updates_balance_and_ledger(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $customer = Customer::create(['tenant_id' => $tenant->id, 'name' => 'Due Customer', 'phone' => '01712345678']);

        Sanctum::actingAs($user);

        // Flutter's single /due endpoint, type=due.
        $this->postJson("/api/mobile/v1/customers/{$customer->id}/due", ['type' => 'due', 'amount' => 300])
            ->assertOk()->assertJsonPath('due_balance', 300);

        // Flutter's single /due endpoint, type=payment (default).
        $this->postJson("/api/mobile/v1/customers/{$customer->id}/due", ['amount' => 200])
            ->assertOk()->assertJsonPath('due_balance', 100);

        $this->assertDatabaseCount('due_ledger', 2);
    }

    public function test_index_returns_pagination_meta(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        Customer::create(['tenant_id' => $tenant->id, 'name' => 'A', 'phone' => '01712345671']);
        Customer::create(['tenant_id' => $tenant->id, 'name' => 'B', 'phone' => '01712345672']);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/mobile/v1/customers')->assertOk();
        $response->assertJsonStructure(['data', 'meta' => ['current_page', 'last_page', 'per_page', 'total']]);
        $this->assertSame(2, $response->json('meta.total'));
    }

    public function test_receive_due_cannot_exceed_current_balance(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $customer = Customer::create(['tenant_id' => $tenant->id, 'name' => 'C', 'phone' => '01712345678', 'due_balance' => 50]);

        Sanctum::actingAs($user);

        $this->postJson("/api/mobile/v1/customers/{$customer->id}/due", ['amount' => 500])
            ->assertOk()->assertJsonPath('due_balance', 0);
    }
}
