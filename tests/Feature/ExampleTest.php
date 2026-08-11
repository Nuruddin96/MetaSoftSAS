<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * GET / (LandingController::index()) is the central marketing homepage
     * — its only database dependency is Plan::where('is_active', 1)->get()
     * (verified: neither central/landing.blade.php nor layouts/central.blade.php
     * issue any other query). This project's real schema lives in raw SQL
     * under database/sql/ (see CLAUDE.md — "NOT migration-driven"), not
     * database/migrations/, so the stock RefreshDatabase trait would have
     * nothing to run against; a hand-built minimal table is the same
     * pattern every other feature test in this suite already uses (e.g.
     * tests/Feature/Order/DeliveryChargeServiceTest.php's own inline
     * setUp()). No rows are seeded — an empty plans table is a perfectly
     * valid state for this smoke test: the page must render 200 with zero
     * plans configured, same as any other empty-state page in this app.
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('plans')) {
            Schema::create('plans', function (Blueprint $table) {
                $table->id();
                $table->string('name', 100);
                $table->string('slug', 100)->unique();
                $table->decimal('price_monthly', 10, 2)->default(0);
                $table->decimal('price_yearly', 10, 2)->default(0);
                $table->integer('max_products')->nullable();
                $table->integer('max_staff')->nullable();
                $table->integer('max_warehouses')->nullable();
                $table->integer('max_orders_per_month')->nullable();
                $table->boolean('allow_custom_domain')->default(false);
                $table->boolean('allow_pos')->default(true);
                $table->boolean('allow_courier_api')->default(true);
                $table->boolean('allow_meta_ads')->default(true);
                $table->json('features')->nullable();
                $table->boolean('is_active')->default(true);
                $table->integer('sort_order')->default(0);
                $table->timestamps();
            });
        }
    }

    /**
     * A basic test example.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
    }
}
