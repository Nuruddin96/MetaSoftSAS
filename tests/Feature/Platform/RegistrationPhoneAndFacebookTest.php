<?php

namespace Tests\Feature\Platform;

use App\Http\Controllers\BrandOwner\RegisterController;
use App\Models\Brand;
use App\Models\BrandCategory;
use App\Models\BrandChangeRequest;
use App\Models\BrandOwner;
use App\Rules\FacebookPageUrl;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\InteractsWithPlatformSchema;
use Tests\TestCase;

/**
 * One mobile number = one business profile (normalized before every check,
 * backed by the brands.phone unique key from chunk67.sql), and the
 * mandatory Facebook Page link on new profiles — a format check only, never
 * treated as proof of page ownership or as verification.
 */
class RegistrationPhoneAndFacebookTest extends TestCase
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

    private function register(array $overrides = []): TestResponse
    {
        $category = BrandCategory::first() ?? $this->makePlatformCategory();

        return $this->post(route('owner.register.store'), array_merge([
            'brand_name' => 'Nakshi Ghor',
            'logo' => UploadedFile::fake()->image('logo.png', 200, 200),
            'brand_category_id' => $category->id,
            'founder_name' => 'Rina Akter',
            'phone' => '01712345678',
            'email' => 'rina@example.com',
            'division' => 'Dhaka',
            'district' => 'Gazipur',
            'facebook' => 'https://www.facebook.com/nakshighor',
            'description' => 'Handloom sarees from Gazipur.',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
            'terms' => '1',
        ], $overrides));
    }

    /** A brand added by Super Admin, with no owner account (the case brand_owners.phone alone never caught). */
    private function adminBrand(string $phone, array $attrs = []): Brand
    {
        $category = BrandCategory::first() ?? $this->makePlatformCategory();

        return Brand::create(array_merge([
            'name' => 'Existing '.$phone, 'brand_category_id' => $category->id, 'founder_name' => 'Someone', 'phone' => $phone,
            'email' => $phone.'@example.com', 'division' => 'Dhaka', 'district' => 'Dhaka', 'status' => 'approved',
        ], $attrs));
    }

    // --- Facebook Page URL format ---------------------------------------------------------

    public function test_facebook_page_url_rule_accepts_page_links_and_rejects_everything_else(): void
    {
        foreach ([
            'https://www.facebook.com/nakshighor', 'https://facebook.com/nakshi.ghor', 'http://m.facebook.com/nakshighor/',
            'https://web.facebook.com/Nakshi-Ghor-123', 'https://www.facebook.com/profile.php?id=100089123456789',
            'https://www.facebook.com/p/Nakshi-Ghor-100089123456789/', 'https://www.facebook.com/pages/Nakshi-Ghor/123456789',
            'https://fb.com/nakshighor', 'https://www.facebook.com/nakshighor?mibextid=ZbWKwL',
        ] as $ok) {
            $this->assertTrue(FacebookPageUrl::passes($ok), "Should accept: $ok");
        }

        foreach ([
            null, '', 'nakshighor', 'https://www.facebook.com', 'https://www.facebook.com/', 'https://www.facebook.com/sharer/sharer.php?u=x',
            'https://www.facebook.com/groups/123', 'https://www.facebook.com/watch/?v=1', 'https://www.facebook.com/login.php',
            'https://www.facebook.com/profile.php', 'https://www.facebook.com/profile.php?id=abc', 'https://facebook.com.evil.example/nakshighor',
            'https://evil.example/facebook.com/nakshighor', 'https://instagram.com/nakshighor', 'javascript:alert(1)', 'ftp://facebook.com/x',
            'https://user@facebook.com/nakshighor', 'https://www.facebook.com/'.str_repeat('a', 260),
        ] as $bad) {
            $this->assertFalse(FacebookPageUrl::passes($bad), 'Should reject: '.var_export($bad, true));
        }

        $this->assertSame('https://facebook.com/nakshighor', FacebookPageUrl::normalize('  facebook.com/nakshighor '));
        $this->assertSame('https://www.facebook.com/x', FacebookPageUrl::normalize('www.facebook.com/x'));
        $this->assertSame('nakshighor', FacebookPageUrl::normalize('nakshighor'), 'Only facebook links get a scheme added.');
        $this->assertNull(FacebookPageUrl::normalize('   '));
    }

    public function test_registration_requires_a_valid_facebook_page_link_and_stores_it(): void
    {
        $this->register(['facebook' => ''])->assertSessionHasErrors(['facebook' => 'Add your business’s Facebook Page link.']);
        foreach (['https://example.com/nakshighor', 'https://www.facebook.com/', 'https://www.facebook.com/groups/1', 'not a url'] as $bad) {
            $this->register(['facebook' => $bad])->assertSessionHasErrors(['facebook' => FacebookPageUrl::MESSAGE]);
        }
        $this->assertSame(0, BrandOwner::count());
        $this->assertSame(0, Brand::count());
        $this->assertSame([], Storage::disk('public')->allFiles(), 'Rejected attempts leave no uploaded logo behind.');

        $this->register([
            'facebook' => 'facebook.com/nakshighor',
            'website' => 'https://nakshighor.example',
            'instagram' => 'https://instagram.com/nakshighor',
        ])->assertRedirect(route('owner.dashboard'));

        $brand = Brand::firstOrFail();
        $this->assertSame('https://facebook.com/nakshighor', $brand->facebook, 'A pasted link without https:// is stored complete.');
        $this->assertSame('https://instagram.com/nakshighor', $brand->instagram);
        $this->assertSame('https://nakshighor.example', $brand->website);
        $this->assertSame('Handloom sarees from Gazipur.', $brand->description);
        $this->assertSame(['pending', false], [$brand->status, $brand->is_verified], 'A Facebook link never verifies or publishes a brand.');
    }

    public function test_website_and_instagram_stay_optional_but_must_be_urls(): void
    {
        $this->register(['website' => 'not-a-url', 'instagram' => 'also not'])->assertSessionHasErrors(['website', 'instagram']);
        $this->register(['website' => '', 'instagram' => ''])->assertRedirect(route('owner.dashboard'));
        $this->assertNull(Brand::firstOrFail()->instagram);
    }

    public function test_registration_form_marks_facebook_required_with_a_browser_check_and_honest_note(): void
    {
        $this->makePlatformCategory();
        $html = $this->get(route('owner.register'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('~<input type="text" name="facebook"[^>]*\brequired\b~', $html);
        $this->assertStringContainsString('pattern="'.e(FacebookPageUrl::HTML_PATTERN).'"', $html);
        $this->assertStringContainsString('Adding a link doesn’t prove you run the page', $html);
        $this->assertStringContainsString('name="instagram"', $html);
        $this->assertDoesNotMatchRegularExpression('~name="(website|instagram)"[^>]*\brequired\b~', $html);
    }

    // --- one mobile number per business profile --------------------------------------------

    public function test_a_number_already_on_a_business_profile_cannot_register_again_in_any_format(): void
    {
        $this->adminBrand('01712345678');

        foreach (['01712345678', '+880 1712-345678', '8801712345678', '1712345678'] as $format) {
            $this->register(['phone' => $format, 'email' => 'new'.md5($format).'@example.com', 'brand_name' => 'New '.md5($format)])
                ->assertSessionHasErrors(['account' => RegisterController::ACCOUNT_UNAVAILABLE]);
        }

        $this->assertSame(0, BrandOwner::count());
        $this->assertSame(1, Brand::count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_a_deleted_profiles_number_stays_reserved(): void
    {
        $this->adminBrand('01712345678')->delete();

        $this->register()->assertSessionHasErrors(['account' => RegisterController::ACCOUNT_UNAVAILABLE]);
        $this->assertSame(1, Brand::withTrashed()->count());
    }

    public function test_concurrent_registration_with_the_same_number_is_stopped_by_the_unique_key(): void
    {
        $category = $this->makePlatformCategory();
        // A parallel request commits a profile with the same number after this request's pre-check passed.
        $raced = false;
        Brand::creating(function (Brand $brand) use (&$raced, $category) {
            if (! $raced) {
                $raced = true;
                DB::table('brands')->insert(['name' => 'Parallel', 'name_key' => 'parallel', 'brand_category_id' => $category->id, 'founder_name' => 'P',
                    'phone' => $brand->phone, 'email' => 'p@example.com', 'division' => 'Dhaka', 'district' => 'Dhaka', 'status' => 'pending']);
            }
        });

        $this->register()->assertSessionHasErrors(['account' => RegisterController::ACCOUNT_UNAVAILABLE]);

        $this->assertTrue($raced);
        $this->assertSame(0, BrandOwner::count(), 'The owner insert is rolled back with the failed brand insert.');
        // The simulated parallel row shares this request's transaction here, so it rolls back too; what matters is
        // that the unique key stopped the second profile and the request left nothing of its own behind.
        $this->assertSame(0, Brand::count());
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertGuest('brand_owner');
    }

    public function test_the_database_rejects_a_second_profile_with_the_same_number(): void
    {
        $this->adminBrand('01712345678');

        $this->expectException(UniqueConstraintViolationException::class);
        $this->adminBrand('01712345678', ['name' => 'Second']);
    }

    public function test_super_admin_cannot_create_or_move_a_profile_onto_a_used_number(): void
    {
        $category = $this->makePlatformCategory();
        $taken = $this->adminBrand('01712345678');
        $other = $this->adminBrand('01812345678');
        $this->actingAs($this->makePlatformAdmin(), 'super_admin');
        $form = ['name' => 'Krishi Bari', 'brand_category_id' => $category->id, 'founder_name' => 'Karim', 'email' => 'k@example.com',
            'division' => 'Dhaka', 'district' => 'Dhaka', 'status' => 'approved', 'facebook' => 'https://www.facebook.com/krishibari'];

        $this->post(route('super.brands.store'), $form + ['phone' => '+8801712345678'])
            ->assertSessionHasErrors(['phone' => 'Another business profile already uses this mobile number.']);
        $this->post(route('super.brands.store'), array_merge($form, ['facebook' => '']) + ['phone' => '01912345678'])->assertSessionHasErrors('facebook');
        $this->assertSame(2, Brand::count());

        // Editing keeps its own number; moving onto another profile's number is refused.
        $edit = ['name' => $other->name, 'brand_category_id' => $category->id, 'founder_name' => 'Someone', 'email' => $other->email, 'division' => 'Dhaka', 'district' => 'Dhaka'];
        $this->put(route('super.brands.update', $other), $edit + ['phone' => '01812345678'])->assertSessionHasNoErrors();
        $this->put(route('super.brands.update', $other), $edit + ['phone' => '01712345678'])->assertSessionHasErrors('phone');
        $this->assertSame('01812345678', $other->fresh()->phone);
        $this->assertSame('01712345678', $taken->fresh()->phone);
    }

    public function test_owner_cannot_take_another_profiles_number_and_approval_rechecks_it(): void
    {
        $this->register()->assertRedirect();
        $brand = Brand::firstOrFail();
        $brand->update(['status' => 'approved', 'is_verified' => true]);
        $other = $this->adminBrand('01812345678');
        $form = ['name' => $brand->name, 'brand_category_id' => $brand->brand_category_id, 'founder_name' => $brand->founder_name, 'email' => $brand->email,
            'division' => 'Dhaka', 'district' => 'Gazipur', 'facebook' => $brand->facebook];

        $this->put(route('owner.brand.update'), $form + ['phone' => '01812345678'])
            ->assertSessionHasErrors(['phone' => 'Another business profile already uses this mobile number.']);

        // A change request made while the number was free cannot be approved after another profile took it.
        $change = BrandChangeRequest::create(['brand_id' => $brand->id, 'status' => 'pending', 'changes' => ['phone' => '01912345678'], 'original' => ['phone' => $brand->phone]]);
        $this->adminBrand('01912345678');
        $this->actingAs($this->makePlatformAdmin(), 'super_admin')
            ->post(route('super.brands.changes.approve', $change))->assertSessionHas('error');
        $this->assertSame('01712345678', $brand->fresh()->phone);
        $this->assertSame('pending', $change->fresh()->status);
        $this->assertSame('01812345678', $other->fresh()->phone);
    }

    public function test_owner_can_change_but_not_remove_the_facebook_link_and_older_profiles_without_one_still_save(): void
    {
        $this->register()->assertRedirect();
        $brand = Brand::firstOrFail();
        $form = ['name' => $brand->name, 'brand_category_id' => $brand->brand_category_id, 'founder_name' => $brand->founder_name, 'phone' => $brand->phone,
            'email' => $brand->email, 'division' => 'Dhaka', 'district' => 'Gazipur'];

        $this->put(route('owner.brand.update'), $form + ['facebook' => ''])->assertSessionHasErrors('facebook');
        $this->put(route('owner.brand.update'), $form + ['facebook' => 'https://example.com/x'])->assertSessionHasErrors('facebook');
        $this->put(route('owner.brand.update'), $form + ['facebook' => 'https://www.facebook.com/nakshighor.bd'])->assertSessionHasNoErrors();
        $this->assertSame('https://www.facebook.com/nakshighor.bd', $brand->fresh()->facebook);

        // A profile created before the rule (no Facebook link) can still save other edits.
        $brand->update(['facebook' => null]);
        $this->app['auth']->forgetGuards();
        $this->put(route('owner.brand.update'), $form + ['facebook' => '', 'description' => 'Updated'])->assertSessionHasNoErrors();
        $this->assertSame('Updated', $brand->fresh()->description);
    }
}
