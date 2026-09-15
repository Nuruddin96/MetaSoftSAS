<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    /**
     * Mobile login (Api\Mobile\AuthController::login) now looks up users by
     * email alone, before any tenant is known — the existing
     * uq_tenant_email(tenant_id, email) unique key can't serve that lookup
     * since email is its second column, not its leftmost one (MySQL's
     * leftmost-prefix rule), so every login would otherwise full-scan
     * `users`.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->index('email', 'idx_users_email');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('idx_users_email');
        });
    }
};
