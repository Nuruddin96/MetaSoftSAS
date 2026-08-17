<?php

namespace Tests\Concerns;

use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Minimal, test-only schema for the Web Push feature tests — same
 * rationale as InteractsWithWhatsAppSchema/InteractsWithCommerceSchema:
 * this project's real schema lives in database/sql/ (see CLAUDE.md — "NOT
 * migration-driven"), not database/migrations/. Deliberately self-
 * contained rather than composed with the other Interacts*Schema traits,
 * same trait-collision avoidance they already follow relative to each
 * other.
 */
trait InteractsWithPushSchema
{
    /** Override to false in a test class to simulate database/sql/chunk31.sql not being imported at all. */
    protected function setUpPushSchema(bool $includeAllTables = true): void
    {
        if (! Schema::hasTable('plans')) {
            Schema::create('plans', function (Blueprint $table) {
                $table->id();
                $table->string('name', 100);
                $table->string('slug', 100)->unique();
                $table->json('features')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('tenants')) {
            Schema::create('tenants', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('plan_id')->nullable();
                $table->string('subdomain')->unique();
                $table->string('store_name');
                $table->string('status')->default('active');
                $table->timestamp('trial_ends_at')->nullable();
                $table->timestamp('subscription_ends_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->string('name');
                $table->string('email');
                $table->string('password');
                $table->string('role')->default('owner');
                $table->boolean('is_active')->default(1);
                $table->rememberToken();
                $table->timestamps();
            });
        }

        if (! $includeAllTables) {
            return;
        }

        if (! Schema::hasTable('push_subscriptions')) {
            Schema::create('push_subscriptions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('user_id');
                $table->text('endpoint');
                $table->char('endpoint_hash', 64);
                $table->string('p256dh_key');
                $table->string('auth_key');
                $table->string('platform', 20)->default('web');
                $table->string('device_name', 150)->nullable();
                $table->string('user_agent', 255)->nullable();
                $table->timestamp('last_seen_at')->nullable();
                $table->boolean('is_active')->default(1);
                $table->timestamps();
                // Tenant-scoped, not global — see database/sql/chunk31.sql's
                // docblock for why a bare endpoint_hash unique key is wrong.
                $table->unique(['tenant_id', 'endpoint_hash']);
            });
        }

        if (! Schema::hasTable('notifications')) {
            Schema::create('notifications', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('user_id');
                $table->string('category', 30);
                $table->string('title', 150);
                $table->string('body', 255);
                $table->string('url', 255)->nullable();
                $table->string('tag', 150)->nullable();
                $table->json('data')->nullable();
                $table->timestamp('read_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('notification_preferences')) {
            Schema::create('notification_preferences', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->string('category', 30);
                $table->boolean('enabled')->default(1);
                $table->timestamps();
                $table->unique(['user_id', 'category']);
            });
        }
    }

    protected function makeTenant(array $attrs = []): Tenant
    {
        $id = DB::table('tenants')->insertGetId(array_merge([
            'subdomain' => 'test-'.strtolower(Str::random(10)),
            'store_name' => 'Test Store',
            'status' => 'active',
            'subscription_ends_at' => now()->addYear(),
            'created_at' => now(),
            'updated_at' => now(),
        ], $attrs));

        return Tenant::find($id);
    }

    protected function makeUser(int $tenantId, array $attrs = []): User
    {
        $id = DB::table('users')->insertGetId(array_merge([
            'tenant_id' => $tenantId,
            'name' => 'Test Owner',
            'email' => 'owner-'.Str::random(10).'@example.com',
            'password' => bcrypt('password'),
            'role' => 'owner',
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ], $attrs));

        return User::find($id);
    }
}
