<?php

namespace Tests\Concerns;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Minimal, test-only schema for the Phase 5 unified inbox tests — the first
 * suite that genuinely needs BOTH messenger_messages and whatsapp_messages
 * present at once. Deliberately self-contained (not composed from
 * InteractsWithFacebookSchema + InteractsWithWhatsAppSchema): both of those
 * already declare their own makeTenant()/makeUser(), and `use TraitA,
 * TraitB;` with colliding method names is a PHP fatal error unless resolved
 * via `insteadof` — the exact trait-collision risk InteractsWithCommerceSchema's
 * own docblock already flags as the reason it stays self-contained too.
 */
trait InteractsWithInboxSchema
{
    protected function setUpInboxSchema(): void
    {
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

        // Same shape InteractsWithFacebookSchema builds, current chunk25 state.
        if (! Schema::hasTable('messenger_messages')) {
            Schema::create('messenger_messages', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('facebook_page_id')->nullable();
                $table->string('sender_psid', 100);
                $table->string('mid', 100)->nullable()->unique();
                $table->string('customer_name', 150)->nullable();
                $table->text('message_text')->nullable();
                $table->string('attachment_url', 500)->nullable();
                $table->string('attachment_type', 20)->nullable();
                $table->string('attachment_name', 255)->nullable();
                $table->string('direction', 10)->default('in');
                $table->string('status', 20)->default('new');
                $table->timestamp('created_at')->nullable();
            });
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

        // layouts/panel.blade.php's notification badge + SettingController::
        // index() touch these on any full panel-page render.
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
            'created_at' => now(),
            'updated_at' => now(),
        ], $attrs));

        return User::find($id);
    }
}
