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
 * Minimal, test-only schema for the "Connect WordPress" feature tests.
 * Same rationale as InteractsWithFacebookSchema/InteractsWithWhatsAppSchema:
 * this project's real schema lives in database/sql/ (see CLAUDE.md — "NOT
 * migration-driven"), not database/migrations/, so this trait hand-builds
 * only the exact columns these tests touch via Schema::create — nothing
 * here is deployed or read by the app outside the test run.
 */
trait InteractsWithWordPressSchema
{
    /**
     * @param  bool  $includeAllTables  Pass false to simulate an environment
     *                                  where database/sql/chunk59.sql hasn't been imported at all —
     *                                  tenants/users still get created, but neither wordpress_connections
     *                                  nor wordpress_connection_tokens exist, exactly as
     *                                  WordPressConnection::tablesReady() is meant to detect.
     */
    protected function setUpWordPressSchema(bool $includeAllTables = true): void
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
                $table->string('custom_domain')->nullable();
                $table->boolean('custom_domain_verified')->default(false);
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
                $table->rememberToken();
                $table->timestamps();
            });
        }

        // Sanctum's own table — real schema comes from database/migrations/
        // 2026_08_20_044218_create_personal_access_tokens_table.php in
        // production, but these tests build schema by hand (see class
        // docblock), same as InteractsWithApiSchema.
        if (! Schema::hasTable('personal_access_tokens')) {
            Schema::create('personal_access_tokens', function (Blueprint $table) {
                $table->id();
                $table->morphs('tokenable');
                $table->text('name');
                $table->string('token', 64)->unique();
                $table->text('abilities')->nullable();
                $table->timestamp('last_used_at')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamps();
            });
        }

        // layouts/panel.blade.php (rendered by every real panel page,
        // including this feature's index page) unconditionally queries all
        // four for the notification-bell badge count — needed so a full
        // HTTP-level render doesn't fail on a missing table. Same stubs
        // InteractsWithFacebookSchema/InteractsWithWhatsAppSchema use for
        // the same reason.
        if (! Schema::hasTable('orders')) {
            Schema::create('orders', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->string('status', 20)->default('pending');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('product_variants')) {
            Schema::create('product_variants', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->integer('low_stock_threshold')->default(5);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('inventory')) {
            Schema::create('inventory', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('variant_id');
                $table->integer('quantity')->default(0);
                $table->timestamp('updated_at')->nullable();
            });
        }

        if (! Schema::hasTable('incomplete_orders')) {
            Schema::create('incomplete_orders', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->string('status', 20)->default('abandoned');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('messenger_messages')) {
            Schema::create('messenger_messages', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->string('status', 20)->default('new');
                $table->timestamp('created_at')->nullable();
            });
        }

        if (! $includeAllTables) {
            return;
        }

        if (! Schema::hasTable('wordpress_connections')) {
            Schema::create('wordpress_connections', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->unique();
                $table->unsignedBigInteger('connected_by_user_id');
                $table->string('site_url', 255);
                $table->string('site_name', 150)->nullable();
                $table->string('wp_rest_url', 255)->nullable();
                $table->string('plugin_version', 20)->nullable();
                $table->string('wp_version', 20)->nullable();
                $table->boolean('woocommerce_active')->default(false);
                $table->string('woocommerce_version', 20)->nullable();
                $table->text('outbound_secret')->nullable();
                $table->string('status', 20)->default('pending');
                $table->timestamp('connected_at')->nullable();
                $table->timestamp('last_verified_at')->nullable();
                $table->timestamp('disconnected_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('wordpress_connection_tokens')) {
            Schema::create('wordpress_connection_tokens', function (Blueprint $table) {
                $table->id();
                $table->string('token', 64)->unique();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('user_id');
                $table->timestamp('expires_at');
                $table->timestamp('used_at')->nullable();
                $table->timestamp('created_at')->nullable();
            });
        }
    }

    /**
     * Every WordPress "generate key" action sits behind
     * 'feature:wordpress_connect' (EnsureFeatureEnabled, reading
     * Plan::hasFeature()) — mirrors WhatsAppFeatureTestCase's
     * makeWhatsAppEnabledPlan()/makeTenant() default-plan pattern so
     * connection tests exercise the connect flow itself, not this gate.
     */
    protected function makeTenant(array $attrs = []): Tenant
    {
        if (! array_key_exists('plan_id', $attrs)) {
            $attrs['plan_id'] = $this->makeWordPressEnabledPlan()->id;
        }

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

    protected function makeWordPressEnabledPlan(array $attrs = []): Plan
    {
        return Plan::create(array_merge([
            'name' => 'Test Plan',
            'slug' => 'test-plan-'.strtolower(Str::random(10)),
            'features' => ['wordpress_connect'],
        ], $attrs));
    }

    protected function makeUser(int $tenantId, array $attrs = []): User
    {
        $id = DB::table('users')->insertGetId(array_merge([
            'tenant_id' => $tenantId,
            'name' => 'Test Owner',
            'email' => 'owner-'.Str::random(10).'@example.com',
            'password' => bcrypt('password'),
            'role' => 'owner',
            'created_at' => now(),
            'updated_at' => now(),
        ], $attrs));

        return User::find($id);
    }
}
