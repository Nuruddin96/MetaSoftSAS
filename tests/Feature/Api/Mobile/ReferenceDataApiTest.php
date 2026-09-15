<?php

namespace Tests\Feature\Api\Mobile;

use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithApiSchema;
use Tests\TestCase;

/**
 * Covers Api\Mobile\ReferenceDataController — bd_divisions/bd_districts/
 * bd_upazilas lookups. districts() gained an optional (previously required)
 * division_id for the District→Thana address UX (Priority 2), and
 * upazilas() is new for the same reason — both proven here alongside the
 * pre-existing divisions()/filtered-districts() behavior.
 */
class ReferenceDataApiTest extends TestCase
{
    use InteractsWithApiSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpApiSchema();

        DB::table('bd_divisions')->insert([
            ['id' => 1, 'name' => 'Barishal', 'bn_name' => 'বরিশাল'],
            ['id' => 3, 'name' => 'Dhaka', 'bn_name' => 'ঢাকা'],
        ]);
        DB::table('bd_districts')->insert([
            ['id' => 30, 'division_id' => 3, 'name' => 'Dhaka', 'bn_name' => 'ঢাকা'],
            ['id' => 31, 'division_id' => 1, 'name' => 'Barishal', 'bn_name' => 'বরিশাল'],
        ]);
        DB::table('bd_upazilas')->insert([
            ['id' => 300, 'district_id' => 30, 'name' => 'Mirpur', 'bn_name' => 'মিরপুর'],
            ['id' => 301, 'district_id' => 30, 'name' => 'Dhanmondi', 'bn_name' => 'ধানমন্ডি'],
            ['id' => 310, 'district_id' => 31, 'name' => 'Sadar', 'bn_name' => 'সদর'],
        ]);
    }

    public function test_divisions_lists_all(): void
    {
        $tenant = $this->makeTenant();
        Sanctum::actingAs($this->makeUser($tenant->id));

        $response = $this->getJson('/api/mobile/v1/reference/divisions')->assertOk();
        $this->assertCount(2, $response->json('data'));
    }

    public function test_districts_without_division_id_returns_every_district(): void
    {
        $tenant = $this->makeTenant();
        Sanctum::actingAs($this->makeUser($tenant->id));

        $response = $this->getJson('/api/mobile/v1/reference/districts')->assertOk();
        $this->assertCount(2, $response->json('data'));
    }

    public function test_districts_with_division_id_still_filters_as_before(): void
    {
        $tenant = $this->makeTenant();
        Sanctum::actingAs($this->makeUser($tenant->id));

        $response = $this->getJson('/api/mobile/v1/reference/districts?division_id=3')->assertOk();
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertSame(30, $data[0]['id']);
    }

    public function test_upazilas_requires_district_id(): void
    {
        $tenant = $this->makeTenant();
        Sanctum::actingAs($this->makeUser($tenant->id));

        $this->getJson('/api/mobile/v1/reference/upazilas')->assertStatus(422);
    }

    public function test_upazilas_returns_only_the_requested_districts_thanas(): void
    {
        $tenant = $this->makeTenant();
        Sanctum::actingAs($this->makeUser($tenant->id));

        $response = $this->getJson('/api/mobile/v1/reference/upazilas?district_id=30')->assertOk();
        $names = collect($response->json('data'))->pluck('bn_name');
        $this->assertCount(2, $names);
        $this->assertTrue($names->contains('মিরপুর'));
        $this->assertFalse($names->contains('সদর'));
    }
}
