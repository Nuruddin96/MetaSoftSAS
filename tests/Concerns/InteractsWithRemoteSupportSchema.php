<?php

namespace Tests\Concerns;

use App\Models\SuperAdmin;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Minimal, test-only schema for the Remote Support feature tests — same
 * rationale as every other InteractsWith*Schema trait (this project's real
 * schema lives in database/sql/, not database/migrations/, so
 * Schema::create is used directly rather than running migrations — see
 * AGENTS.md). Composed with InteractsWithApiSchema for
 * tenants/users/personal_access_tokens, since Remote Support's device
 * credential reuses Sanctum's token table (see MobileDevice's docblock).
 */
trait InteractsWithRemoteSupportSchema
{
    use InteractsWithApiSchema;

    protected function setUpRemoteSupportSchema(): void
    {
        $this->setUpApiSchema();

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

        if (! Schema::hasTable('remote_support_settings')) {
            Schema::create('remote_support_settings', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->unique();
                $table->boolean('enabled')->default(false);
                $table->unsignedBigInteger('enabled_by_super_admin_id')->nullable();
                $table->timestamp('enabled_at')->nullable();
                $table->unsignedBigInteger('disabled_by_super_admin_id')->nullable();
                $table->timestamp('disabled_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('mobile_devices')) {
            Schema::create('mobile_devices', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('user_id');
                $table->string('device_uuid', 64)->unique();
                $table->string('platform', 20)->default('android');
                $table->string('device_model', 150)->nullable();
                $table->string('os_version', 50)->nullable();
                $table->string('app_version', 30)->nullable();
                $table->string('status', 30)->default('pending_verification');
                $table->string('verification_code', 12)->nullable();
                $table->timestamp('verified_at')->nullable();
                $table->unsignedBigInteger('approved_by_super_admin_id')->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->unsignedBigInteger('revoked_by_super_admin_id')->nullable();
                $table->timestamp('revoked_at')->nullable();
                $table->string('revoke_reason', 255)->nullable();
                $table->boolean('remote_support_enabled')->default(false);
                $table->unsignedBigInteger('credential_token_id')->nullable();
                $table->timestamp('last_seen_at')->nullable();
                $table->unsignedTinyInteger('battery_pct')->nullable();
                $table->boolean('charging')->nullable();
                $table->string('network_type', 20)->nullable();
                $table->json('permissions')->nullable();
                $table->boolean('foreground_service_running')->default(false);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('remote_support_sessions')) {
            Schema::create('remote_support_sessions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('mobile_device_id');
                $table->unsignedBigInteger('started_by_super_admin_id');
                $table->string('status', 20)->default('pending');
                $table->string('session_token', 64)->unique();
                $table->boolean('include_microphone')->default(false);
                $table->boolean('include_camera')->default(false);
                $table->timestamp('started_at')->nullable();
                $table->timestamp('connected_at')->nullable();
                $table->timestamp('ended_at')->nullable();
                $table->string('end_reason', 40)->nullable();
                $table->timestamp('expires_at');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('remote_support_signals')) {
            Schema::create('remote_support_signals', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('remote_support_session_id');
                $table->string('sender', 10);
                $table->string('type', 20);
                $table->longText('payload');
                $table->timestamp('created_at')->nullable();
            });
        }

        if (! Schema::hasTable('device_events')) {
            Schema::create('device_events', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->nullable();
                $table->unsignedBigInteger('mobile_device_id')->nullable();
                $table->unsignedBigInteger('remote_support_session_id')->nullable();
                $table->string('event_type', 60);
                $table->string('actor_type', 20);
                $table->unsignedBigInteger('actor_id')->nullable();
                $table->text('note')->nullable();
                $table->timestamp('created_at')->nullable();
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
}
