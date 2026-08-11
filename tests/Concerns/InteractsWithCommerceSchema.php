<?php

namespace Tests\Concerns;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Minimal, test-only schema for the Messenger -> Order feature tests
 * (auto pending-order creation, complete()/confirm, tenant isolation, the
 * /panel/messenger/updates polling endpoint). Same rationale as
 * InteractsWithFacebookSchema — this project's real schema lives in
 * database/sql/, not database/migrations/ (see CLAUDE.md), so this trait
 * hand-builds only the columns these tests touch via Schema::create.
 *
 * Deliberately self-contained rather than composed with
 * InteractsWithFacebookSchema — these tests exercise the legacy
 * messenger_settings page-resolution path (matching
 * MessengerWebhookRegressionTest::test_legacy_messenger_settings_resolution_still_works_unchanged),
 * not the OAuth facebook_pages tables, so there's no need for those tables
 * or the trait-collision risk of combining two schema-setup traits.
 */
trait InteractsWithCommerceSchema
{
    protected function setUpCommerceSchema(): void
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
                $table->boolean('is_active')->default(true);
                $table->rememberToken();
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

        // layouts/panel.blade.php (rendered by every real panel page) queries
        // this unconditionally for the notification-bell badge count.
        if (! Schema::hasTable('incomplete_orders')) {
            Schema::create('incomplete_orders', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->string('status', 20)->default('abandoned');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('categories')) {
            Schema::create('categories', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->string('name', 150);
                $table->string('slug', 180)->nullable();
                $table->unsignedBigInteger('parent_id')->nullable();
                $table->string('image_path', 255)->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('expenses')) {
            Schema::create('expenses', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('expense_category_id')->nullable();
                $table->string('title');
                $table->decimal('amount', 12, 2);
                $table->date('expense_date');
                $table->text('note')->nullable();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('banners')) {
            Schema::create('banners', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->string('image_path', 255)->nullable();
                $table->string('title', 150)->nullable();
                $table->string('subtitle', 255)->nullable();
                $table->string('button_text', 50)->nullable();
                $table->string('button_link', 255)->nullable();
                $table->integer('sort_order')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        // Website builder "pages" — distinct from the storefront-rendered
        // ones, same table name as the real schema (database/sql/chunk6.sql).
        if (! Schema::hasTable('pages')) {
            Schema::create('pages', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->string('title', 150);
                $table->string('slug', 180)->nullable();
                $table->longText('content')->nullable();
                $table->boolean('show_in_header')->default(false);
                $table->boolean('show_in_footer')->default(true);
                $table->integer('sort_order')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('product_images')) {
            Schema::create('product_images', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('product_id');
                $table->string('image_path', 255);
                $table->integer('sort_order')->default(0);
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

        if (! Schema::hasTable('bd_upazilas')) {
            Schema::create('bd_upazilas', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('district_id');
                $table->string('name', 80);
                $table->string('bn_name', 80);
            });
        }

        if (! Schema::hasTable('customers')) {
            Schema::create('customers', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->string('name', 150);
                $table->string('phone', 20);
                $table->string('email', 150)->nullable();
                $table->text('address')->nullable();
                $table->unsignedInteger('division_id')->nullable();
                $table->unsignedInteger('district_id')->nullable();
                $table->unsignedInteger('upazila_id')->nullable();
                $table->decimal('due_balance', 12, 2)->default(0);
                $table->integer('total_orders')->default(0);
                $table->decimal('total_spent', 14, 2)->default(0);
                $table->text('note')->nullable();
                $table->timestamps();
                $table->unique(['tenant_id', 'phone']);
            });
        }

        if (! Schema::hasTable('due_ledger')) {
            Schema::create('due_ledger', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('customer_id');
                $table->unsignedBigInteger('order_id')->nullable();
                $table->string('type', 10);
                $table->decimal('amount', 12, 2);
                $table->decimal('balance_after', 12, 2);
                $table->string('note', 255)->nullable();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->timestamp('created_at')->nullable();
            });
        }

        if (! Schema::hasTable('warehouses')) {
            Schema::create('warehouses', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->string('name', 150);
                $table->string('address', 255)->nullable();
                $table->boolean('is_default')->default(false);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('products')) {
            Schema::create('products', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('category_id')->nullable();
                $table->string('name');
                $table->string('slug', 280)->nullable();
                $table->boolean('has_variants')->default(false);
                $table->boolean('is_active')->default(true);
                $table->boolean('is_featured')->default(false);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('product_variants')) {
            Schema::create('product_variants', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('product_id');
                $table->string('sku', 80)->nullable();
                $table->string('barcode', 80)->nullable();
                $table->string('variant_name', 150)->default('Default');
                $table->json('attributes')->nullable();
                $table->decimal('purchase_price', 12, 2)->default(0);
                $table->decimal('selling_price', 12, 2);
                $table->decimal('compare_at_price', 12, 2)->nullable();
                $table->integer('low_stock_threshold')->default(5);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('inventory')) {
            Schema::create('inventory', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('variant_id');
                $table->unsignedBigInteger('warehouse_id');
                $table->integer('quantity')->default(0);
                $table->timestamp('updated_at')->nullable();
            });
        }

        if (! Schema::hasTable('stock_movements')) {
            Schema::create('stock_movements', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->nullable();
                $table->unsignedBigInteger('variant_id');
                $table->unsignedBigInteger('warehouse_id');
                $table->string('type', 20);
                $table->integer('quantity');
                $table->string('reference_type', 50)->nullable();
                $table->unsignedBigInteger('reference_id')->nullable();
                $table->string('note', 255)->nullable();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->timestamp('created_at')->nullable();
            });
        }

        if (! Schema::hasTable('orders')) {
            Schema::create('orders', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->string('order_number', 30);
                $table->string('source', 20)->default('web');
                $table->string('channel', 20)->default('website');
                $table->string('messenger_psid', 100)->nullable();
                $table->unsignedBigInteger('customer_id')->nullable();
                $table->string('customer_name', 150);
                $table->string('customer_phone', 20);
                $table->text('customer_address')->nullable();
                $table->unsignedInteger('division_id')->nullable();
                $table->unsignedInteger('district_id')->nullable();
                $table->unsignedInteger('upazila_id')->nullable();
                $table->decimal('subtotal', 12, 2)->default(0);
                $table->decimal('discount', 12, 2)->default(0);
                $table->decimal('delivery_charge', 10, 2)->default(0);
                $table->decimal('total', 12, 2)->default(0);
                $table->decimal('paid_amount', 12, 2)->default(0);
                $table->decimal('due_amount', 12, 2)->default(0);
                $table->string('payment_method', 20)->default('cod');
                $table->string('payment_status', 20)->default('unpaid');
                $table->string('status', 20)->default('pending');
                $table->unsignedBigInteger('warehouse_id')->nullable();
                $table->string('courier_provider', 30)->nullable();
                $table->string('courier_consignment_id', 100)->nullable();
                $table->string('courier_tracking_code', 100)->nullable();
                $table->string('courier_status', 50)->nullable();
                $table->integer('fraud_score')->nullable();
                $table->json('fraud_summary')->nullable();
                $table->string('fb_event_id', 64)->nullable();
                $table->string('utm_source', 100)->nullable();
                $table->text('note')->nullable();
                $table->timestamp('confirmed_at')->nullable();
                $table->timestamp('delivered_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('order_items')) {
            Schema::create('order_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('order_id');
                $table->unsignedBigInteger('variant_id')->nullable();
                $table->string('product_name');
                $table->string('variant_name', 150)->nullable();
                $table->string('sku', 80)->nullable();
                $table->decimal('unit_price', 12, 2);
                $table->decimal('purchase_price', 12, 2)->default(0);
                $table->integer('quantity');
                $table->decimal('line_total', 12, 2);
            });
        }

        if (! Schema::hasTable('courier_settings')) {
            Schema::create('courier_settings', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->string('provider', 30);
                $table->text('credentials')->nullable();
                $table->boolean('is_active')->default(false);
                $table->timestamps();
                $table->unique(['tenant_id', 'provider']);
            });
        }

        // DeliveryChargeService (order calculation fix) queries this on
        // every order create/show/complete render.
        if (! Schema::hasTable('store_settings')) {
            Schema::create('store_settings', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->string('key', 100);
                $table->string('value', 255)->nullable();
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

    /** Registers a legacy messenger_settings page for a tenant, matching the pre-OAuth flow. */
    protected function makeMessengerPage(int $tenantId, string $pageId, array $attrs = []): void
    {
        DB::table('messenger_settings')->insert(array_merge([
            'tenant_id' => $tenantId,
            'page_id' => $pageId,
            'page_access_token' => encrypt('page-token'),
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ], $attrs));
    }

    /**
     * Creates one active product/variant/warehouse/inventory row for a
     * tenant — the minimum a test needs to exercise OrderController::complete().
     */
    protected function makeSellableVariant(int $tenantId, array $variantAttrs = []): \App\Models\ProductVariant
    {
        app()->instance('currentTenant', \App\Models\Tenant::find($tenantId));

        $product = \App\Models\Product::create([
            'tenant_id' => $tenantId,
            'name' => 'Test Product',
            'is_active' => 1,
        ]);

        $variant = \App\Models\ProductVariant::create(array_merge([
            'tenant_id' => $tenantId,
            'product_id' => $product->id,
            'variant_name' => 'Default',
            'selling_price' => 500,
            'purchase_price' => 300,
        ], $variantAttrs));

        $warehouse = \App\Models\Warehouse::create([
            'tenant_id' => $tenantId,
            'name' => 'Main Warehouse',
            'is_default' => 1,
        ]);

        \App\Models\Inventory::create([
            'tenant_id' => $tenantId,
            'variant_id' => $variant->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 100,
        ]);

        return $variant;
    }
}
