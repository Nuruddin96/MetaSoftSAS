<?php

namespace Tests\Feature\Order;

use App\Models\StoreSetting;
use App\Models\Tenant;
use App\Services\DeliveryChargeService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * DeliveryChargeService — the single central place "inside vs outside
 * Dhaka" delivery pricing is decided, reused by CheckoutController,
 * OrderController::store(), and OrderController::complete(). Division-
 * based (not district-based), matching the rule that was already live on
 * the storefront checkout before this service existed.
 */
class DeliveryChargeServiceTest extends TestCase
{
    protected int $dhakaDivisionId;
    protected int $otherDivisionId;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('tenants')) {
            Schema::create('tenants', function (Blueprint $table) {
                $table->id();
                $table->string('subdomain')->unique();
                $table->string('store_name');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('bd_divisions')) {
            Schema::create('bd_divisions', function (Blueprint $table) {
                $table->increments('id');
                $table->string('name', 50);
                $table->string('bn_name', 50);
            });
        }

        if (! Schema::hasTable('bd_districts')) {
            Schema::create('bd_districts', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('division_id');
                $table->string('name', 50);
                $table->string('bn_name', 50);
            });
        }

        if (! Schema::hasTable('store_settings')) {
            Schema::create('store_settings', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->string('key', 100);
                $table->string('value', 255)->nullable();
                $table->timestamps();
            });
        }

        DB::table('bd_divisions')->insert([
            ['id' => 1, 'name' => 'Barishal', 'bn_name' => 'বরিশাল'],
            ['id' => 2, 'name' => 'Chattogram', 'bn_name' => 'চট্টগ্রাম'],
            ['id' => 3, 'name' => 'Dhaka', 'bn_name' => 'ঢাকা'],
        ]);

        DB::table('bd_districts')->insert([
            ['id' => 10, 'division_id' => 3, 'name' => 'Dhaka', 'bn_name' => 'ঢাকা'],
            ['id' => 11, 'division_id' => 2, 'name' => 'Chattogram', 'bn_name' => 'চট্টগ্রাম'],
        ]);

        $this->dhakaDivisionId = 3;
        $this->otherDivisionId = 2;
    }

    protected function makeTenant(): Tenant
    {
        $id = DB::table('tenants')->insertGetId([
            'subdomain' => 'test-'.strtolower(Str::random(10)),
            'store_name' => 'Test Store',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return Tenant::find($id);
    }

    public function test_dhaka_division_is_detected_as_inside(): void
    {
        $tenant = $this->makeTenant();
        app()->instance('currentTenant', $tenant);

        $this->assertTrue((new DeliveryChargeService)->isInsideDhaka($this->dhakaDivisionId));
    }

    public function test_non_dhaka_division_is_detected_as_outside(): void
    {
        $tenant = $this->makeTenant();
        app()->instance('currentTenant', $tenant);

        $this->assertFalse((new DeliveryChargeService)->isInsideDhaka($this->otherDivisionId));
    }

    public function test_null_division_is_treated_as_outside(): void
    {
        $tenant = $this->makeTenant();
        app()->instance('currentTenant', $tenant);

        $this->assertFalse((new DeliveryChargeService)->isInsideDhaka(null));
    }

    public function test_calculate_uses_configured_store_settings_values(): void
    {
        $tenant = $this->makeTenant();
        app()->instance('currentTenant', $tenant);
        StoreSetting::create(['tenant_id' => $tenant->id, 'key' => 'delivery_charge_inside_dhaka', 'value' => '70']);
        StoreSetting::create(['tenant_id' => $tenant->id, 'key' => 'delivery_charge_outside_dhaka', 'value' => '150']);

        $service = new DeliveryChargeService;

        $this->assertEquals(70.0, $service->calculate($this->dhakaDivisionId));
        $this->assertEquals(150.0, $service->calculate($this->otherDivisionId));
    }

    public function test_calculate_falls_back_to_defaults_when_unconfigured(): void
    {
        $tenant = $this->makeTenant();
        app()->instance('currentTenant', $tenant); // no StoreSetting rows created at all

        $service = new DeliveryChargeService;

        $this->assertEquals(60.0, $service->calculate($this->dhakaDivisionId));
        $this->assertEquals(120.0, $service->calculate($this->otherDivisionId));
    }

    public function test_calculate_for_missing_address_defaults_to_outside_dhaka_charge(): void
    {
        $tenant = $this->makeTenant();
        app()->instance('currentTenant', $tenant);
        StoreSetting::create(['tenant_id' => $tenant->id, 'key' => 'delivery_charge_inside_dhaka', 'value' => '70']);
        StoreSetting::create(['tenant_id' => $tenant->id, 'key' => 'delivery_charge_outside_dhaka', 'value' => '150']);

        $this->assertEquals(150.0, (new DeliveryChargeService)->calculate(null));
    }

    public function test_delivery_charge_settings_are_tenant_isolated(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();

        app()->instance('currentTenant', $tenantA);
        StoreSetting::create(['tenant_id' => $tenantA->id, 'key' => 'delivery_charge_inside_dhaka', 'value' => '70']);

        app()->instance('currentTenant', $tenantB);
        // Tenant B configured nothing — must get the hardcoded default, never tenant A's 70.
        $this->assertEquals(60.0, (new DeliveryChargeService)->calculate($this->dhakaDivisionId));
    }

    public function test_resolve_division_id_derives_from_district_when_division_not_submitted(): void
    {
        $service = new DeliveryChargeService;

        $this->assertSame($this->dhakaDivisionId, $service->resolveDivisionId(null, 10));
        $this->assertSame($this->otherDivisionId, $service->resolveDivisionId(null, 11));
    }

    public function test_resolve_division_id_prefers_an_explicitly_submitted_division_over_district(): void
    {
        $service = new DeliveryChargeService;

        // District 11 (Chattogram) is submitted alongside an explicit
        // division_id — the explicit value must win, never be silently
        // overridden by the district's own division.
        $this->assertSame($this->dhakaDivisionId, $service->resolveDivisionId($this->dhakaDivisionId, 11));
    }

    public function test_resolve_division_id_is_null_when_neither_is_submitted(): void
    {
        $service = new DeliveryChargeService;

        $this->assertNull($service->resolveDivisionId(null, null));
    }
}
