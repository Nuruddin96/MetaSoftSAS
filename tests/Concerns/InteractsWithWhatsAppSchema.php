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
 * Minimal, test-only schema for the WhatsApp Cloud API feature tests.
 * Same rationale as InteractsWithFacebookSchema: this project's real schema
 * lives in database/sql/ (see CLAUDE.md — "NOT migration-driven"), not
 * database/migrations/, so RefreshDatabase against the stock cache/jobs
 * migrations wouldn't create any of the tables these tests need. This trait
 * hand-builds only the exact columns these tests touch, via Schema::create
 * — nothing here is deployed or read by the app outside the test run.
 *
 * Deliberately self-contained rather than composed with
 * InteractsWithFacebookSchema/InteractsWithCommerceSchema — same
 * trait-collision avoidance those two already follow relative to each
 * other (see InteractsWithCommerceSchema's docblock).
 */
trait InteractsWithWhatsAppSchema
{
    /**
     * @param  bool  $includeAllTables  Pass false to simulate an environment
     *   where database/sql/chunk26.sql hasn't been imported at all —
     *   tenants/users still get created, but none of the four WhatsApp
     *   tables exist, exactly as WhatsAppPhoneNumber::tablesReady() is
     *   meant to detect.
     */
    protected function setUpWhatsAppSchema(bool $includeAllTables = true): void
    {
        // Needed so Tenant::plan()/Plan::hasFeature('whatsapp') can be
        // exercised by the feature-gate tests, and so makeTenant() can grant
        // the feature by default without every other WhatsApp test having to
        // know that gate exists — mirrors production's tenants.plan_id
        // NOT NULL FK (schema.sql), except nullable here since this stub
        // schema (unlike production) predates the feature-gate phase and
        // some tests may want an explicitly plan-less tenant.
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

        // Unrelated to WhatsApp, but layouts/panel.blade.php (rendered by
        // every real panel page, including Settings) unconditionally
        // queries all four for the notification-bell badge count, and
        // SettingController::index() itself gathers courier/marketing/
        // store/messenger data regardless of WhatsApp readiness — needed so
        // a full HTTP-level render of the Settings page doesn't fail on a
        // missing table. Mirrors InteractsWithFacebookSchema's identical
        // stubs for the same reason.
        if (! Schema::hasTable('courier_settings')) {
            Schema::create('courier_settings', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->string('provider', 30);
                $table->text('credentials')->nullable();
                $table->boolean('is_active')->default(false);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('marketing_settings')) {
            Schema::create('marketing_settings', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->string('fb_pixel_id', 50)->nullable();
                $table->text('fb_capi_token')->nullable();
                $table->string('fb_test_event_code', 50)->nullable();
                $table->string('gtm_container_id', 20)->nullable();
                $table->string('meta_app_id', 50)->nullable();
                $table->text('meta_app_secret')->nullable();
                $table->text('meta_access_token')->nullable();
                $table->string('meta_ad_account_id', 50)->nullable();
                $table->timestamp('updated_at')->nullable();
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

        if (! Schema::hasTable('messenger_settings')) {
            Schema::create('messenger_settings', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->unique();
                $table->string('page_id', 50)->unique();
                $table->text('page_access_token');
                $table->string('page_name', 150)->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

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
                $table->string('sender_psid', 100);
                $table->string('mid', 100)->nullable()->unique();
                $table->string('customer_name', 150)->nullable();
                $table->text('message_text')->nullable();
                $table->string('attachment_url', 500)->nullable();
                $table->string('direction', 10)->default('in');
                $table->string('status', 20)->default('new');
                $table->timestamp('created_at')->nullable();
            });
        }

        if (! $includeAllTables) {
            return;
        }

        if (! Schema::hasTable('whatsapp_oauth_states')) {
            Schema::create('whatsapp_oauth_states', function (Blueprint $table) {
                $table->id();
                $table->string('state', 64)->unique();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('user_id');
                $table->string('purpose', 30)->default('whatsapp');
                $table->timestamp('expires_at');
                $table->timestamp('used_at')->nullable();
                $table->timestamp('created_at')->nullable();
            });
        }

        if (! Schema::hasTable('whatsapp_business_accounts')) {
            Schema::create('whatsapp_business_accounts', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->unique();
                $table->unsignedBigInteger('connected_by_user_id');
                $table->string('waba_id', 64)->unique();
                $table->string('business_id', 64)->nullable();
                $table->string('business_name', 150)->nullable();
                $table->text('user_access_token');
                $table->timestamp('token_expires_at')->nullable();
                $table->string('granted_scopes', 500)->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('whatsapp_phone_numbers')) {
            Schema::create('whatsapp_phone_numbers', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('whatsapp_business_account_id');
                $table->string('phone_number_id', 64)->unique();
                $table->string('display_phone_number', 30)->nullable();
                $table->string('verified_name', 150)->nullable();
                $table->string('quality_rating', 20)->nullable();
                $table->string('status', 30)->default('active');
                $table->boolean('is_active')->default(true);
                $table->timestamp('subscribed_at')->nullable();
                $table->timestamp('disconnected_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('whatsapp_messages')) {
            Schema::create('whatsapp_messages', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('whatsapp_phone_number_id')->nullable();
                $table->string('wa_id', 30);
                $table->string('wamid', 100)->nullable()->unique();
                $table->string('customer_name', 150)->nullable();
                $table->string('message_type', 20)->nullable();
                $table->text('message_text')->nullable();
                $table->string('attachment_url', 500)->nullable();
                $table->string('attachment_type', 20)->nullable();
                $table->string('attachment_name', 255)->nullable();
                $table->json('raw_payload')->nullable();
                $table->string('direction', 10)->default('in');
                $table->string('status', 20)->default('new');
                $table->string('delivery_status', 20)->nullable();
                $table->string('error_code', 30)->nullable();
                $table->string('error_message', 500)->nullable();
                $table->timestamp('created_at')->nullable();
            });
        }
    }

    /**
     * Every WhatsApp connect/inbox route now sits behind 'feature:whatsapp'
     * (EnsureFeatureEnabled, reading Plan::hasFeature()) — a tenant with no
     * plan, or a plan whose features don't include 'whatsapp', gets
     * redirected before ever reaching the controller. Defaulting every
     * makeTenant() call to a plan that already has the feature keeps every
     * existing connection/inbox test exercising the behavior it was written
     * to test, not this gate; WhatsAppFeatureGateTest covers the gate itself
     * (both the blocked and explicitly-plan-less-tenant cases).
     */
    protected function makeTenant(array $attrs = []): Tenant
    {
        if (! array_key_exists('plan_id', $attrs)) {
            $attrs['plan_id'] = $this->makeWhatsAppEnabledPlan()->id;
        }

        $id = DB::table('tenants')->insertGetId(array_merge([
            // routes/web.php constrains tenant_slug to [a-z0-9-]+ in path
            // mode — Str::random() is mixed-case and would 404 before ever
            // reaching resolve.tenant, so lowercase it explicitly here.
            'subdomain' => 'test-'.strtolower(Str::random(10)),
            'store_name' => 'Test Store',
            'status' => 'active',
            'subscription_ends_at' => now()->addYear(),
            'created_at' => now(),
            'updated_at' => now(),
        ], $attrs));

        return Tenant::find($id);
    }

    protected function makeWhatsAppEnabledPlan(array $attrs = []): Plan
    {
        return Plan::create(array_merge([
            'name' => 'Test Plan',
            'slug' => 'test-plan-'.strtolower(Str::random(10)),
            'features' => ['whatsapp'],
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
