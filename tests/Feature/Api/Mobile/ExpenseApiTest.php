<?php

namespace Tests\Feature\Api\Mobile;

use App\Models\Expense;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithApiSchema;
use Tests\TestCase;

/**
 * Covers Api\Mobile\ExpenseController — mirrors Tenant\ExpenseController's
 * real capability exactly.
 */
class ExpenseApiTest extends TestCase
{
    use InteractsWithApiSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpApiSchema();

        if (! Schema::hasTable('expense_categories')) {
            Schema::create('expense_categories', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->nullable();
                $table->string('name', 100);
                $table->timestamps();
            });
        }
    }

    public function test_index_returns_expenses_within_the_current_month_by_default_with_total(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        // Inserted via the query builder (not Expense::create()) so the
        // stored value is a genuine date-only string. The model's
        // `expense_date` => 'date' cast re-serializes any assigned value
        // to "Y-m-d 00:00:00" on save; a real MySQL DATE column silently
        // truncates that back to a date, but SQLite's TEXT-backed date
        // column keeps the time component, which broke the controller's
        // (proven, unmodified) whereBetween() string comparison in this
        // test environment only.
        \Illuminate\Support\Facades\DB::table('expenses')->insert([
            ['tenant_id' => $tenant->id, 'title' => 'Rent', 'amount' => 5000, 'expense_date' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now()],
            ['tenant_id' => $tenant->id, 'title' => 'Old', 'amount' => 1000, 'expense_date' => now()->subMonths(2)->toDateString(), 'created_at' => now(), 'updated_at' => now()],
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/mobile/v1/expenses')->assertOk();
        $response->assertJsonStructure(['data' => [['id', 'title', 'amount', 'expense_date']], 'meta' => ['total_amount']]);
        $titles = collect($response->json('data'))->pluck('title');
        $this->assertTrue($titles->contains('Rent'));
        $this->assertFalse($titles->contains('Old'));
        $this->assertEquals(5000.0, $response->json('meta.total_amount'));
    }

    public function test_store_creates_an_expense_and_resolves_a_category_by_name(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/mobile/v1/expenses', [
            'title' => 'Electricity Bill',
            'amount' => 1200,
            'expense_date' => now()->toDateString(),
            'category_name' => 'Utilities',
        ]);

        $response->assertCreated()->assertJsonPath('title', 'Electricity Bill')->assertJsonPath('category_name', 'Utilities');
        $this->assertDatabaseHas('expenses', ['tenant_id' => $tenant->id, 'title' => 'Electricity Bill']);
        $this->assertDatabaseHas('expense_categories', ['name' => 'Utilities']);
    }

    public function test_store_rejects_missing_required_fields(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        Sanctum::actingAs($user);

        $this->postJson('/api/mobile/v1/expenses', [])
            ->assertStatus(422)->assertJsonValidationErrors(['title', 'amount', 'expense_date']);
    }

    public function test_destroy_removes_the_expense(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        app()->instance('currentTenant', $tenant);
        $expense = Expense::create(['tenant_id' => $tenant->id, 'title' => 'Rent', 'amount' => 5000, 'expense_date' => now()]);
        app()->forgetInstance('currentTenant');

        Sanctum::actingAs($user);

        $this->deleteJson("/api/mobile/v1/expenses/{$expense->id}")->assertOk()->assertJsonPath('ok', true);
        $this->assertDatabaseMissing('expenses', ['id' => $expense->id]);
    }

    public function test_tenant_cannot_delete_another_tenants_expense(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $userB = $this->makeUser($tenantB->id);
        app()->instance('currentTenant', $tenantA);
        $expense = Expense::create(['tenant_id' => $tenantA->id, 'title' => 'Rent', 'amount' => 5000, 'expense_date' => now()]);
        app()->forgetInstance('currentTenant');

        Sanctum::actingAs($userB);

        $this->deleteJson("/api/mobile/v1/expenses/{$expense->id}")->assertNotFound();
        $this->assertDatabaseHas('expenses', ['id' => $expense->id]);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/mobile/v1/expenses')->assertUnauthorized();
    }
}
