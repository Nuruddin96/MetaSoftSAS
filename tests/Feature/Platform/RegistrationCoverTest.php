<?php

namespace Tests\Feature\Platform;

use App\Models\Brand;
use App\Models\BrandCategory;
use App\Support\Platform\PlatformSchema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\InteractsWithPlatformSchema;
use Tests\TestCase;

/**
 * Optional brand cover image on "List Your Brand" (brands.cover_path,
 * chunk69.sql): validated like gallery photos, stored on the public disk,
 * shown as the profile banner — and safely ignored until the column exists.
 */
class RegistrationCoverTest extends TestCase
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
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
            'terms' => '1',
        ], $overrides));
    }

    public function test_the_form_shows_logo_and_cover_upload_boxes_side_by_side(): void
    {
        $this->makePlatformCategory();

        $this->get(route('owner.register'))->assertOk()
            ->assertSeeInOrder(['Logo', 'name="logo"', 'Cover Image', 'name="cover"'], false)
            ->assertSee('data-preview="#coverPreview"', false)
            ->assertSee('data-preview="#logoPreview"', false);
    }

    public function test_a_cover_is_stored_and_used_as_the_profile_banner(): void
    {
        $this->register(['cover' => UploadedFile::fake()->image('cover.jpg', 1200, 400)])->assertRedirect(route('owner.dashboard'));

        $brand = Brand::firstOrFail();
        $this->assertNotNull($brand->cover_path);
        $this->assertStringStartsWith('brands/covers/', $brand->cover_path);
        Storage::disk('public')->assertExists($brand->cover_path);
        $this->assertSame(asset('storage/'.$brand->cover_path), $brand->coverUrl());
        $this->assertSame($brand->coverUrl(), $brand->toCard()['cover']);
    }

    public function test_the_cover_is_optional_and_existing_logo_uploads_still_work(): void
    {
        $this->register()->assertRedirect(route('owner.dashboard'));

        $brand = Brand::firstOrFail();
        $this->assertNull($brand->cover_path);
        Storage::disk('public')->assertExists($brand->logo_path);
        $this->assertStringStartsWith('brands/logos/', $brand->logo_path);
        $this->assertNull($brand->coverUrl());
    }

    public function test_an_invalid_cover_is_rejected_and_nothing_is_created(): void
    {
        foreach ([
            UploadedFile::fake()->create('cover.pdf', 100, 'application/pdf'),
            UploadedFile::fake()->image('huge.jpg')->size(3073),
        ] as $file) {
            $this->from(route('owner.register'))->register(['cover' => $file])
                ->assertRedirect(route('owner.register'))->assertSessionHasErrors('cover');
        }

        $this->assertSame(0, Brand::count());
        $this->assertSame([], Storage::disk('public')->allFiles('brands/covers'));
    }

    public function test_without_the_cover_column_the_box_is_hidden_and_a_cover_is_ignored(): void
    {
        Schema::table('brands', fn (Blueprint $t) => $t->dropColumn('cover_path'));
        PlatformSchema::flush();

        $this->get(route('owner.register'))->assertOk()->assertDontSee('name="cover"', false)->assertSee('name="logo"', false);

        $this->register(['cover' => UploadedFile::fake()->image('cover.jpg', 1200, 400)])->assertRedirect(route('owner.dashboard'));
        $this->assertSame(1, Brand::count());
        $this->assertSame([], Storage::disk('public')->allFiles('brands/covers'));
    }

    public function test_without_an_uploaded_cover_the_first_gallery_photo_is_the_banner(): void
    {
        $category = $this->makePlatformCategory();
        $brand = Brand::create([
            'name' => 'Gallery Brand', 'brand_category_id' => $category->id, 'founder_name' => 'A', 'phone' => '01812345678',
            'email' => 'g@example.com', 'division' => 'Dhaka', 'district' => 'Dhaka', 'status' => 'approved',
            'gallery' => ['brands/gallery/1/a.jpg', 'brands/gallery/1/b.jpg'],
        ]);

        $this->assertSame(asset('storage/brands/gallery/1/a.jpg'), $brand->coverUrl());
    }
}
