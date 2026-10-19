<?php

namespace Tests\Feature\Platform;

use App\Models\Brand;
use App\Models\BrandCategory;
use App\Models\BrandOwner;
use App\Support\Platform\BusinessCategories;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithPlatformSchema;
use Tests\TestCase;

/**
 * The 50 registration categories: database/sql/chunk68.sql (run here against
 * SQLite, translated only where the dialects differ) loads exactly
 * BusinessCategories::NAMES, keeps existing rows and brands intact, and
 * retires — never renames or deletes — categories that aren't on the list.
 */
class BusinessCategoriesTest extends TestCase
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

    /** chunk68.sql, with MariaDB's upsert/NOW() written the SQLite way. */
    private function importChunk68(): void
    {
        $sql = file_get_contents(base_path('database/sql/chunk68.sql'));
        $sql = str_replace(
            ['ON DUPLICATE KEY UPDATE sort_order = VALUES(sort_order), is_active = 1, updated_at = NOW()', 'NOW()'],
            ['ON CONFLICT(slug) DO UPDATE SET sort_order = excluded.sort_order, is_active = 1, updated_at = CURRENT_TIMESTAMP', 'CURRENT_TIMESTAMP'],
            $sql,
        );
        DB::unprepared($sql);
    }

    /** The production starting point (chunk64.sql seeds), with one real brand in a category that isn't on the new list. */
    private function legacyCategoriesWithABrand(): Brand
    {
        foreach ([['Fashion & Apparel', 'fashion-apparel', 'ফ্যাশন ও পোশাক', 1], ['Food & Beverage', 'food-beverage', 'খাদ্য ও পানীয়', 2], ['Education & Training', 'education-training', 'শিক্ষা ও প্রশিক্ষণ', 8], ['Services', 'services', 'সেবা', 9]] as [$name, $slug, $bn, $sort]) {
            BrandCategory::create(['name' => $name, 'slug' => $slug, 'bn_name' => $bn, 'icon' => 'heart', 'sort_order' => $sort, 'is_active' => true]);
        }
        $owner = BrandOwner::create(['name' => 'Rina', 'email' => 'rina@example.com', 'phone' => '01711111111', 'password' => 'secret123']);
        $brand = Brand::create(['brand_owner_id' => $owner->id, 'name' => 'Old Shop', 'brand_category_id' => BrandCategory::where('slug', 'fashion-apparel')->value('id'),
            'founder_name' => 'Rina', 'phone' => '01711111111', 'email' => 'rina@example.com', 'division' => 'Dhaka', 'district' => 'Dhaka', 'status' => 'approved', 'approved_at' => now()]);
        $brand->assignSlug();
        $brand->save();

        return $brand;
    }

    public function test_the_list_has_the_50_exact_labels_with_unique_slugs(): void
    {
        $this->assertCount(50, BusinessCategories::NAMES);
        $this->assertCount(50, array_unique(BusinessCategories::NAMES));
        $this->assertCount(50, BusinessCategories::bySlug());
        $this->assertSame('Clothing & Fashion', BusinessCategories::NAMES[0]);
        $this->assertSame('Religious & Cultural Services', BusinessCategories::NAMES[49]);
        $this->assertSame('food-beverage', Str::slug('Food & Beverage'));
    }

    public function test_chunk68_loads_exactly_the_list_and_keeps_existing_rows_and_brands(): void
    {
        $brand = $this->legacyCategoriesWithABrand();
        $food = BrandCategory::where('slug', 'food-beverage')->firstOrFail();

        $this->importChunk68();
        $this->importChunk68(); // re-running reaches the same state

        $active = BrandCategory::active()->ordered()->get();
        $this->assertSame(BusinessCategories::NAMES, $active->pluck('name')->all());
        $this->assertSame(range(1, 50), $active->pluck('sort_order')->all());

        // Existing rows with a matching slug are reused, not duplicated or renamed.
        $this->assertSame(1, BrandCategory::where('slug', 'food-beverage')->count());
        $this->assertSame($food->id, BrandCategory::where('slug', 'food-beverage')->value('id'));
        $this->assertSame('খাদ্য ও পানীয়', BrandCategory::where('slug', 'food-beverage')->value('bn_name'));

        // Categories not on the list are retired, not deleted or renamed; the brand keeps its category.
        $retired = BrandCategory::where('slug', 'fashion-apparel')->firstOrFail();
        $this->assertSame(['Fashion & Apparel', false], [$retired->name, (bool) $retired->is_active]);
        $this->assertSame(52, BrandCategory::count());
        $this->assertSame($retired->id, $brand->fresh()->brand_category_id);
        $this->get(route('brands.show', $brand->slug))->assertOk()->assertSee('Fashion &amp; Apparel', false);
    }

    public function test_registration_offers_the_50_in_order_and_rejects_retired_ones(): void
    {
        $this->legacyCategoriesWithABrand();
        $this->importChunk68();

        $html = $this->get(route('owner.register'))->assertOk()->getContent();
        preg_match('~<select name="brand_category_id".*?</select>~s', $html, $m);
        preg_match_all('~<option value="\d+"[^>]*>([^<]+?)(?: — [^<]*)?</option>~', $m[0], $options);
        $this->assertSame(BusinessCategories::NAMES, array_map(fn ($o) => html_entity_decode(trim($o)), $options[1]));

        $this->post(route('owner.register.store'), $this->form(BrandCategory::where('slug', 'fashion-apparel')->value('id'), 0))
            ->assertSessionHasErrors('brand_category_id');
    }

    public function test_every_one_of_the_50_can_be_saved_and_is_shown_on_the_profile(): void
    {
        $this->importChunk68();

        foreach (BrandCategory::active()->ordered()->get() as $i => $category) {
            // A different visitor each time: one IP may only register a few accounts (throttle:owner-register).
            $this->withServerVariables(['REMOTE_ADDR' => '10.0.'.intdiv($i, 200).'.'.($i % 200 + 1)])
                ->post(route('owner.register.store'), $this->form($category->id, $i))->assertSessionHasNoErrors()->assertRedirect(route('owner.dashboard'));
            $this->app['auth']->guard('brand_owner')->logout();

            $brand = Brand::where('phone', $this->phone($i))->firstOrFail();
            $this->assertSame($category->id, $brand->brand_category_id);
            $brand->update(['status' => 'approved', 'approved_at' => now()]);
            $brand->assignSlug();
            $brand->save();
            $this->get(route('brands.show', $brand->slug))->assertOk()->assertSee(e($category->name), false);
        }
        $this->assertSame(50, Brand::count());
    }

    public function test_a_brand_in_a_retired_category_keeps_working_for_its_owner_and_the_directory(): void
    {
        $brand = $this->legacyCategoriesWithABrand();
        $this->importChunk68();
        $retiredId = $brand->brand_category_id;

        // The directory still offers the retired category, because a published brand uses it.
        $this->get(route('brands.index'))->assertOk()->assertSee('value="fashion-apparel"', false)->assertDontSee('value="services"', false);
        $this->get(route('brands.index', ['category' => 'fashion-apparel']))->assertOk()->assertSee('Old Shop');

        // Its owner can save other edits without being forced to change category…
        $this->actingAs($brand->owner, 'brand_owner');
        $this->get(route('owner.brand.edit'))->assertOk()->assertSee('Fashion &amp; Apparel', false);
        $this->put(route('owner.brand.update'), ['name' => 'Old Shop', 'brand_category_id' => $retiredId, 'founder_name' => 'Rina', 'phone' => '01711111111',
            'email' => 'rina@example.com', 'division' => 'Dhaka', 'district' => 'Dhaka', 'description' => 'Still here'])->assertSessionHasNoErrors();
        $this->assertSame(['Still here', $retiredId], [$brand->fresh()->description, $brand->fresh()->brand_category_id]);

        // …but can't move to a different retired category.
        $this->put(route('owner.brand.update'), ['name' => 'Old Shop', 'brand_category_id' => BrandCategory::where('slug', 'services')->value('id'), 'founder_name' => 'Rina',
            'phone' => '01711111111', 'email' => 'rina@example.com', 'division' => 'Dhaka', 'district' => 'Dhaka'])->assertSessionHasErrors('brand_category_id');
    }

    private function phone(int $i): string
    {
        return '0181'.str_pad((string) $i, 7, '0', STR_PAD_LEFT);
    }

    private function form(int $categoryId, int $i): array
    {
        return [
            'brand_name' => 'Brand '.$i, 'logo' => UploadedFile::fake()->image('logo.png', 120, 120), 'brand_category_id' => $categoryId,
            'founder_name' => 'Founder '.$i, 'phone' => $this->phone($i), 'email' => "founder{$i}@example.com", 'division' => 'Dhaka', 'district' => 'Gazipur',
            'facebook' => 'https://www.facebook.com/brand'.$i, 'password' => 'secret123', 'password_confirmation' => 'secret123', 'terms' => '1',
        ];
    }
}
