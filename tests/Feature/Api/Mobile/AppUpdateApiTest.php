<?php

namespace Tests\Feature\Api\Mobile;

use App\Models\AppUpdateConfig;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Centralized Android APK update check — GET /api/mobile/v1/app-version
 * (Api\Mobile\AppUpdateController). Deliberately public/unauthenticated;
 * see that controller's docblock for why. Same lightweight, manually
 * created single-table schema pattern as AnnouncementControllerTest
 * (this table has no tenant/session dependency at all, so no other
 * schema trait is needed).
 */
class AppUpdateApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('app_update_configs')) {
            Schema::create('app_update_configs', function (Blueprint $table) {
                $table->id();
                $table->string('latest_version', 20)->default('1.0.0');
                $table->unsignedInteger('latest_build')->default(1);
                $table->string('minimum_supported_version', 20)->default('1.0.0');
                $table->unsignedInteger('minimum_supported_build')->default(1);
                $table->string('apk_path')->nullable();
                $table->text('release_notes')->nullable();
                $table->boolean('force_update')->default(false);
                $table->timestamps();
            });
        }
    }

    public function test_guest_can_check_app_version_without_authentication(): void
    {
        $this->getJson('/api/mobile/v1/app-version')->assertOk();
    }

    public function test_returns_column_defaults_when_nothing_has_been_configured_yet(): void
    {
        $response = $this->getJson('/api/mobile/v1/app-version');

        $response->assertOk()
            ->assertJsonPath('latest_version', '1.0.0')
            ->assertJsonPath('latest_build', 1)
            ->assertJsonPath('minimum_supported_version', '1.0.0')
            ->assertJsonPath('minimum_supported_build', 1)
            ->assertJsonPath('download_url', null)
            ->assertJsonPath('force_update', false);
    }

    public function test_returns_the_configured_values_and_does_not_create_a_second_row(): void
    {
        AppUpdateConfig::current()->update([
            'latest_version' => '1.1.0',
            'latest_build' => 2,
            'minimum_supported_version' => '1.0.5',
            'minimum_supported_build' => 6,
            'release_notes' => 'Bug fixes and performance improvements.',
            'force_update' => true,
        ]);

        $response = $this->getJson('/api/mobile/v1/app-version');

        $response->assertOk()
            ->assertJsonPath('latest_version', '1.1.0')
            ->assertJsonPath('latest_build', 2)
            ->assertJsonPath('minimum_supported_version', '1.0.5')
            ->assertJsonPath('minimum_supported_build', 6)
            ->assertJsonPath('release_notes', 'Bug fixes and performance improvements.')
            ->assertJsonPath('force_update', true);

        $this->assertSame(1, AppUpdateConfig::count());
    }

    public function test_download_url_is_a_stable_public_storage_url_when_an_apk_is_configured(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('app-releases/release.apk', 'fake-apk-bytes');

        AppUpdateConfig::current()->update(['apk_path' => 'app-releases/release.apk']);

        $response = $this->getJson('/api/mobile/v1/app-version');

        $response->assertOk();
        $url = $response->json('download_url');
        $this->assertNotNull($url);
        $this->assertStringContainsString('/storage/app-releases/release.apk', $url);
    }

    public function test_response_never_exposes_the_raw_filesystem_path(): void
    {
        AppUpdateConfig::current()->update(['apk_path' => 'app-releases/release.apk']);

        $response = $this->getJson('/api/mobile/v1/app-version');

        $response->assertOk();
        $this->assertArrayNotHasKey('apk_path', $response->json());
    }
}
