<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `product_images` already exists in production via database/sql/schema.sql
 * (imported directly, not through Laravel migrations) but was created
 * without created_at/updated_at columns, even though the ProductImage
 * model expects Eloquent's default timestamps. This migration is written
 * defensively so it is safe to run both against that existing table
 * (adds only the missing columns) and against a fresh environment that
 * has never imported the SQL schema (creates the table outright, e.g. CI
 * or a fresh test database).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('product_images')) {
            Schema::create('product_images', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->foreignId('product_id')->constrained()->cascadeOnDelete();
                $table->string('image_path');
                $table->integer('sort_order')->default(0);
                $table->timestamps();
            });

            return;
        }

        Schema::table('product_images', function (Blueprint $table) {
            if (! Schema::hasColumn('product_images', 'created_at')) {
                $table->timestamp('created_at')->nullable();
            }
            if (! Schema::hasColumn('product_images', 'updated_at')) {
                $table->timestamp('updated_at')->nullable();
            }
        });
    }

    /**
     * Deliberately does not drop the product_images table itself — in
     * every real environment this table pre-dates this migration and
     * holds live data, so rolling back only removes what this migration
     * added.
     */
    public function down(): void
    {
        if (! Schema::hasTable('product_images')) {
            return;
        }

        Schema::table('product_images', function (Blueprint $table) {
            if (Schema::hasColumn('product_images', 'created_at')) {
                $table->dropColumn('created_at');
            }
            if (Schema::hasColumn('product_images', 'updated_at')) {
                $table->dropColumn('updated_at');
            }
        });
    }
};
