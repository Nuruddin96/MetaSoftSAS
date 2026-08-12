<?php

namespace Tests\Concerns;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * Minimal, test-only schema for the AI Customer Support Agent feature
 * tests (tenant toggle, Messenger webhook dispatch, the queued job). Same
 * rationale as InteractsWithFacebookSchema/InteractsWithCommerceSchema —
 * this project's real schema lives in database/sql/, not
 * database/migrations/ (see CLAUDE.md) — so this hand-builds only the
 * columns these tests touch.
 *
 * Deliberately self-contained (not composed with the other schema traits)
 * for the same trait-collision reason InteractsWithCommerceSchema already
 * documents, and uses the legacy messenger_settings page-resolution path
 * rather than facebook_pages — these tests don't exercise Facebook OAuth
 * at all.
 */
trait InteractsWithAiAgentSchema
{
    protected function setUpAiAgentSchema(): void
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

        if (! Schema::hasTable('store_settings')) {
            Schema::create('store_settings', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->string('key', 100);
                $table->string('value', 255)->nullable();
                $table->timestamps();
            });
        }

        // See database/sql/chunk30.sql for the real (MySQL) definition —
        // the ENUM there becomes a plain string column here, same
        // simplification every other schema trait in this suite makes.
        if (! Schema::hasTable('ai_agent_message_jobs')) {
            Schema::create('ai_agent_message_jobs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('messenger_message_id')->unique();
                $table->string('status', 20)->default('pending');
                $table->timestamps();
            });
        }

        // layouts/panel.blade.php (rendered by every real panel page,
        // including Settings) unconditionally queries these for the
        // notification-bell badge count.
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

    /** Registers a legacy messenger_settings page for a tenant. */
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

    protected function enableAiAgent(int $tenantId): void
    {
        DB::table('store_settings')->updateOrInsert(
            ['tenant_id' => $tenantId, 'key' => 'ai_agent_enabled'],
            ['value' => '1', 'created_at' => now(), 'updated_at' => now()]
        );
    }

    protected function postSignedMessengerWebhook(array $payload): TestResponse
    {
        $body = json_encode($payload);
        $signature = 'sha256='.hash_hmac('sha256', $body, 'test-secret');

        return $this->call('POST', '/webhook/messenger', [], [], [], $this->transformHeadersToServerVars([
            'X-Hub-Signature-256' => $signature,
            'CONTENT_TYPE' => 'application/json',
        ]), $body);
    }

    protected function inboundMessengerPayload(string $pageId, string $psid, string $mid, string $text): array
    {
        return [
            'object' => 'page',
            'entry' => [[
                'id' => $pageId,
                'messaging' => [[
                    'sender' => ['id' => $psid],
                    'recipient' => ['id' => $pageId],
                    'message' => ['mid' => $mid, 'text' => $text],
                ]],
            ]],
        ];
    }

    protected function echoMessengerPayload(string $pageId, string $psid, string $mid, string $text): array
    {
        return [
            'object' => 'page',
            'entry' => [[
                'id' => $pageId,
                'messaging' => [[
                    'sender' => ['id' => $pageId],
                    'recipient' => ['id' => $psid],
                    'message' => ['is_echo' => true, 'mid' => $mid, 'text' => $text],
                ]],
            ]],
        ];
    }
}
