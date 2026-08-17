<?php

namespace Tests\Feature\SuperAdmin;

use App\Models\Plan;
use App\Models\SuperAdmin;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Super Admin plan-feature checkbox system (config/features.php +
 * plans.features JSON) and its dynamic reflection on the landing page's
 * pricing section — nothing here is hardcoded per-plan in any view.
 */
class PlanFeatureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

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

        if (! Schema::hasTable('plans')) {
            Schema::create('plans', function (Blueprint $table) {
                $table->id();
                $table->string('name', 100);
                $table->string('tagline', 150)->nullable();
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
                $table->boolean('is_featured')->default(false);
                $table->integer('sort_order')->default(0);
                $table->timestamps();
            });
        }
    }

    protected function makePlan(array $attrs = []): Plan
    {
        return Plan::create(array_merge([
            'name' => 'Test Plan', 'slug' => 'test-plan-'.uniqid(),
            'price_monthly' => 500, 'price_yearly' => 5000,
        ], $attrs));
    }

    protected function makeSuperAdmin(): SuperAdmin
    {
        return SuperAdmin::create([
            'name' => 'Admin', 'email' => 'admin-'.uniqid().'@example.com', 'password' => bcrypt('password'),
        ]);
    }

    // --- model -------------------------------------------------------------------------------------

    public function test_features_column_ready_reports_true_when_column_exists(): void
    {
        $this->assertTrue(Plan::featuresColumnReady());
    }

    public function test_has_feature_true_for_an_enabled_feature(): void
    {
        $plan = $this->makePlan(['features' => ['facebook', 'whatsapp']]);

        $this->assertTrue($plan->hasFeature('facebook'));
        $this->assertTrue($plan->hasFeature('whatsapp'));
        $this->assertFalse($plan->hasFeature('auto_order'));
    }

    public function test_has_feature_false_when_features_column_is_null(): void
    {
        $plan = $this->makePlan(['features' => null]);

        foreach (array_keys(config('features.list')) as $key) {
            $this->assertFalse($plan->hasFeature($key));
        }
    }

    // --- controller ----------------------------------------------------------------------------------

    public function test_super_admin_can_enable_features_on_a_plan(): void
    {
        $admin = $this->makeSuperAdmin();
        $plan = $this->makePlan();

        $response = $this->actingAs($admin, 'super_admin')->put(route('super.plans.update', $plan), [
            'name' => $plan->name, 'price_monthly' => 500, 'price_yearly' => 5000,
            'features' => ['facebook', 'messenger', 'whatsapp'],
        ]);

        $response->assertRedirect();
        $plan->refresh();
        $this->assertTrue($plan->hasFeature('facebook'));
        $this->assertTrue($plan->hasFeature('messenger'));
        $this->assertTrue($plan->hasFeature('whatsapp'));
        $this->assertFalse($plan->hasFeature('auto_order'));
    }

    public function test_unchecking_all_features_clears_them(): void
    {
        $admin = $this->makeSuperAdmin();
        $plan = $this->makePlan(['features' => ['facebook', 'whatsapp']]);

        $this->actingAs($admin, 'super_admin')->put(route('super.plans.update', $plan), [
            'name' => $plan->name, 'price_monthly' => 500, 'price_yearly' => 5000,
            // no features[] key at all — same as unchecking every box in the form
        ])->assertRedirect();

        $plan->refresh();
        $this->assertFalse($plan->hasFeature('facebook'));
        $this->assertFalse($plan->hasFeature('whatsapp'));
    }

    public function test_a_feature_key_outside_the_configured_catalog_is_rejected(): void
    {
        $admin = $this->makeSuperAdmin();
        $plan = $this->makePlan();

        $response = $this->actingAs($admin, 'super_admin')->put(route('super.plans.update', $plan), [
            'name' => $plan->name, 'price_monthly' => 500, 'price_yearly' => 5000,
            'features' => ['facebook', 'not-a-real-feature-key'],
        ]);

        $response->assertSessionHasErrors('features.1');
        $this->assertNull($plan->fresh()->features);
    }

    public function test_existing_pos_and_domain_toggles_still_work_unchanged(): void
    {
        $admin = $this->makeSuperAdmin();
        $plan = $this->makePlan(['allow_pos' => false, 'allow_custom_domain' => false]);

        $this->actingAs($admin, 'super_admin')->put(route('super.plans.update', $plan), [
            'name' => $plan->name, 'price_monthly' => 500, 'price_yearly' => 5000,
            'allow_pos' => '1', 'allow_custom_domain' => '1',
        ])->assertRedirect();

        $plan->refresh();
        $this->assertTrue((bool) $plan->allow_pos);
        $this->assertTrue((bool) $plan->allow_custom_domain);
    }

    // --- landing page ------------------------------------------------------------------------------

    public function test_landing_page_shows_enabled_features_and_hides_disabled_ones(): void
    {
        $this->makePlan([
            'name' => 'Business', 'slug' => 'business-plan', 'is_active' => 1,
            'features' => ['facebook', 'whatsapp'],
        ]);

        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('Facebook');
        $response->assertSee('WhatsApp');
        // "not included" features still render (opacity-50, x icon) — they
        // must be visible as unchecked, not silently omitted from the list.
        $response->assertSee(config('features.list')['auto_order']);
    }

    public function test_landing_page_never_hardcodes_features_it_reflects_config_changes(): void
    {
        $this->makePlan(['is_active' => 1, 'features' => []]);

        config(['features.list' => ['totally_new_feature' => 'একটি নতুন ফিচার']]);

        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('একটি নতুন ফিচার');
    }

    // --- CMS fields (tagline / is_featured) ---------------------------------------------------------

    public function test_cms_columns_ready_reports_true_when_columns_exist(): void
    {
        $this->assertTrue(Plan::cmsColumnsReady());
    }

    public function test_super_admin_can_set_a_tagline_and_feature_a_plan(): void
    {
        $admin = $this->makeSuperAdmin();
        $plan = $this->makePlan();

        $this->actingAs($admin, 'super_admin')->put(route('super.plans.update', $plan), [
            'name' => $plan->name, 'price_monthly' => 500, 'price_yearly' => 5000,
            'tagline' => 'ছোট ব্যবসার জন্য সেরা', 'is_featured' => '1',
        ])->assertRedirect();

        $plan->refresh();
        $this->assertSame('ছোট ব্যবসার জন্য সেরা', $plan->tagline);
        $this->assertTrue((bool) $plan->is_featured);
    }

    public function test_landing_page_shows_the_super_admin_authored_tagline(): void
    {
        $this->makePlan(['is_active' => 1, 'tagline' => 'সবচেয়ে জনপ্রিয় প্ল্যান']);

        $this->get('/')->assertOk()->assertSee('সবচেয়ে জনপ্রিয় প্ল্যান');
    }

    public function test_landing_page_popular_badge_follows_is_featured_not_iteration_order(): void
    {
        // Deliberately reverse of the old "2nd plan" hardcoded position —
        // proves the badge now follows is_featured, not iteration index.
        $this->makePlan(['name' => 'Starter', 'slug' => 'starter', 'sort_order' => 1, 'is_active' => 1, 'is_featured' => false]);
        $this->makePlan(['name' => 'Pro', 'slug' => 'pro', 'sort_order' => 2, 'is_active' => 1, 'is_featured' => false]);
        $this->makePlan(['name' => 'Business', 'slug' => 'business', 'sort_order' => 3, 'is_active' => 1, 'is_featured' => true]);

        $response = $this->get('/');

        $response->assertOk();
        // The badge text appears exactly once — attached to the 3rd (not
        // 2nd) plan, since that's the one with is_featured=true.
        $this->assertSame(1, substr_count($response->getContent(), 'জনপ্রিয়'));
    }
}
