<?php

namespace Tests\Feature\Platform;

use App\Models\Brand;
use App\Models\BrandCategory;
use App\Models\BrandChangeRequest;
use App\Models\BrandOwner;
use App\Models\PlatformAuditLog;
use App\Models\PlatformSetting;
use App\Support\Home\HomeMedia;
use App\Support\Platform\BusinessCategories;
use App\Support\Platform\HomepageContent;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\Concerns\InteractsWithPlatformSchema;
use Tests\TestCase;

/**
 * Homepage material that arrives later (certificate, recipient portraits,
 * extra programme photos) appears only when it is a real, readable image;
 * retired categories keep working and are only mapped where the
 * equivalent is unambiguous; the removed homepage switches keep their data.
 */
class HomeAssetsAndLegacyCategoriesTest extends TestCase
{
    use InteractsWithPlatformSchema;

    /** Files and folders this test creates under public/, removed afterwards. */
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPlatformSchema();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->withoutVite();
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->created) as $path) {
            is_dir($path) ? File::deleteDirectory($path) : File::delete($path);
        }
        parent::tearDown();
    }

    private function jpeg(string $relative, int $w = 60, int $h = 80): void
    {
        $path = public_path($relative);
        if (! is_dir(dirname($path))) {
            File::makeDirectory(dirname($path), 0755, true);
            $this->created[] = dirname($path);
        }
        $img = imagecreatetruecolor($w, $h);
        imagejpeg($img, $path);
        $this->created[] = $path;
    }

    private function raw(string $relative, string $contents): void
    {
        File::put(public_path($relative), $contents);
        $this->created[] = public_path($relative);
    }

    // --- homepage material ----------------------------------------------------------------------

    public function test_credentials_appear_with_their_links_once_a_real_certificate_exists(): void
    {
        $this->assertNull(HomeMedia::certificate());
        $this->get('/')->assertOk()->assertDontSee('id="credentials"', false)->assertDontSee('href="#credentials"', false);

        // A file that isn't a readable image is ignored.
        $this->raw('images/home/certificate.jpg', 'not an image');
        $this->assertNull(HomeMedia::certificate());
        $this->get('/')->assertDontSee('id="credentials"', false);

        File::delete(public_path('images/home/certificate.jpg'));
        $this->jpeg('images/home/certificate.jpg', 200, 283);
        $this->assertSame(asset('images/home/certificate.jpg'), HomeMedia::certificate());
        $this->get('/')->assertOk()
            ->assertSee('id="credentials"', false)
            ->assertSee('src="'.asset('images/home/certificate.jpg').'"', false)
            ->assertSee('Ministry of Commerce, Government of Bangladesh')
            ->assertSee('It is not a government endorsement', false)
            ->assertSee('href="#credentials"', false) // menu "About us" and the footer links
            ->assertSee('Registered business · see certificate');
    }

    public function test_past_recipients_show_only_readable_portraits_and_otherwise_a_note(): void
    {
        $this->get('/')->assertOk()->assertSee('Award recipients will be featured here')->assertDontSee('id="recipientsRow"', false);

        $this->jpeg('images/home/recipients/Rina-Akter_Rising-Brand-2025.jpg');
        $this->raw('images/home/recipients/broken.jpg', 'not an image');
        $this->raw('images/home/recipients/.hidden.jpg', 'x');
        $this->raw('images/home/recipients/notes.txt', 'x');

        $this->assertSame([['src' => asset('images/home/recipients/Rina-Akter_Rising-Brand-2025.jpg'), 'caption' => 'Rina Akter Rising Brand 2025']], HomeMedia::recipients());
        $html = $this->get('/')->assertOk()->assertSee('id="recipientsRow"', false)->assertSee('Rina Akter Rising Brand 2025')->getContent();
        $this->assertStringNotContainsString('broken.jpg', $html);
        $this->assertStringNotContainsString('Award recipients will be featured here', $html);
    }

    public function test_an_empty_or_messy_upload_folder_adds_no_programme_photos(): void
    {
        $before = HomeMedia::programme();
        $this->assertNotEmpty($before, 'The supplied photos in public/images/home/programme are used.');

        File::ensureDirectoryExists(public_path('images/Homepage/programme'));
        $this->created[] = public_path('images/Homepage');
        $this->assertSame($before, HomeMedia::programme(), 'An empty upload folder changes nothing.');

        $this->raw('images/Homepage/programme/broken.jpg', 'not an image');
        $this->raw('images/Homepage/programme/readme.txt', 'x');
        $this->assertSame($before, HomeMedia::programme());

        $this->jpeg('images/Homepage/programme/programme-workshop.jpg', 160, 120);
        $after = HomeMedia::programme();
        $this->assertCount(count($before) + 1, $after);
        $this->assertSame(asset('images/Homepage/programme/programme-workshop.jpg'), end($after)['src']);

        $html = $this->get('/')->assertOk()->getContent();
        $this->assertStringNotContainsString('broken.jpg', $html);
        foreach (HomeMedia::programme() as $p) {
            $this->assertFileExists(public_path(ltrim(parse_url($p['src'], PHP_URL_PATH), '/')));
        }
    }

    // --- removed homepage switches --------------------------------------------------------------

    public function test_the_removed_switches_are_gone_and_their_saved_values_are_kept(): void
    {
        PlatformSetting::put('homepage', array_merge(HomepageContent::defaults(), ['show_sponsors' => false, 'show_stories' => false, 'show_events' => true]));
        $this->actingAs($this->makePlatformAdmin(), 'super_admin');

        $form = $this->get(route('super.homepage.edit'))->assertOk();
        foreach (['show_sponsors', 'show_stories', 'show_events', 'Partner with us', 'Brands to Watch'] as $gone) {
            $form->assertDontSee($gone, false);
        }

        $this->put(route('super.homepage.update'), ['announcement' => 'A', 'hero_pill' => 'P', 'hero_title' => 'T', 'hero_highlight' => 'H', 'hero_sub' => 'S', 'sample_fallback' => '1'])
            ->assertSessionHas('success');
        PlatformSetting::flush();
        $saved = PlatformSetting::get('homepage', []);
        $this->assertSame([false, false, true, true], [$saved['show_sponsors'], $saved['show_stories'], $saved['show_events'], $saved['sample_fallback']]);
        $this->assertSame('T', $saved['hero_title']);
    }

    // --- retired categories ---------------------------------------------------------------------

    private function importChunk68(): void
    {
        $sql = str_replace(
            ['ON DUPLICATE KEY UPDATE sort_order = VALUES(sort_order), is_active = 1, updated_at = NOW()', 'NOW()'],
            ['ON CONFLICT(slug) DO UPDATE SET sort_order = excluded.sort_order, is_active = 1, updated_at = CURRENT_TIMESTAMP', 'CURRENT_TIMESTAMP'],
            file_get_contents(base_path('database/sql/chunk68.sql')),
        );
        DB::unprepared($sql);
    }

    /** Production's two real brands: one in "Fashion & Apparel", one in "Services". */
    private function legacyBrands(): array
    {
        $brands = [];
        foreach ([['fashion-apparel', 'Fashion & Apparel', 'Shop Basket', '01711111111'], ['services', 'Services', 'MetaSoft', '01722222222']] as [$slug, $name, $brand, $phone]) {
            $cat = BrandCategory::create(['name' => $name, 'slug' => $slug, 'sort_order' => 1, 'is_active' => true]);
            $owner = BrandOwner::create(['name' => $brand, 'email' => "$slug@example.com", 'phone' => $phone, 'password' => 'secret123']);
            $b = Brand::create(['brand_owner_id' => $owner->id, 'name' => $brand, 'brand_category_id' => $cat->id, 'founder_name' => $brand, 'phone' => $phone,
                'email' => "$slug@example.com", 'division' => 'Dhaka', 'district' => 'Dhaka', 'status' => 'approved', 'approved_at' => now()]);
            $b->assignSlug();
            $b->save();
            $brands[$slug] = $b;
        }
        $this->importChunk68();

        return $brands;
    }

    public function test_only_the_unambiguous_legacy_category_is_mapped_and_only_with_apply(): void
    {
        ['fashion-apparel' => $shop, 'services' => $metasoft] = $this->legacyBrands();
        $fashion = $shop->brand_category_id;
        $services = $metasoft->brand_category_id;

        $this->artisan('platform:map-legacy-categories')
            ->expectsOutputToContain('"Fashion & Apparel" → "Clothing & Fashion" (dry run)')
            ->expectsOutputToContain('"Services" has no unambiguous equivalent')
            ->assertSuccessful();
        $this->assertSame([$fashion, $services], [$shop->fresh()->brand_category_id, $metasoft->fresh()->brand_category_id]);

        $this->artisan('platform:map-legacy-categories --apply')->expectsOutputToContain('Moved 1 brand(s).')->assertSuccessful();
        $clothing = BrandCategory::where('slug', 'clothing-fashion')->value('id');
        $this->assertSame($clothing, $shop->fresh()->brand_category_id);
        $this->assertSame($services, $metasoft->fresh()->brand_category_id, 'Ambiguous: left for Super Admin.');
        $log = PlatformAuditLog::where('action', 'brand.category_mapped')->sole();
        $this->assertSame(['Fashion & Apparel', 'Clothing & Fashion'], [$log->changes['from'], $log->changes['to']]);

        // Nothing renamed or deleted; running again moves nothing.
        $this->assertSame('Fashion & Apparel', BrandCategory::find($fashion)->name);
        $this->assertSame(count(BusinessCategories::NAMES) + 2, BrandCategory::count());
        $this->artisan('platform:map-legacy-categories --apply')->expectsOutputToContain('Moved 0 brand(s).')->assertSuccessful();
    }

    public function test_admin_can_keep_a_retired_category_but_never_assign_one(): void
    {
        ['services' => $metasoft, 'fashion-apparel' => $shop] = $this->legacyBrands();
        $this->actingAs($this->makePlatformAdmin(), 'super_admin');
        $services = $metasoft->brand_category_id;

        $this->get(route('super.brands.show', $metasoft))->assertOk()->assertSee('Retired category:');
        $edit = $this->get(route('super.brands.edit', $metasoft))->assertOk();
        $edit->assertSee('Services (inactive)')->assertDontSee('Fashion &amp; Apparel', false)->assertSee('Clothing &amp; Fashion', false);

        $form = fn (Brand $b, $cat) => ['name' => $b->name, 'brand_category_id' => $cat, 'founder_name' => $b->founder_name, 'phone' => $b->phone,
            'email' => $b->email, 'division' => 'Dhaka', 'district' => 'Dhaka'];
        $this->put(route('super.brands.update', $metasoft), $form($metasoft, $services))->assertSessionHasNoErrors();
        $this->put(route('super.brands.update', $metasoft), $form($metasoft, $shop->brand_category_id))->assertSessionHasErrors('brand_category_id');
        $this->post(route('super.brands.store'), $form($metasoft, $services) + ['name' => 'New One', 'phone' => '01933333333', 'facebook' => 'https://www.facebook.com/newone'])
            ->assertSessionHasErrors('brand_category_id');
        $this->assertSame($services, $metasoft->fresh()->brand_category_id);

        // A queued owner change to a now-retired category can't be approved.
        $change = BrandChangeRequest::create(['brand_id' => $shop->id, 'status' => 'pending', 'changes' => ['brand_category_id' => $services], 'original' => ['brand_category_id' => $shop->brand_category_id]]);
        $this->post(route('super.brands.changes.approve', $change))->assertSessionHas('error');
        $this->assertSame('pending', $change->fresh()->status);
        $this->assertNotSame($services, $shop->fresh()->brand_category_id);
    }
}
