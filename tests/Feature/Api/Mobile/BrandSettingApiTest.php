<?php

namespace Tests\Feature\Api\Mobile;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithApiSchema;
use Tests\TestCase;

/** Covers Api\Mobile\SettingController::brand()/updateBrand() — mirrors Tenant\WebsiteController::brand() exactly. */
class BrandSettingApiTest extends TestCase
{
    use InteractsWithApiSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpApiSchema();

        // Pre-existing gap in InteractsWithCommerceSchema's hand-built
        // `tenants` table (predates schema.sql's real logo_path column) —
        // Tenant\WebsiteController::brand() has always written this field.
        // Same scoped-patch convention already used elsewhere in this
        // suite for an identical gap (order_date), kept local here.
        if (! Schema::hasColumn('tenants', 'logo_path')) {
            Schema::table('tenants', fn (Blueprint $table) => $table->string('logo_path')->nullable());
        }
    }

    public function test_brand_returns_defaults_for_a_fresh_tenant(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        Sanctum::actingAs($user);

        $this->getJson('/api/mobile/v1/settings/brand')->assertOk()
            ->assertJsonPath('store_name', $tenant->store_name)
            ->assertJsonPath('primary_color', '#128155')
            ->assertJsonPath('logo_url', null)
            ->assertJsonPath('announcement_style', 'static');
    }

    public function test_update_brand_saves_name_color_and_logo(): void
    {
        Storage::fake('public');
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        Sanctum::actingAs($user);

        $response = $this->post('/api/mobile/v1/settings/brand', [
            'store_name' => 'New Store Name',
            'primary_color' => '#FF00FF',
            'announcement' => 'Eid Sale!',
            'announcement_style' => 'marquee',
            'logo' => UploadedFile::fake()->image('logo.png', 200, 200),
        ]);

        $response->assertOk()->assertJsonPath('ok', true);

        $tenant->refresh();
        $this->assertSame('New Store Name', $tenant->store_name);
        $this->assertSame('#FF00FF', $tenant->primary_color);
        $this->assertNotNull($tenant->logo_path);

        $this->getJson('/api/mobile/v1/settings/brand')->assertOk()
            ->assertJsonPath('announcement', 'Eid Sale!')
            ->assertJsonPath('announcement_style', 'marquee');
    }

    public function test_update_brand_is_tenant_isolated(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $userA = $this->makeUser($tenantA->id);

        Sanctum::actingAs($userA);
        $this->post('/api/mobile/v1/settings/brand', [
            'store_name' => 'Tenant A Store',
            'primary_color' => '#000000',
        ])->assertOk();

        $tenantB->refresh();
        $this->assertNotSame('Tenant A Store', $tenantB->store_name);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/mobile/v1/settings/brand')->assertUnauthorized();
    }
}
