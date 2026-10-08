<?php

namespace Tests\Concerns;

use App\Models\BrandCategory;
use App\Models\PlatformSetting;
use App\Models\SuperAdmin;
use App\Support\Platform\BdLocations;
use App\Support\Platform\PlatformSchema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * SQLite mirror of database/sql/chunk64.sql (recognition platform) plus
 * the bd_divisions/bd_districts reference tables and super_admins it
 * relies on. ENUMs become strings; unique keys match the SQL file so the
 * duplicate-vote / duplicate-nomination guarantees are exercised for real.
 */
trait InteractsWithPlatformSchema
{
    protected function setUpPlatformSchema(): void
    {
        if (! Schema::hasTable('super_admins')) {
            Schema::create('super_admins', function (Blueprint $t) {
                $t->id();
                $t->string('name', 150);
                $t->string('email', 150)->unique();
                $t->string('password');
                $t->rememberToken();
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('bd_divisions')) {
            Schema::create('bd_divisions', function (Blueprint $t) {
                $t->increments('id');
                $t->string('name', 50);
                $t->string('bn_name', 50);
            });
            Schema::create('bd_districts', function (Blueprint $t) {
                $t->increments('id');
                $t->unsignedInteger('division_id');
                $t->string('name', 50);
                $t->string('bn_name', 50);
            });
            foreach (['Dhaka' => ['Dhaka', 'Gazipur'], 'Chattogram' => ['Chattogram', "Cox's Bazar"]] as $division => $districts) {
                $id = DB::table('bd_divisions')->insertGetId(['name' => $division, 'bn_name' => $division]);
                foreach ($districts as $d) {
                    DB::table('bd_districts')->insert(['division_id' => $id, 'name' => $d, 'bn_name' => $d]);
                }
            }
        }

        Schema::create('brand_categories', function (Blueprint $t) {
            $t->id();
            $t->string('name', 100);
            $t->string('bn_name', 100)->nullable();
            $t->string('slug', 120)->unique();
            $t->string('icon', 30)->nullable();
            $t->string('color', 7)->nullable();
            $t->string('image_path')->nullable();
            $t->integer('sort_order')->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('brand_owners', function (Blueprint $t) {
            $t->id();
            $t->string('name', 150);
            $t->string('email', 150)->unique();
            $t->string('phone', 20)->unique();
            $t->string('password');
            $t->rememberToken();
            $t->string('status')->default('active');
            $t->timestamp('last_login_at')->nullable();
            $t->timestamps();
        });

        Schema::create('brands', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('brand_owner_id')->nullable();
            $t->string('name', 150);
            $t->string('name_key', 150)->index();
            $t->string('slug', 160)->nullable()->unique();
            $t->unsignedBigInteger('brand_category_id')->nullable();
            $t->string('sub_category', 100)->nullable();
            $t->string('founder_name', 150);
            $t->string('phone', 20);
            $t->string('email', 150);
            $t->string('district', 50);
            $t->string('division', 50);
            $t->string('logo_path')->nullable();
            $t->text('description')->nullable();
            $t->text('products_info')->nullable();
            foreach (['website', 'facebook', 'instagram', 'tiktok', 'youtube'] as $c) {
                $t->string($c)->nullable();
            }
            $t->json('gallery')->nullable();
            $t->unsignedSmallInteger('founded_year')->nullable();
            $t->string('status')->default('pending');
            $t->string('status_reason', 500)->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->boolean('is_verified')->default(false);
            $t->timestamp('verified_at')->nullable();
            $t->boolean('is_featured')->default(false);
            $t->integer('featured_order')->default(0);
            $t->boolean('is_sponsored')->default(false);
            $t->date('sponsored_until')->nullable();
            $t->unsignedInteger('views_count')->default(0);
            $t->softDeletes();
            $t->timestamps();
        });

        Schema::create('brand_change_requests', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('brand_id');
            $t->json('changes');
            $t->json('original')->nullable();
            $t->string('status')->default('pending');
            $t->string('review_note', 500)->nullable();
            $t->unsignedBigInteger('reviewed_by')->nullable();
            $t->timestamp('reviewed_at')->nullable();
            $t->timestamps();
        });

        Schema::create('platform_notifications', function (Blueprint $t) {
            $t->id();
            $t->string('recipient_type', 20);
            $t->unsignedBigInteger('recipient_id')->nullable();
            $t->string('type', 50);
            $t->string('title', 200);
            $t->string('body', 1000)->nullable();
            $t->string('url')->nullable();
            $t->timestamp('read_at')->nullable();
            $t->timestamps();
        });

        Schema::create('platform_audit_logs', function (Blueprint $t) {
            $t->id();
            $t->string('actor_type', 20);
            $t->unsignedBigInteger('actor_id')->nullable();
            $t->string('actor_name', 150)->nullable();
            $t->string('action', 60);
            $t->string('subject_type', 40);
            $t->unsignedBigInteger('subject_id')->nullable();
            $t->json('changes')->nullable();
            $t->string('reason', 500)->nullable();
            $t->string('ip', 45)->nullable();
            $t->timestamp('created_at')->nullable();
        });

        Schema::create('awards', function (Blueprint $t) {
            $t->id();
            $t->string('title', 200);
            $t->string('bn_title', 200)->nullable();
            $t->string('slug', 220)->unique();
            $t->unsignedSmallInteger('year');
            $t->text('description')->nullable();
            $t->text('rules')->nullable();
            $t->text('jury_info')->nullable();
            $t->string('status')->default('draft');
            foreach (['nomination_starts_at', 'nomination_ends_at', 'voting_starts_at', 'voting_ends_at'] as $c) {
                $t->dateTime($c)->nullable();
            }
            $t->boolean('is_featured')->default(false);
            $t->timestamps();
        });

        Schema::create('award_categories', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('award_id');
            $t->string('name', 150);
            $t->string('description', 500)->nullable();
            $t->unsignedBigInteger('brand_category_id')->nullable();
            $t->integer('sort_order')->default(0);
            $t->timestamps();
        });

        Schema::create('award_nominations', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('award_id');
            $t->unsignedBigInteger('award_category_id');
            $t->unsignedBigInteger('brand_id');
            $t->string('source')->default('owner');
            $t->text('statement')->nullable();
            $t->string('status')->default('submitted');
            $t->string('admin_note', 500)->nullable();
            $t->timestamps();
            $t->unique(['award_category_id', 'brand_id']);
        });

        Schema::create('award_recognitions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('award_id');
            $t->unsignedBigInteger('award_category_id')->nullable();
            $t->unsignedBigInteger('brand_id');
            $t->string('type');
            $t->string('title', 200)->nullable();
            $t->timestamps();
        });

