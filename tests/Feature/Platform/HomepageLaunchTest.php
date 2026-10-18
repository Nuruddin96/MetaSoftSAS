<?php

namespace Tests\Feature\Platform;

use App\Models\Brand;
use App\Models\BrandCategory;
use App\Models\BrandOwner;
use App\Models\PlatformAuditLog;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithPlatformSchema;
use Tests\TestCase;

/**
 * Marketing launch: the free-registration benefits on the homepage, the
 * phone action bar, and the Blue Verified badge rule behind the benefit
 * copy: a new registration is pending, unpublished and unverified, and the
 * badge appears only after an admin approves and then verifies the brand.
 */
class HomepageLaunchTest extends TestCase
{
    use InteractsWithPlatformSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPlatformSchema();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->withoutVite();
        Storage::fake('public');
    }

    public function test_benefits_are_listed_with_honest_qualifiers_and_no_unsupported_promises(): void
    {
        $html = $this->get('/')->assertOk()->getContent();
        $join = Str::between($html, 'id="join"', '</section>');

        foreach (['Your business profile', 'A link you can share', 'Findable on Google', 'More visibility', 'Meet other businesses', 'Free award entry', 'Blue Verified badge, after review'] as $title) {
            $this->assertStringContainsString($title, $join);
        }
        $this->assertStringContainsString('Google decides if and where they appear.', $join);
        $this->assertStringContainsString('It is never shown before that review.', $join);
        $this->assertStringContainsString('We don’t promise search rankings, customers or sales.', $join);
        // Features the platform doesn't have are not advertised.
        foreach (['certificate', 'Certificate', 'guarantee', 'Customers across Bangladesh', 'message other'] as $missing) {
            $this->assertStringNotContainsString($missing, $join);
        }
    }

    public function test_every_registration_call_to_action_uses_the_existing_registration_route(): void
    {
        $html = $this->get('/')->assertOk()->getContent();
        $register = route('owner.register');

        $this->assertStringContainsString('href="'.$register.'"', Str::between($html, '<h1', 'Quick actions'), 'Hero button');
        $this->assertStringContainsString('href="'.$register.'"', Str::between($html, 'id="join"', '</section>'), 'Join section');
        $this->assertStringContainsString('href="'.$register.'"', $this->phoneBar(), 'Phone bar');
    }

    public function test_phone_bar_whatsapp_button_uses_the_configured_support_number_only(): void
    {
        config(['payment.support_whatsapp' => '+880 1900-000000']);
        $bar = $this->phoneBar();
        $this->assertStringContainsString('Create Free Profile', $bar);
        $this->assertStringContainsString('href="https://wa.me/8801900000000?text=', $bar);
        $this->assertStringContainsString('WhatsApp', strip_tags($bar));

        // No configured number: no guessed link, only the profile button.
        config(['payment.support_whatsapp' => '']);
        $bar = $this->phoneBar();
        $this->assertStringContainsString('Create Free Profile', $bar);
        $this->assertStringNotContainsString('wa.me', $bar);
    }

    public function test_badge_needs_admin_approval_then_admin_verification(): void
    {
        $category = BrandCategory::first() ?? $this->makePlatformCategory();
        $this->post(route('owner.register.store'), [
            'brand_name' => 'Launch Check Crafts',
            'logo' => UploadedFile::fake()->image('logo.png', 200, 200),
            'brand_category_id' => $category->id,
            'founder_name' => 'Test Founder',
            'phone' => '01712345670',
            'email' => 'founder@example.com',
            'division' => 'Dhaka',
            'district' => 'Gazipur',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
            'terms' => '1',
        ])->assertRedirect(route('owner.dashboard'));

        $this->assertSame(1, BrandOwner::count());
        $this->assertSame(1, Brand::count());
        $brand = Brand::firstOrFail();
        $this->assertSame('pending', $brand->status);
        $this->assertFalse($brand->is_verified);

        // Pending: nowhere public, and it cannot be verified yet.
        $this->get('/')->assertOk()->assertDontSee('Launch Check Crafts');
        Auth::guard('brand_owner')->logout();
        $admin = $this->makePlatformAdmin();
        $this->actingAs($admin, 'super_admin')->post(route('super.brands.verification', $brand), ['verified' => 1, 'evidence' => ['contact']]);
        $this->assertFalse($brand->fresh()->is_verified, 'Only approved brands can be verified.');

        // Approved: public, still without the badge.
        $this->post(route('super.brands.status', $brand), ['action' => 'approve'])->assertRedirect();
        $brand->refresh();
        $this->assertSame('approved', $brand->status);
        $home = $this->get('/')->assertOk()->getContent();
        $grid = Str::between($home, 'id="ourBrandsTitle"', 'View All Brands');
        $this->assertStringContainsString('Launch Check Crafts', $grid);
        $this->assertSame(0, substr_count($grid, 'images/badges/metasoft-verified.png'));
        $this->get(route('brands.show', $brand->slug))->assertOk()->assertDontSee('MetaSoft BD Verified');

        // Verified by an admin with evidence: badge on the card and the profile, and audited.
        $this->post(route('super.brands.verification', $brand), ['verified' => 1, 'evidence' => ['contact']])->assertRedirect();
        $this->assertTrue($brand->fresh()->is_verified);
        $this->assertTrue(PlatformAuditLog::where('action', 'brand.verified')->exists());
        $grid = Str::between($this->get('/')->getContent(), 'id="ourBrandsTitle"', 'View All Brands');
        $this->assertSame(1, substr_count($grid, 'images/badges/metasoft-verified.png'));
        $this->get(route('brands.show', $brand->slug))->assertOk()->assertSee('MetaSoft BD Verified');

        // The owner can log back in and reach the dashboard.
        Auth::guard('super_admin')->logout();
        $this->post(route('owner.login.attempt'), ['login' => 'founder@example.com', 'password' => 'secret123'])->assertRedirect(route('owner.dashboard'));
        $this->get(route('owner.dashboard'))->assertOk()->assertSee('Launch Check Crafts');
    }

    /** The fixed phone action bar's markup (header.blade.php, [data-mobile-cta]). */
    private function phoneBar(): string
    {
        preg_match('~data-mobile-cta.*?</div>\s*</div>~s', $this->get('/')->assertOk()->getContent(), $m);

        return $m[0] ?? '';
    }
}
