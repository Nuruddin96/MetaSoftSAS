<?php

namespace Tests\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds personal_access_tokens on top of InteractsWithCommerceSchema's
 * existing tables (tenants/users/orders/customers/etc.) — this codebase's
 * feature tests build schema via Schema::create rather than running
 * database/migrations (see InteractsWithCommerceSchema's own docblock for
 * why), so Sanctum's migration doesn't get picked up automatically either.
 */
trait InteractsWithApiSchema
{
    use InteractsWithCommerceSchema;

    protected function setUpApiSchema(): void
    {
        $this->setUpCommerceSchema();

        // InteractsWithCommerceSchema's hand-built `orders`/`due_ledger`
        // tables predate columns the real (chunk-patched) schema has —
        // `orders.order_date` (schema.sql) and `due_ledger.updated_at`
        // (inferred: App\Models\DueLedger doesn't disable $timestamps, so
        // the real table must have it or every due-ledger write in
        // production would already fail the same way). Added here rather
        // than editing that shared trait, to keep this fix scoped to the
        // mobile API test suite.
        if (! Schema::hasColumn('orders', 'order_date')) {
            Schema::table('orders', fn (Blueprint $table) => $table->date('order_date')->nullable());
        }

        if (! Schema::hasColumn('due_ledger', 'updated_at')) {
            Schema::table('due_ledger', fn (Blueprint $table) => $table->timestamp('updated_at')->nullable());
        }

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
    }
}
