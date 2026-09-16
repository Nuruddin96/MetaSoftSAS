<?php

namespace Tests\Feature\SuperAdmin;

use App\Models\AppUpdateConfig;
use App\Models\SuperAdmin;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Centralized Android APK update config — Super Admin only
 * (SuperAdmin\AppUpdateController), a single GLOBAL row (see
 * App\Models\AppUpdateConfig's docblock). Same manually-created,
 * self-contained schema pattern as AnnouncementControllerTest.
 */
class AppUpdateControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ValidateCsrfToken::class);

        if (! Schema::hasTable('super_admins')) {
            Schema::create('super_admins', function (Blueprint $table) {
                $table->id();
                $table->string('name', 150);
                $table->string('email', 150)->unique();
                $table->string('password');
                $table->rememberToken();
                $table->timestamps();
            });
        }

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

    protected function makeSuperAdmin(): SuperAdmin
    {
        return SuperAdmin::create([
            'name' => 'Admin',
            'email' => 'admin-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
        ]);
    }

    protected function validPayload(array $overrides = []): array
    {
        return array_merge([
            'latest_version' => '1.1.0',
            'latest_build' => 2,
            'minimum_supported_version' => '1.0.5',
            'minimum_supported_build' => 6,
            'release_notes' => 'Bug fixes.',
        ], $overrides);
    }

    public function test_guest_cannot_update_app_update_config(): void
    {
        $response = $this->post(route('super.app-update.update'), $this->validPayload());

        $response->assertRedirect();
        $this->assertSame('1.0.0', AppUpdateConfig::current()->latest_version);
    }

    public function test_super_admin_can_save_version_fields(): void
    {
        $admin = $this->makeSuperAdmin();

        $response = $this->actingAs($admin, 'super_admin')
            ->post(route('super.app-update.update'), $this->validPayload(['force_update' => '1']));

        $response->assertRedirect();

        $config = AppUpdateConfig::current();
        $this->assertSame('1.1.0', $config->latest_version);
        $this->assertSame(2, $config->latest_build);
        $this->assertSame('1.0.5', $config->minimum_supported_version);
        $this->assertSame(6, $config->minimum_supported_build);
        $this->assertTrue($config->force_update);
    }

    public function test_omitting_force_update_checkbox_turns_it_off(): void
    {
        $admin = $this->makeSuperAdmin();
        AppUpdateConfig::current()->update(['force_update' => true]);

        $this->actingAs($admin, 'super_admin')
            ->post(route('super.app-update.update'), $this->validPayload())
            ->assertRedirect();

        $this->assertFalse(AppUpdateConfig::current()->fresh()->force_update);
    }

    public function test_super_admin_can_upload_an_apk_and_it_is_publicly_downloadable(): void
    {
        Storage::fake('public');
        $admin = $this->makeSuperAdmin();

        $response = $this->actingAs($admin, 'super_admin')->post(route('super.app-update.update'), $this->validPayload([
            'apk' => UploadedFile::fake()->create('release.apk', 100, 'application/vnd.android.package-archive'),
        ]));

        $response->assertRedirect();

        $config = AppUpdateConfig::current()->fresh();
        $this->assertNotNull($config->apk_path);
        Storage::disk('public')->assertExists($config->apk_path);
        $this->assertStringEndsWith('.apk', $config->apk_path);
    }

    public function test_uploading_a_non_apk_file_is_rejected(): void
    {
        Storage::fake('public');
        $admin = $this->makeSuperAdmin();

        $response = $this->actingAs($admin, 'super_admin')->post(route('super.app-update.update'), $this->validPayload([
            'apk' => UploadedFile::fake()->create('not-an-apk.txt', 10, 'text/plain'),
        ]));

        $response->assertSessionHasErrors('apk');
        $this->assertNull(AppUpdateConfig::current()->apk_path);
    }

    public function test_saving_without_a_new_apk_keeps_the_previously_uploaded_one(): void
    {
        Storage::fake('public');
        $admin = $this->makeSuperAdmin();
        AppUpdateConfig::current()->update(['apk_path' => 'app-releases/existing.apk']);
        Storage::disk('public')->put('app-releases/existing.apk', 'bytes');

        $this->actingAs($admin, 'super_admin')
            ->post(route('super.app-update.update'), $this->validPayload())
            ->assertRedirect();

        $this->assertSame('app-releases/existing.apk', AppUpdateConfig::current()->fresh()->apk_path);
    }

    public function test_uploading_a_new_apk_replaces_and_deletes_the_old_file(): void
    {
        Storage::fake('public');
        $admin = $this->makeSuperAdmin();
        Storage::disk('public')->put('app-releases/old.apk', 'old-bytes');
        AppUpdateConfig::current()->update(['apk_path' => 'app-releases/old.apk']);

        $this->actingAs($admin, 'super_admin')->post(route('super.app-update.update'), $this->validPayload([
            'apk' => UploadedFile::fake()->create('new-release.apk', 50, 'application/vnd.android.package-archive'),
        ]))->assertRedirect();

        Storage::disk('public')->assertMissing('app-releases/old.apk');
        $this->assertNotSame('app-releases/old.apk', AppUpdateConfig::current()->fresh()->apk_path);
    }

    public function test_saving_twice_does_not_create_a_second_row(): void
    {
        $admin = $this->makeSuperAdmin();

        $this->actingAs($admin, 'super_admin')->post(route('super.app-update.update'), $this->validPayload(['latest_build' => 2]))->assertRedirect();
        $this->actingAs($admin, 'super_admin')->post(route('super.app-update.update'), $this->validPayload(['latest_build' => 3]))->assertRedirect();

        $this->assertSame(1, AppUpdateConfig::count());
        $this->assertSame(3, AppUpdateConfig::current()->latest_build);
    }
}
