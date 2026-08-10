<?php

namespace Tests\Concerns;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Minimal, test-only schema for the Facebook OAuth feature tests.
 *
 * This project's real schema lives in database/sql/ (see CLAUDE.md — "NOT
 * migration-driven"), not database/migrations/, so RefreshDatabase against
 * the stock cache/jobs migrations wouldn't create any of the tables these
 * tests need. Rather than adding production-style Laravel migrations (which
 * would fight that convention and risk drifting from database/sql/chunk23.sql,
 * the authoritative source), this trait builds only the exact columns these
 * tests touch directly via Schema::create — nothing here is deployed or
 * read by the app outside the test run.
 */
trait InteractsWithFacebookSchema
{
    /**
     * @param  bool  $includeFacebookOauthTables  Pass false to simulate an
     *   environment where database/sql/chunk23.sql hasn't been imported at
     *   all — tenants/users/messenger_settings/messenger_messages still get
     *   created (so the legacy flow has something to fall back to), but
     *   facebook_oauth_states/facebook_connections/facebook_pages are left
     *   absent, exactly as FacebookPage::tablesReady() is meant to detect.
     * @param  bool  $includeFacebookPageIdColumn  Pass false to simulate a
     *   PARTIAL chunk23.sql import: the three tables above exist, but the
     *   trailing `ALTER TABLE messenger_messages ADD COLUMN facebook_page_id
     *   ...` statement in that same file did not run. Independent of
     *   $includeFacebookOauthTables so both failure modes can be tested on
     *   their own.
     * @param  bool  $includeAttachmentColumns  Pass false to simulate
     *   database/sql/chunk25.sql not having been imported yet — the
     *   attachment_type/attachment_name columns are absent, exactly as
     *   MessengerMessage::attachmentColumnsReady() is meant to detect.
     */
    protected function setUpFacebookSchema(
        bool $includeFacebookOauthTables = true,
        bool $includeFacebookPageIdColumn = true,
        bool $includeAttachmentColumns = true,
    ): void {
        if (! Schema::hasTable('tenants')) {
            Schema::create('tenants', function (Blueprint $table) {
                $table->id();
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

        if ($includeFacebookOauthTables) {
            if (! Schema::hasTable('facebook_oauth_states')) {
                Schema::create('facebook_oauth_states', function (Blueprint $table) {
                    $table->id();
                    $table->string('state', 64)->unique();
                    $table->unsignedBigInteger('tenant_id');
                    $table->unsignedBigInteger('user_id');
                    $table->string('purpose', 30)->default('messenger');
                    $table->timestamp('expires_at');
                    $table->timestamp('used_at')->nullable();
                    $table->timestamp('created_at')->nullable();
                });
            }

            if (! Schema::hasTable('facebook_connections')) {
                Schema::create('facebook_connections', function (Blueprint $table) {
                    $table->id();
                    $table->unsignedBigInteger('tenant_id')->unique();
                    $table->unsignedBigInteger('connected_by_user_id');
                    $table->string('facebook_user_id', 64);
                    $table->text('user_access_token');
                    $table->timestamp('token_expires_at')->nullable();
                    $table->string('granted_scopes', 500)->nullable();
                    $table->timestamps();
                });
            }

            if (! Schema::hasTable('facebook_pages')) {
                Schema::create('facebook_pages', function (Blueprint $table) {
                    $table->id();
                    $table->unsignedBigInteger('tenant_id');
                    $table->unsignedBigInteger('facebook_connection_id');
                    $table->string('page_id', 50)->unique();
                    $table->string('page_name', 150)->nullable();
                    $table->text('page_access_token')->nullable();
                    $table->string('status', 30)->default('active');
                    $table->boolean('is_active')->default(true);
                    $table->timestamp('subscribed_at')->nullable();
                    $table->timestamp('disconnected_at')->nullable();
                    $table->timestamps();
                });
            }
        }

        // Unrelated to Facebook, but SettingController::index() touches all
        // three on every call — needed so that controller can genuinely be
        // exercised end-to-end (not just around the Facebook portions) in
        // the schema-guard tests.
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

        // Unrelated to Facebook, but layouts/panel.blade.php (rendered by
        // every real panel page, including Settings) unconditionally queries
        // all four for the notification-bell badge count — needed so a full
        // HTTP-level render of a panel page doesn't fail on a missing table.
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
            Schema::create('messenger_messages', function (Blueprint $table) use ($includeFacebookPageIdColumn, $includeAttachmentColumns) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                if ($includeFacebookPageIdColumn) {
                    $table->unsignedBigInteger('facebook_page_id')->nullable();
                }
                $table->string('sender_psid', 100);
                $table->string('mid', 100)->nullable()->unique();
                $table->string('customer_name', 150)->nullable();
                $table->text('message_text')->nullable();
                $table->string('attachment_url', 500)->nullable();
                if ($includeAttachmentColumns) {
                    $table->string('attachment_type', 20)->nullable();
                    $table->string('attachment_name', 255)->nullable();
                }
                $table->string('direction', 10)->default('in');
                $table->string('status', 20)->default('new');
                $table->timestamp('created_at')->nullable();
            });
        }
    }

    protected function makeTenant(array $attrs = []): Tenant
    {
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
