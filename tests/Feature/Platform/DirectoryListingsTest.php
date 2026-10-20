<?php

namespace Tests\Feature\Platform;

use App\Models\AwardNomination;
use App\Models\Brand;
use App\Models\BrandCategory;
use App\Models\BrandDirectoryListing;
use App\Models\BrandOwner;
use App\Models\PlatformAuditLog;
use App\Models\PlatformNotification;
use App\Models\VoteEntry;
use App\Support\Platform\BusinessCategories;
use App\Support\Platform\DirectoryListings;
use App\Support\Platform\HomepageContent;
use DomainException;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithPlatformSchema;
use Tests\TestCase;

/**
 * Discovery directory (chunk70.sql): researched listings stay separate from
 * registered brands — idempotent import, duplicate detection, public
 * visibility + filtering, never a Verified badge, claim → verified
 * replacement → rollback, and archiving (never deleting) expired rows.
 */
class DirectoryListingsTest extends TestCase
{
    use InteractsWithPlatformSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPlatformSchema();
        $this->setUpDirectorySchema();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->withoutVite();
        Storage::fake('public');

        $order = 0;
        foreach (BusinessCategories::bySlug() as $slug => $name) {
            BrandCategory::create(['name' => $name, 'slug' => $slug, 'sort_order' => ++$order, 'is_active' => true]);
        }
    }

    private function directory(): DirectoryListings
    {
        return app(DirectoryListings::class);
    }

    private function importAll(): array
    {
        return $this->directory()->import($this->directory()->load(), true);
    }

    private function brand(string $name, array $attrs = []): Brand
    {
        static $n = 0;
        $n++;
        $phone = '0181'.str_pad((string) $n, 7, '0', STR_PAD_LEFT);
        $owner = BrandOwner::create(['name' => 'Owner '.$n, 'email' => "dir{$n}@example.com", 'phone' => $phone, 'password' => 'secret123']);
        $brand = Brand::create(array_merge([
            'brand_owner_id' => $owner->id, 'name' => $name, 'brand_category_id' => BrandCategory::first()->id,
            'founder_name' => 'Owner '.$n, 'phone' => $phone, 'email' => "dir{$n}@example.com",
            'division' => 'Dhaka', 'district' => 'Dhaka', 'status' => 'approved', 'approved_at' => now(),
        ], $attrs));
        if ($brand->status === 'approved') {
            $brand->assignSlug();
            $brand->save();
        }

        return $brand;
    }

    // --- research data + import ------------------------------------------------------------

    public function test_data_file_has_thirty_records_with_sources_for_every_verified_one(): void
    {
        $data = $this->directory()->load();

        $this->assertCount(30, $data['listings']);
        foreach ($data['listings'] as $r) {
            $this->assertContains($r['verification_status'], ['verified', 'needs_review'], $r['slug']);
            if ($r['verification_status'] === 'verified') {
                $this->assertNotEmpty($r['website'], $r['slug']);
                $this->assertContains($r['source_url'], $r['sources'], $r['slug'].': source_url must be one of the recorded sources');
            }
            $this->assertArrayHasKey($r['category'], BusinessCategories::bySlug(), $r['slug'].' uses an unknown category');
        }
        $homepage = array_filter($data['listings'], fn ($r) => $r['homepage_order'] !== null);
        $this->assertGreaterThanOrEqual(6, count($homepage));
        $this->assertLessThanOrEqual(9, count($homepage));
    }

    public function test_dry_run_writes_nothing_and_apply_is_idempotent(): void
    {
        $this->artisan('platform:directory-import')->assertSuccessful();
        $this->assertSame(0, BrandDirectoryListing::count());

        $this->artisan('platform:directory-import', ['--apply' => true])->assertSuccessful();
        $this->assertSame(30, BrandDirectoryListing::count());
        $this->assertSame(29, BrandDirectoryListing::where('verification_status', 'verified')->count());
        $this->assertSame(0, Brand::count(), 'Listings must never create registered brands.');
        $this->assertSame(30, BrandDirectoryListing::where('source_type', 'public_research')->where('membership_status', 'not_member')->count());

        $audit = PlatformAuditLog::count();
        $report = $this->importAll();
        $this->assertSame(['unchanged'], array_values(array_unique(array_column($report, 0))));
        $this->assertSame(30, BrandDirectoryListing::count());
        $this->assertSame($audit, PlatformAuditLog::count(), 'A no-op re-import writes no audit rows.');
    }

    public function test_reimport_never_revives_archived_rows_or_clears_claims(): void
    {
        $this->importAll();
        $aarong = BrandDirectoryListing::where('slug', 'aarong')->first();
        $this->directory()->archive($aarong, 'manual', 'Owner asked');
        $claimer = $this->brand('Walton', ['status' => 'pending']);
        $walton = BrandDirectoryListing::where('slug', 'walton')->first();
        $walton->update(['claimed_by_brand_id' => $claimer->id, 'membership_status' => 'claim_pending']);

        $this->importAll();

        $this->assertSame('archived', $aarong->fresh()->status);
        $this->assertSame('claim_pending', $walton->fresh()->membership_status);
        $this->assertSame($claimer->id, $walton->fresh()->claimed_by_brand_id);
    }

    // --- duplicate detection ---------------------------------------------------------------

    public function test_import_skips_names_and_domains_already_registered(): void
    {
        $this->brand('WALTON');
        $this->brand('Handmade by Rina', ['website' => 'https://aarong.com/some-page']);
        $this->brand('Rejected Daraz', ['status' => 'rejected', 'website' => 'https://www.daraz.com.bd']);

        $report = collect($this->importAll())->keyBy(1);

        $this->assertSame('skipped', $report['walton'][0]);
        $this->assertSame('skipped', $report['aarong'][0]);
        $this->assertSame('created', $report['daraz-bangladesh'][0], 'Rejected registrations do not block a listing.');
        $this->assertFalse(BrandDirectoryListing::whereIn('slug', ['walton', 'aarong'])->exists());
    }

    public function test_duplicate_names_or_domains_inside_the_data_file_are_rejected(): void
    {
        $data = $this->directory()->load();
        $data['listings'][] = array_merge($data['listings'][0], ['slug' => 'aarong-copy', 'name' => 'AARONG!']);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('duplicate name');
        $this->directory()->import($data, false);
    }

    public function test_domain_normalization(): void
    {
        $this->assertSame('shajgoj.com', BrandDirectoryListing::domainOf('https://shop.shajgoj.com/'));
        $this->assertSame('daraz.com.bd', BrandDirectoryListing::domainOf('www.Daraz.com.bd/products'));
        $this->assertNull(BrandDirectoryListing::domainOf('not a url'));
    }

    // --- public visibility + filtering -----------------------------------------------------

    public function test_directory_lists_verified_listings_separately_and_hides_needs_review(): void
    {
        $this->importAll();

        $this->get(route('brands.index'))->assertOk()
            ->assertSee('Well-known brands in Bangladesh')
            ->assertSee('have not joined, endorsed or partnered with MetaSoft BD')
            ->assertSee('Aarong')->assertSee('Grameenphone')
            ->assertDontSee('Akij Group');

        $this->get(route('directory.show', 'aarong'))->assertOk()
            ->assertSee('has not been verified by MetaSoft BD')
            ->assertSee('https://www.aarong.com/', false)
            ->assertSee('rel="nofollow noopener noreferrer"', false)
            ->assertSee('<meta name="robots" content="noindex, follow">', false);
        $this->get(route('directory.show', 'akij-group'))->assertNotFound();
        $this->get(route('directory.show', 'no-such-brand'))->assertNotFound();
    }

    public function test_category_filter_and_search_apply_to_listings(): void
    {
        $this->importAll();

        $this->get(route('brands.index', ['category' => 'leather-footwear']))->assertOk()
            ->assertSee('Bata Bangladesh')->assertSee('Apex')->assertDontSee('Grameenphone');

        $this->get(route('brands.index', ['q' => 'ride-hailing']))->assertOk()
            ->assertSee('Pathao')->assertDontSee('Aarong');

        // Listings have no division and are never "verified brands", so those filters exclude them.
        $this->get(route('brands.index', ['verified' => 1]))->assertOk()->assertDontSee('Well-known brands in Bangladesh');
        $this->get(route('brands.index', ['division' => 'Dhaka']))->assertOk()->assertDontSee('Well-known brands in Bangladesh');
    }

    public function test_homepage_shows_picked_listings_without_counting_them_as_members(): void
    {
        $this->importAll();

        $html = $this->get('/')->assertOk()
            ->assertSee('Well-known brands in Bangladesh')
            ->assertSee(route('brands.index').'#listings', false)
            ->assertSee(route('directory.show', 'aarong'), false)
            ->assertSee(route('directory.show', 'brac'), false)
            ->getContent();

        $this->assertSame(8, substr_count($html, 'Public listing'));
        $this->assertStringNotContainsString(route('directory.show', 'grameenphone'), $html, 'Only homepage picks appear on the homepage.');
        $this->assertSame(0, app(HomepageContent::class)->realBrandCount());
    }

    public function test_listings_never_show_the_verified_badge(): void
    {
        $this->importAll();
        $listing = BrandDirectoryListing::where('slug', 'bkash')->first();

        $card = Blade::render('<x-plat.listing-card :listing="$listing" />', ['listing' => $listing]);
        $this->assertStringNotContainsString('metasoft-verified.png', $card);
        $this->assertStringContainsString('Public listing', $card);

        $this->get(route('directory.show', 'bkash'))->assertOk()->assertDontSee('metasoft-verified.png', false);
    }

    // --- registration claim + replacement ---------------------------------------------------

    public function test_registration_with_a_matching_name_records_a_claim_but_changes_nothing_public(): void
    {
        $this->importAll();

        $this->post(route('owner.register.store'), [
            'brand_name' => 'Aarong', 'logo' => UploadedFile::fake()->image('logo.png', 200, 200),
            'brand_category_id' => BrandCategory::where('slug', 'clothing-fashion')->value('id'),
            'founder_name' => 'Someone', 'phone' => '01712345678', 'email' => 'someone@example.com',
            'division' => 'Dhaka', 'district' => 'Gazipur', 'facebook' => 'https://www.facebook.com/someaarongpage',
            'password' => 'secret123', 'password_confirmation' => 'secret123', 'terms' => '1',
        ])->assertRedirect(route('owner.dashboard'));

        $brand = Brand::where('name', 'Aarong')->firstOrFail();
        $listing = BrandDirectoryListing::where('slug', 'aarong')->first();
        $this->assertSame('claim_pending', $listing->membership_status);
        $this->assertSame($brand->id, $listing->claimed_by_brand_id);
        $this->assertNull($listing->replaced_by_brand_id);
        $this->assertTrue($listing->isListed(), 'A claim alone never hides or replaces the listing.');
        $this->assertTrue(PlatformNotification::forAdmins()->where('type', 'directory_claim')->exists());
        $this->assertSame(1, BrandDirectoryListing::where('claimed_by_brand_id', $brand->id)->count(), 'Unrelated listings are untouched.');
    }

    public function test_a_seller_page_on_a_marketplace_never_matches_the_marketplace_listing(): void
    {
        $this->importAll();
        $seller = $this->brand('Rina Crafts', ['status' => 'pending', 'website' => 'https://www.daraz.com.bd/shop/rina-crafts/']);

        $this->assertTrue($this->directory()->matches($seller)->isEmpty());
        $this->directory()->recordClaim($seller);
        $this->assertSame('not_member', BrandDirectoryListing::where('slug', 'daraz-bangladesh')->value('membership_status'));
        $this->assertTrue(BrandDirectoryListing::isSiteRoot('https://www.aarong.com/bgd'));
        $this->assertFalse(BrandDirectoryListing::isSiteRoot('https://www.foodpanda.com.bd/restaurant/x1/cafe'));
    }

    public function test_listings_never_enter_voting_awards_or_brand_counts(): void
    {
        $this->importAll();

        $this->assertSame(0, Brand::count());
        $this->assertSame(0, VoteEntry::count());
        $this->assertSame(0, AwardNomination::count());
        $this->assertSame([], app(HomepageContent::class)->stats());
        $this->assertFalse(Brand::nameTaken('Aarong'), 'The real Aarong can still register under its own name.');
    }

    public function test_matching_by_official_domain(): void
    {
        $this->importAll();
        $brand = $this->brand('Some Other Name', ['status' => 'pending', 'website' => 'https://pathao.com/en']);

        $this->assertSame(['pathao'], $this->directory()->matches($brand)->pluck('slug')->all());
    }

    public function test_replacement_requires_an_approved_verified_brand_then_redirects_and_can_be_rolled_back(): void
    {
        $this->importAll();
        $admin = $this->makePlatformAdmin();
        $listing = BrandDirectoryListing::where('slug', 'walton')->first();
        $brand = $this->brand('Walton Official', ['status' => 'pending', 'website' => 'https://waltonbd.com']);
        $this->directory()->recordClaim($brand);

        $this->actingAs($admin, 'super_admin');
        $this->post(route('super.directory.replace', $listing), ['brand_id' => $brand->id])->assertSessionHas('error');
        $this->assertTrue($listing->fresh()->isListed());

        $brand->update(['status' => 'approved', 'approved_at' => now()]);
        $brand->assignSlug();
        $brand->save();
        $this->post(route('super.directory.replace', $listing), ['brand_id' => $brand->id])->assertSessionHas('error');

        $brand->update(['is_verified' => true, 'verified_at' => now(), 'verification_basis' => ['contact']]);
        $this->post(route('super.directory.replace', $listing), ['brand_id' => $brand->id])->assertSessionHas('error', fn ($m) => str_contains($m, 'trade licence'));

        $unrelated = $this->brand('Unrelated Verified', ['is_verified' => true, 'verification_basis' => ['trade_licence']]);
        $this->post(route('super.directory.replace', $listing), ['brand_id' => $unrelated->id])->assertSessionHas('error', fn ($m) => str_contains($m, 'does not match'));

        $brand->update(['verification_basis' => ['trade_licence', 'online_presence']]);
        $this->post(route('super.directory.replace', $listing), ['brand_id' => $brand->id, 'reason' => 'Trade licence matches'])->assertSessionHas('success');

        $listing->refresh();
        $this->assertSame('archived', $listing->status);
        $this->assertSame('replaced', $listing->archive_reason);
        $this->assertSame('member', $listing->membership_status);
        $this->assertSame($brand->id, $listing->replaced_by_brand_id);
        $this->get(route('directory.show', 'walton'))->assertRedirect($brand->profileUrl())->assertStatus(302);
        $this->get(route('brands.index'))->assertDontSee(route('directory.show', 'walton'), false);
        $this->assertTrue(PlatformAuditLog::where('action', 'directory.replaced')->where('subject_id', $listing->id)->exists());

        $this->post(route('super.directory.restore', $listing))->assertSessionHas('success');
        $listing->refresh();
        $this->assertSame('active', $listing->status);
        $this->assertNull($listing->replaced_by_brand_id);
        $this->assertTrue($listing->isListed());
        $this->assertSame(30, BrandDirectoryListing::count(), 'Nothing is ever deleted.');
    }

    public function test_rejecting_the_claiming_brand_releases_the_claim(): void
    {
        $this->importAll();
        $brand = $this->brand('Shohoz', ['status' => 'pending']);
        $this->directory()->recordClaim($brand);
        $this->assertSame('claim_pending', BrandDirectoryListing::where('slug', 'shohoz')->value('membership_status'));

        $this->actingAs($this->makePlatformAdmin(), 'super_admin')
            ->post(route('super.brands.status', $brand), ['action' => 'reject', 'reason' => 'Not the owner'])->assertRedirect();

        $listing = BrandDirectoryListing::where('slug', 'shohoz')->first();
        $this->assertSame('not_member', $listing->membership_status);
        $this->assertNull($listing->claimed_by_brand_id);
    }

    public function test_admin_directory_page_needs_the_super_admin_guard(): void
    {
        $this->importAll();

        $this->get(route('super.directory.index'))->assertRedirect();
        $this->actingAs($this->makePlatformAdmin(), 'super_admin')
            ->get(route('super.directory.index', ['filter' => 'needs_review']))->assertOk()->assertSee('Akij Group');
    }

    // --- expiry + category capacity ----------------------------------------------------------

    public function test_expired_listings_are_hidden_then_archived_not_deleted(): void
    {
        $this->importAll();
        $this->travelTo(now()->addMonths(7));

        $this->get(route('brands.index'))->assertOk()->assertDontSee('Well-known brands in Bangladesh');
        $this->get(route('directory.show', 'aarong'))->assertNotFound();

        $this->artisan('platform:directory-maintain')->assertSuccessful();
        $this->assertSame(0, BrandDirectoryListing::where('status', 'archived')->count(), 'Dry run changes nothing.');

        $this->artisan('platform:directory-maintain', ['--apply' => true])->assertSuccessful();
        $this->assertSame(30, BrandDirectoryListing::where('status', 'archived')->where('archive_reason', 'expired')->count());
        $this->assertSame(30, BrandDirectoryListing::count());
    }

    public function test_a_newer_source_check_renews_expired_listings_but_not_admin_archives(): void
    {
        $this->importAll();
        $this->directory()->archive(BrandDirectoryListing::where('slug', 'apex')->first(), 'manual', 'Owner asked');
        $this->travelTo(now()->addMonths(7));
        $this->artisan('platform:directory-maintain', ['--apply' => true])->assertSuccessful();

        // Re-importing the same (old) check renews nothing.
        $this->importAll();
        $this->assertSame(0, BrandDirectoryListing::where('status', 'active')->count());

        $data = $this->directory()->load();
        $data['checked_at'] = now()->toDateString();
        $this->directory()->import($data, false);
        $this->assertSame(0, BrandDirectoryListing::where('status', 'active')->count(), 'Dry run renews nothing.');

        $report = collect($this->directory()->import($data, true))->keyBy(1);
        $this->assertSame('renewed', $report['aarong'][0]);
        $this->assertTrue(BrandDirectoryListing::where('slug', 'aarong')->first()->isListed());
        $this->assertSame('archived', BrandDirectoryListing::where('slug', 'apex')->value('status'), 'A manual archive stays archived.');
        $this->assertTrue(PlatformAuditLog::where('action', 'directory.renewed')->exists());
    }

    public function test_category_capacity_archives_only_that_category_highest_priority_first(): void
    {
        $this->importAll();
        $telecom = BrandCategory::where('slug', 'telecommunications-networking')->value('id');
        foreach (['Telco One', 'Telco Two'] as $name) {
            $this->brand($name, ['brand_category_id' => $telecom, 'is_verified' => true]);
        }
        $this->brand('New Unverified Shop', ['brand_category_id' => BrandCategory::where('slug', 'clothing-fashion')->value('id')]);

        // Capacity 4 with 2 verified brands → telecom keeps 2 of its 3 listings.
        $this->artisan('platform:directory-maintain', ['--apply' => true, '--category-cap' => 4])->assertSuccessful();

        $this->assertSame(2, BrandDirectoryListing::where('brand_category_id', $telecom)->where('status', 'active')->count());
        $this->assertSame(1, BrandDirectoryListing::where('brand_category_id', $telecom)->where('archive_reason', 'category_full')->count());
        $this->assertSame(0, BrandDirectoryListing::where('status', 'archived')->where('brand_category_id', '!=', $telecom)->count(),
            'Unverified registrations and other categories never remove listings.');
    }
}
