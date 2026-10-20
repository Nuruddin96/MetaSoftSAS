<?php

namespace Tests\Feature\Platform;

use App\Models\Brand;
use Tests\Concerns\InteractsWithPlatformSchema;
use Tests\TestCase;

/**
 * Premium brand profile header: cover banner (uploaded cover → first gallery
 * photo → brand gradient), logo tile, and the "✓ Verified" pill — shown only
 * for brands Super Admin has verified — plus the shared navy footer.
 */
class BrandProfileLayoutTest extends TestCase
{
    use InteractsWithPlatformSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPlatformSchema();
        $this->withoutVite();
    }

    private function publishedBrand(array $attrs = []): Brand
    {
        $category = $this->makePlatformCategory();
        $brand = Brand::create(array_merge([
            'name' => 'Nakshi Ghor', 'brand_category_id' => $category->id, 'founder_name' => 'Rina Akter', 'phone' => '01712345678',
            'email' => 'rina@example.com', 'division' => 'Dhaka', 'district' => 'Gazipur', 'status' => 'approved', 'approved_at' => now(),
        ], $attrs));
        $brand->assignSlug();
        $brand->save();

        return $brand->fresh();
    }

    public function test_a_verified_brand_shows_the_verified_pill_beside_its_name(): void
    {
        $brand = $this->publishedBrand(['is_verified' => true, 'verified_at' => now()]);

        $this->get(route('brands.show', $brand->slug))->assertOk()
            ->assertSee('data-brand-hero', false)
            ->assertSeeInOrder(['<h1', 'Nakshi Ghor</h1>', 'rounded-full border border-[#1E40AF]/60', 'Verified<span class="sr-only"> by MetaSoft BD</span>'], false);
    }

    public function test_an_unverified_brand_never_shows_the_verified_pill(): void
    {
        $brand = $this->publishedBrand(['is_verified' => false, 'is_featured' => true]);

        $this->get(route('brands.show', $brand->slug))->assertOk()
            ->assertSee('Nakshi Ghor')
            ->assertDontSee('border-[#1E40AF]/60', false)
            ->assertDontSee('Verified<span class="sr-only">', false);
    }

    public function test_the_uploaded_cover_is_the_banner_with_gallery_and_gradient_fallbacks(): void
    {
        $brand = $this->publishedBrand(['cover_path' => 'brands/covers/cover.jpg', 'gallery' => ['brands/gallery/1/a.jpg']]);
        $this->get(route('brands.show', $brand->slug))->assertOk()
            ->assertSee('src="'.asset('storage/brands/covers/cover.jpg').'" alt="" class="absolute inset-0 -z-20', false);

        $brand->update(['cover_path' => null]);
        $this->get(route('brands.show', $brand->slug))->assertOk()
            ->assertSee('src="'.asset('storage/brands/gallery/1/a.jpg').'" alt="" class="absolute inset-0 -z-20', false);

        $brand->update(['gallery' => null]);
        [$from, $to] = $brand->gradient();
        $this->get(route('brands.show', $brand->slug))->assertOk()
            ->assertDontSee('data-brand-cover', false)
            ->assertSee('background:linear-gradient(135deg,'.$from.','.$to.')', false);
    }

    public function test_the_profile_uses_the_navy_footer_with_the_contact_strip(): void
    {
        $brand = $this->publishedBrand();

        $this->get(route('brands.show', $brand->slug))->assertOk()
            ->assertSee('<footer class="bg-night text-white">', false)
            ->assertSee('data-footer-contact', false)
            ->assertSee('mailto:support@metasoftbd.com', false)
            ->assertSee('Brand & Entrepreneur Network', false);
    }
}