        Schema::create('vote_campaigns', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('award_id')->nullable();
            $t->string('title', 200);
            $t->string('slug', 220)->unique();
            $t->text('description')->nullable();
            $t->string('status')->default('draft');
            $t->dateTime('starts_at')->nullable();
            $t->dateTime('ends_at')->nullable();
            $t->string('vote_limit')->default('daily');
            $t->boolean('show_counts')->default(true);
            $t->timestamp('started_notified_at')->nullable();
            $t->timestamp('ended_notified_at')->nullable();
            $t->timestamps();
        });

        Schema::create('vote_categories', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('campaign_id');
            $t->unsignedBigInteger('award_category_id')->nullable();
            $t->string('name', 150);
            $t->integer('sort_order')->default(0);
            $t->timestamps();
        });

        Schema::create('vote_entries', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('campaign_id');
            $t->unsignedBigInteger('vote_category_id');
            $t->unsignedBigInteger('brand_id');
            $t->unsignedInteger('votes_count')->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['vote_category_id', 'brand_id']);
        });

        Schema::create('votes', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('campaign_id');
            $t->unsignedBigInteger('vote_category_id');
            $t->unsignedBigInteger('vote_entry_id');
            $t->char('voter_hash', 64);
            $t->string('phone_masked', 20);
            $t->string('period_key', 10);
            $t->string('ip', 45)->nullable();
            $t->char('device_hash', 64)->nullable();
            $t->string('user_agent')->nullable();
            $t->string('flags')->nullable();
            $t->string('status')->default('valid');
            $t->unsignedBigInteger('invalidated_by')->nullable();
            $t->timestamp('invalidated_at')->nullable();
            $t->string('invalid_reason')->nullable();
            $t->timestamp('created_at')->nullable();
            $t->unique(['vote_category_id', 'voter_hash', 'period_key']);
        });

        // database/sql/chunk65.sql
        Schema::create('platform_settings', function (Blueprint $t) {
            $t->string('key', 80)->primary();
            $t->json('value')->nullable();
            $t->timestamps();
        });

        PlatformSchema::flush();
        BdLocations::flush();
        PlatformSetting::flush();
    }

    protected function makePlatformCategory(string $name = 'Fashion & Apparel', array $attrs = []): BrandCategory
    {
        return BrandCategory::create(array_merge(['name' => $name, 'slug' => Str::slug($name), 'sort_order' => 1, 'is_active' => true], $attrs));
    }

    protected function makePlatformAdmin(): SuperAdmin
    {
        return SuperAdmin::create(['name' => 'Platform Admin', 'email' => 'admin-'.uniqid().'@example.com', 'password' => bcrypt('password')]);
    }
}
