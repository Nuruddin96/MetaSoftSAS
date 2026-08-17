<?php

namespace Tests\Concerns;

use App\Models\SuperAdmin;
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
                // Phase 14 — see database/sql/chunk39.sql's docblock.
                $table->timestamp('ai_paused_at')->nullable();
                $table->unsignedBigInteger('ai_paused_by_super_admin_id')->nullable();
                $table->string('ai_paused_reason', 255)->nullable();
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
                $table->string('sent_by', 10)->default('human');
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
                $table->string('order_number', 30)->default('');
                $table->string('messenger_psid', 100)->nullable();
                $table->string('customer_name', 150)->default('');
                $table->string('customer_phone', 20)->default('');
                $table->text('customer_address')->nullable();
                $table->string('status', 20)->default('pending');
                $table->timestamps();
            });
        }

        // Phase 5 (AiProductKnowledgeService) needs the real product/variant
        // shape, not just the low_stock_threshold-only stub the panel's
        // notification badge previously needed here — mirrors
        // InteractsWithCommerceSchema's fuller definition rather than a
        // second, differently-shaped stub.
        if (! Schema::hasTable('categories')) {
            Schema::create('categories', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->string('name', 150);
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
                $table->boolean('is_active')->default(true);
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
                $table->decimal('purchase_price', 12, 2)->default(0);
                $table->decimal('selling_price', 12, 2);
                $table->integer('low_stock_threshold')->default(5);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('warehouses')) {
            Schema::create('warehouses', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->string('name', 150);
                $table->boolean('is_default')->default(false);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('inventory')) {
            Schema::create('inventory', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('variant_id');
                $table->unsignedBigInteger('warehouse_id')->nullable();
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

        // See database/sql/chunk32.sql for the real (MySQL) definition —
        // the ENUM there becomes a plain string column here, same
        // simplification every other schema trait in this suite makes.
        if (! Schema::hasTable('ai_credit_accounts')) {
            Schema::create('ai_credit_accounts', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->unique();
                $table->decimal('balance', 12, 4)->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('ai_usage_ledger')) {
            Schema::create('ai_usage_ledger', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->string('type', 20);
                $table->decimal('credit_amount', 12, 4);
                $table->decimal('balance_after', 12, 4);
                $table->unsignedInteger('input_tokens')->nullable();
                $table->unsignedInteger('output_tokens')->nullable();
                $table->string('model', 100)->nullable();
                $table->decimal('estimated_cost_usd', 10, 6)->nullable();
                $table->string('context_type', 50)->nullable();
                $table->unsignedBigInteger('context_id')->nullable();
                $table->string('note', 255)->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamp('created_at')->nullable();
            });
        }

        // See database/sql/chunk33.sql (Phase 4 panel chat) for the real
        // (MySQL) definition.
        if (! Schema::hasTable('ai_conversations')) {
            Schema::create('ai_conversations', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('user_id');
                $table->timestamps();
                $table->unique(['tenant_id', 'user_id']);
            });
        }

        if (! Schema::hasTable('ai_conversation_messages')) {
            Schema::create('ai_conversation_messages', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('conversation_id');
                $table->string('role', 20);
                $table->text('content');
                $table->unsignedBigInteger('pending_action_id')->nullable();
                $table->timestamp('created_at')->nullable();
            });
        }

        // See database/sql/chunk34.sql (Phase 5 mutating tools +
        // confirmation system) for the real (MySQL) definition.
        if (! Schema::hasTable('ai_pending_actions')) {
            Schema::create('ai_pending_actions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('user_id');
                $table->unsignedBigInteger('conversation_id')->nullable();
                $table->string('tool_name', 100);
                $table->json('resolved_args');
                $table->text('summary');
                $table->string('status', 20)->default('pending');
                $table->json('result')->nullable();
                $table->string('error', 255)->nullable();
                $table->timestamp('expires_at');
                $table->timestamp('confirmed_at')->nullable();
                $table->timestamps();
            });
        }

        // See database/sql/chunk38.sql (Phase 13 human handoff) for the
        // real (MySQL) definition — the ENUM there becomes a plain string
        // column here, same simplification every other schema trait in
        // this suite makes.
        if (! Schema::hasTable('ai_handoffs')) {
            Schema::create('ai_handoffs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->string('channel', 20);
                $table->string('external_id', 100);
                $table->string('reason', 50);
                $table->unsignedBigInteger('triggered_by_message_id')->nullable();
                $table->timestamp('resolved_at')->nullable();
                $table->unsignedBigInteger('resolved_by_user_id')->nullable();
                $table->timestamp('created_at')->nullable();
            });
        }

        // "Teach Your AI Agent" — see database/sql/chunk41.sql for the
        // real (MySQL) definition.
        if (! Schema::hasTable('tenant_ai_memories')) {
            Schema::create('tenant_ai_memories', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->string('question', 500);
                $table->text('answer');
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

    /** Independent of enableAiAgent() — see MessengerWebhookController::maybeDispatchAiAgent()'s docblock. */
    protected function enableMessengerAutoReply(int $tenantId): void
    {
        DB::table('store_settings')->updateOrInsert(
            ['tenant_id' => $tenantId, 'key' => 'messenger_ai_auto_reply_enabled'],
            ['value' => '1', 'created_at' => now(), 'updated_at' => now()]
        );
    }

    /** Both toggles at once — the common case for tests exercising the full dispatch/job pipeline rather than the toggles themselves. */
    protected function enableAiAgentAndMessengerAutoReply(int $tenantId): void
    {
        $this->enableAiAgent($tenantId);
        $this->enableMessengerAutoReply($tenantId);
    }

    protected function makeSuperAdmin(): SuperAdmin
    {
        return SuperAdmin::create([
            'name' => 'Admin',
            'email' => 'admin-'.Str::random(10).'@example.com',
            'password' => bcrypt('password'),
        ]);
    }

    /** Directly seeds an ai_credit_accounts row — bypasses AiCreditService for tests that just need a starting balance, not the allocation flow itself. */
    protected function allocateAiCredit(int $tenantId, float $balance): void
    {
        DB::table('ai_credit_accounts')->updateOrInsert(
            ['tenant_id' => $tenantId],
            ['balance' => $balance, 'created_at' => now(), 'updated_at' => now()]
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
