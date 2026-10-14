<?php

namespace Tests\Feature\Platform;

use App\Models\Award;
use App\Models\AwardNomination;
use App\Models\Brand;
use App\Models\BrandCategory;
use App\Models\BrandOwner;
use App\Models\PlatformSetting;
use App\Models\VoteCampaign;
use App\Models\VoteEntry;
use App\Support\Home\Showcase;
use App\Support\Platform\HomepageContent;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithPlatformSchema;
use Tests\TestCase;

/**
 * Award programme naming, the official verified badge, share/vote URLs,
 * one-phone-one-vote per programme, the admin nomination flow, homepage
 * real-brands-first logic and Super Admin → Homepage.
 */
class AwardsHomepageTest extends TestCase
{
    use InteractsWithPlatformSchema;

    private const AWARD = 'Bangladesh Brand & Entrepreneur Awards 2026';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPlatformSchema();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->withoutVite();
    }

    private function brand(string $name, array $attrs = []): Brand
    {
        static $n = 0;
        $n++;
        $phone = '0181'.str_pad((string) $n, 7, '0', STR_PAD_LEFT);
        $owner = BrandOwner::create(['name' => "Owner {$n}", 'email' => "o{$n}@example.com", 'phone' => $phone, 'password' => 'secret123']);
        $category = BrandCategory::first() ?? $this->makePlatformCategory();
        $b = Brand::create(array_merge([
            'brand_owner_id' => $owner->id, 'name' => $name, 'brand_category_id' => $category->id, 'founder_name' => "Owner {$n}",
            'phone' => $phone, 'email' => "o{$n}@example.com", 'division' => 'Dhaka', 'district' => 'Dhaka', 'status' => 'approved', 'approved_at' => now(),
        ], $attrs));
        if ($b->status === 'approved') {
            $b->assignSlug();
            $b->save();
        }

        return $b;
    }

    private function admin()
    {
        return $this->actingAs($this->makePlatformAdmin(), 'super_admin');
    }

    // --- 1. award name ----------------------------------------------------------------------

    public function test_public_award_name_is_bangladesh_brand_and_entrepreneur_awards(): void
    {
        $this->assertSame(self::AWARD, config('platform.award_name'));
        $brand = $this->brand('Shop Basket');

        $home = $this->get('/')->assertOk();
        $home->assertSee(self::AWARD)->assertDontSee('MetaSoft BD Awards')->assertDontSee('METASOFT BD AWARD');

        $this->actingAs($brand->owner, 'brand_owner')->get(route('owner.voting'))
            ->assertSee('data-campaign="'.e(self::AWARD).'"', false);
    }

    // --- 2. badge ---------------------------------------------------------------------------

    public function test_verified_badge_uses_the_supplied_asset(): void
    {
        $path = public_path('images/badges/metasoft-verified.png');
        $this->assertFileExists($path);
        $img = imagecreatefrompng($path);
        $this->assertSame(127, (imagecolorat($img, 0, 0) >> 24) & 127, 'Badge backdrop must be transparent.');
        $centre = imagecolorat($img, 64, 64);
        $this->assertSame(0, ($centre >> 24) & 127, 'Badge artwork must be opaque.');

        $verified = $this->brand('Verified Co', ['is_verified' => true]);
        // Different category, so the verified brand isn't listed under "More … brands" on its page.
        $plain = $this->brand('Plain Co', ['brand_category_id' => $this->makePlatformCategory('Other')->id]);
        $this->get(route('brands.show', $verified->slug))->assertSee('images/badges/metasoft-verified.png', false);
        $this->get(route('brands.show', $plain->slug))->assertDontSee('images/badges/metasoft-verified.png', false);
    }

    public function test_badge_follows_admin_approval_on_cards_and_profile_pages(): void
    {
        $approved = $this->brand('Approved Verified Co', ['is_verified' => true]);
        $pending = $this->brand('Pending Co', ['status' => 'pending', 'is_verified' => true]);
        $pending->update(['slug' => 'pending-co']);

        $grid = $this->get('/')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#metasoft-verified\.png"[^>]*alt="Verified brand"#', $grid);
        $this->assertStringNotContainsString('Pending Co', $grid);

        $this->get(route('brands.show', $approved->slug))->assertOk()->assertSee('alt="Verified brand"', false);
        // Not approved = not public, whatever its verification flag says.
        $this->get('/brand/pending-co')->assertNotFound();
    }

    public function test_voting_cues_need_a_campaign_the_server_confirms_is_open(): void
    {
        $brand = $this->brand('Shop Basket');
        $award = Award::create(['title' => self::AWARD, 'slug' => 'bbea-2026', 'year' => 2026, 'status' => 'voting', 'is_featured' => true]);
        $this->campaign($award, 'program', [$brand], 'Peoples Choice');
        $campaign = VoteCampaign::firstOrFail();
        $campaign->update(['ends_at' => now()->addDays(3)]);

        $this->assertTrue(app(HomepageContent::class)->votingOpen());
        $this->get('/')->assertOk()
            ->assertSee('Vote now')->assertSee('>LIVE<', false)->assertSee('data-countdown', false)
            ->assertSee(route('vote.show', $brand->slug), false);

        foreach ([['status' => 'paused'], ['status' => 'ended'], ['starts_at' => now()->addDay()], ['ends_at' => now()->subMinute()]] as $state) {
            $campaign->update(array_merge(['status' => 'active', 'starts_at' => null, 'ends_at' => null], $state));
            $this->assertFalse(app(HomepageContent::class)->votingOpen(), json_encode($state));
            $this->get('/')->assertOk()
                ->assertDontSee('Vote now')->assertDontSee('>LIVE<', false)->assertDontSee('data-countdown', false)
                ->assertDontSee(route('vote.show', $brand->slug), false)
                ->assertSee('Voting not open');
        }
    }

    public function test_award_rule_figures_appear_only_when_configured(): void
    {
        $home = $this->get('/')->assertOk();
        foreach (['jury of 21', 'under 3 years', '40% vote', '60% jury'] as $claim) {
            $home->assertDontSee($claim);
        }

        config(['platform.award_rules' => ['jury_size' => 9, 'public_vote_weight' => 30, 'rising_brand_max_years' => 2]]);
        $this->get('/')->assertSee('Selected by a jury of 9.')->assertSee('30% vote · 70% jury')->assertSee('For brands under 2 years old.');
    }

    public function test_hero_figures_are_real_counts_only(): void
    {
        $this->brand('Counted One');
        $this->brand('Counted Two');
        $this->brand('Not Yet Approved', ['status' => 'pending']);

        $this->assertSame([['value' => '2', 'label' => 'Brand profiles', 'n' => 2]], app(HomepageContent::class)->stats());
        $this->get('/')->assertOk()->assertSee('Brand profiles')->assertDontSee('Votes counted')->assertDontSee('1.2M');
    }

    public function test_sample_brand_pages_yield_to_real_brands_and_the_sample_switch(): void
    {
        $this->get('/brand/ayat-fashion')->assertOk()->assertSee('Sample profile')->assertSee('noindex', false);
        $this->get('/brand/no-such-brand')->assertNotFound();

        $real = $this->brand('Ayat Fashion');
        $this->assertSame('ayat-fashion', $real->slug);
        $this->get('/brand/ayat-fashion')->assertOk()->assertDontSee('Sample profile')->assertDontSee('noindex', false);

        PlatformSetting::put('homepage', ['sample_fallback' => false] + HomepageContent::defaults());
        $this->get('/brand/girls-secret')->assertNotFound();
    }

    // --- 3. share card / vote URL -----------------------------------------------------------

    public function test_share_card_and_social_links_use_the_permanent_vote_url(): void
    {
        $brand = $this->brand('Shop Basket');
        $url = route('vote.show', $brand->slug);

        $page = $this->actingAs($brand->owner, 'brand_owner')->get(route('owner.voting'))->assertOk();
        $page->assertSee('data-url="'.$url.'"', false)
            ->assertSee('https://www.facebook.com/sharer/sharer.php?u='.rawurlencode($url), false)
            ->assertSee('data-nominee="0"', false)
            ->assertSee('can’t contain a clickable link', false);

        $vote = $this->get($url)->assertOk();
        $vote->assertSee('<meta property="og:url" content="'.$url.'">', false)
            ->assertSee('og:title" content="Vote for Shop Basket — '.e(self::AWARD), false);
    }

    // --- 4. one phone = one vote per programme ----------------------------------------------

    private function vote(Brand $brand, VoteEntry $entry, string $phone, string $device)
    {
        return $this->withCredentials()->withCookie('msbd_vd', $device)->postJson(route('vote.cast', $brand->slug), [
            'entry_id' => $entry->id, 'phone' => $phone, 'form_token' => encrypt(now()->subSeconds(10)->timestamp),
        ]);
    }

    private function campaign(?Award $award, string $limit, array $brands, string $title): array
    {
        $c = VoteCampaign::create(['award_id' => $award?->id, 'title' => $title, 'slug' => Str::slug($title), 'status' => 'active', 'vote_limit' => $limit, 'started_notified_at' => now()]);
        $entries = [];
        foreach ($brands as $i => $b) {
            $cat = $c->categories()->create(['name' => 'Category '.$i]);
            $entries[] = VoteEntry::create(['campaign_id' => $c->id, 'vote_category_id' => $cat->id, 'brand_id' => $b->id]);
        }

        return $entries;
    }

    public function test_one_phone_gets_one_vote_in_the_whole_programme(): void
    {
        $a = $this->brand('Alpha');
        $b = $this->brand('Beta');
        $c = $this->brand('Gamma');
        $award = Award::create(['title' => self::AWARD, 'slug' => 'bbea-2026', 'year' => 2026, 'status' => 'voting']);
        [$ea, $eb] = $this->campaign($award, 'program', [$a, $b], 'Peoples Choice');
        [$ec] = $this->campaign($award, 'program', [$c], 'Second round');

        $this->vote($a, $ea, '01911111111', 'dev-1')->assertOk();
        $this->vote($b, $eb, '01911111111', 'dev-2')->assertStatus(422)->assertJsonFragment(['ok' => false]);
        $this->vote($c, $ec, '+8801911111111', 'dev-3')->assertStatus(422);
        $this->assertSame([1, 0, 0], [$ea->fresh()->votes_count, $eb->fresh()->votes_count, $ec->fresh()->votes_count]);

        // A different number still votes; IP/device protections still apply.
        $this->vote($b, $eb, '01922222222', 'dev-4')->assertOk();
    }

    public function test_programme_rule_does_not_affect_other_programmes(): void
    {
        $a = $this->brand('Alpha');
        $other = $this->brand('Other');
        $award = Award::create(['title' => self::AWARD, 'slug' => 'bbea-2026', 'year' => 2026, 'status' => 'voting']);
        $future = Award::create(['title' => 'Future Awards 2027', 'slug' => 'future-2027', 'year' => 2027, 'status' => 'voting']);
        [$ea] = $this->campaign($award, 'program', [$a], 'Programme A');
        [$eo, $eo2] = $this->campaign($future, 'daily', [$other, $a], 'Programme B');

        $this->vote($a, $ea, '01911111111', 'dev-1')->assertOk();
        $this->vote($other, $eo, '01911111111', 'dev-2')->assertOk();
        $this->vote($a, $eo2, '01911111111', 'dev-2')->assertOk(); // daily: a different category is still allowed
    }

    // --- 5 + 9. nomination flow and award structure -----------------------------------------

    public function test_programme_setup_creates_25_categories_with_two_awards_each_and_programme_vote(): void
    {
        $this->admin()->post(route('super.awards.setup-program'))->assertRedirect();
        $award = Award::where('title', self::AWARD)->firstOrFail();

        $this->assertSame(25, $award->categories()->count());
        $names = $award->categories()->pluck('name')->all();
        $this->assertSame(config('platform.award_categories'), $names);
        $this->assertSame('Fashion & Apparel', $names[0]);
        $this->assertSame('Social Impact & Innovation', $names[24]);
        $this->assertNotContains('Entrepreneur of the Year', $names);
        $this->assertNotContains('Best Women-Led Business', $names);
        $this->assertSame('draft', $award->status);
        $campaign = $award->campaigns()->firstOrFail();
        $this->assertSame('program', $campaign->vote_limit);
        $this->assertSame(25, $campaign->categories()->count());

        $this->get(route('super.awards.show', $award))->assertOk()->assertSee('0 of 50 awards decided')->assertSee('People’s Choice')->assertSee('Jury Choice');

        $this->post(route('super.awards.setup-program'))->assertRedirect(route('super.awards.show', $award));
        $this->assertSame(1, Award::where('title', self::AWARD)->count(), 'Setup runs once.');
    }

    public function test_admin_selects_approved_brands_and_moves_them_to_finalist_and_voting(): void
    {
        $approved = $this->brand('Shop Basket', ['is_verified' => true]);
        $second = $this->brand('Second Brand');
        $pending = $this->brand('Pending Brand', ['status' => 'pending']);
        $this->admin()->post(route('super.awards.setup-program'));
        $award = Award::firstOrFail();
        $cat = $award->categories()->first();

        $this->assertSame(0, AwardNomination::count(), 'Approval/verification never nominates automatically.');
        $this->get(route('super.awards.show', $award))->assertSee('Shop Basket')->assertSee('Second Brand')->assertDontSee('Pending Brand');

        $this->post(route('super.awards.nominations.store', $award), ['award_category_id' => $cat->id, 'brand_ids' => [$approved->id, $second->id, $pending->id]])->assertSessionHas('success');
        $this->assertSame(['accepted', 'accepted'], AwardNomination::orderBy('id')->pluck('status')->all());
        $this->assertFalse(AwardNomination::where('brand_id', $pending->id)->exists());

        $ids = AwardNomination::pluck('id')->all();
        $this->post(route('super.awards.nominations.bulk', $award), ['ids' => $ids, 'status' => 'shortlisted'])->assertRedirect();
        $this->post(route('super.awards.nominations.bulk', $award), ['ids' => [$ids[0]], 'status' => 'finalist'])->assertRedirect();
        $this->assertSame(['finalist', 'shortlisted'], AwardNomination::orderBy('id')->pluck('status')->all());

        $campaign = $award->campaigns()->first();
        $this->post(route('super.campaigns.import', $campaign), ['statuses' => ['finalist']])->assertRedirect();
        $this->assertSame([$approved->id], VoteEntry::pluck('brand_id')->all());

        $this->actingAs($approved->owner, 'brand_owner')->get(route('owner.awards'))->assertSee('Finalist')->assertDontSee('No nominations yet');
    }

    public function test_category_eligibility_is_respected_and_categories_are_editable(): void
    {
        $brand = $this->brand('Shop Basket');
        $other = $this->makePlatformCategory('Food & Beverage');
        $this->admin()->post(route('super.awards.setup-program'));
        $cat = Award::firstOrFail()->categories()->first();

        $this->put(route('super.awards.categories.update', $cat), ['name' => 'Best Fashion Brand', 'brand_category_id' => $other->id])->assertRedirect();
        $this->assertSame('Best Fashion Brand', $cat->fresh()->name);

        $this->post(route('super.awards.nominations.store', $cat->award_id), ['award_category_id' => $cat->id, 'brand_ids' => [$brand->id]])->assertSessionHas('error');
        $this->assertSame(0, AwardNomination::count());
    }

    // --- 6 + 7. homepage: real approved brands first, sample fallback ----------------------

    public function test_approved_brand_appears_on_homepage_without_being_featured(): void
    {
        $this->brand('Shop Basket');
        $this->brand('Hidden Pending', ['status' => 'pending']);

        $this->get('/')->assertOk()->assertSee('Shop Basket')->assertDontSee('Hidden Pending')->assertSee('Our Brands')->assertDontSee('Featured brands');
    }

    public function test_featured_and_sponsored_rows_stay_separate_from_approval(): void
    {
        $this->brand('Editorial Pick', ['is_featured' => true]);
        $this->brand('Paid Placement', ['is_sponsored' => true]);
        $content = app(HomepageContent::class);

        $featured = collect($content->featured())->pluck('name', 'name');
        $this->assertTrue($featured->has('Editorial Pick'));
        $this->assertTrue($featured->has('Paid Placement'));
        $this->assertSame('sponsored', collect($content->featured())->firstWhere('name', 'Paid Placement')['tag']);
        $this->assertFalse(Brand::where('name', 'Paid Placement')->first()->is_verified);
    }

    public function test_real_brands_replace_sample_slots_and_samples_disappear_when_full(): void
    {
        $content = fn () => app(HomepageContent::class);

        $samples = count(Showcase::brands());
        $empty = $content()->discover();
        $this->assertCount($samples, $empty, 'Every showcase brand fills the row while there are no real brands.');
        $this->assertTrue(collect($empty)->every(fn ($b) => $b['sample'] ?? false));

        $this->brand('Real One');
        $one = $content()->discover();
        $this->assertSame('Real One', $one[0]['name']);
        $this->assertCount(min(8, $samples + 1), $one);
        $this->assertCount(min(7, $samples), array_filter($one, fn ($b) => $b['sample'] ?? false));

        foreach (range(2, 9) as $i) {
            $this->brand('Real '.$i);
        }
        $full = $content()->discover();
        $this->assertCount(0, array_filter($full, fn ($b) => $b['sample'] ?? false), 'Samples disappear once real brands fill the row.');

        PlatformSetting::put('homepage', ['sample_fallback' => false] + HomepageContent::defaults());
        $this->assertCount(0, array_filter($content()->featured(), fn ($b) => $b['sample'] ?? false));
    }

    public function test_real_brands_keep_their_own_assets_and_only_samples_get_demo_art(): void
    {
        $real = $this->brand('Shop Basket', ['logo_path' => 'brands/logos/shop-basket.jpg', 'gallery' => ['brands/gallery/1/front.jpg']]);
        $cards = collect(app(HomepageContent::class)->discover());

        $realCard = $cards->firstWhere('name', 'Shop Basket');
        $this->assertSame($real->logoUrl(), $realCard['logo']);
        $this->assertSame(asset('storage/brands/gallery/1/front.jpg'), $realCard['cover']);
        $this->assertStringNotContainsString('images/showcase', $realCard['logo'].$realCard['cover']);

        foreach ($cards->where('sample', true) as $sample) {
            $this->assertStringContainsString('images/showcase/logos/', $sample['logo']);
            $this->assertFileExists(public_path(ltrim(parse_url($sample['logo'], PHP_URL_PATH), '/')));
            $this->assertFileExists(public_path(ltrim(parse_url($sample['cover'], PHP_URL_PATH), '/')));
        }

        $this->get('/')->assertOk()->assertSee($real->logoUrl(), false)->assertSee('images/showcase/logos/', false);
    }

    public function test_homepage_voting_uses_real_campaign_nominees_with_vote_links(): void
    {
        $brand = $this->brand('Shop Basket');
        $award = Award::create(['title' => self::AWARD, 'slug' => 'bbea-2026', 'year' => 2026, 'status' => 'voting', 'is_featured' => true]);
        $this->campaign($award, 'program', [$brand], 'Peoples Choice');

        $this->get('/')->assertOk()
            ->assertSee(route('vote.show', $brand->slug), false)
            ->assertSee('One vote per mobile number in the entire '.e(self::AWARD), false);
    }

    public function test_our_brands_puts_featured_first_then_newest_and_shows_verified_seal_only_for_verified(): void
    {
        $this->brand('Plain Old', ['approved_at' => now()->subDays(3)]);
        $this->brand('Fresh One', ['approved_at' => now()]);
        $this->brand('Editor Pick', ['is_featured' => true, 'approved_at' => now()->subDays(9)]);
        $verified = $this->brand('Trusted Co', ['is_verified' => true, 'verified_at' => now(), 'approved_at' => now()->subDays(5)]);

        $names = array_column(app(HomepageContent::class)->ourBrands(), 'name');
        $this->assertSame(['Editor Pick', 'Fresh One', 'Plain Old', 'Trusted Co'], array_slice($names, 0, 4));

        $home = $this->get('/')->assertOk();
        $html = $home->getContent();
        // Our Brands comes right after the hero and links to the directory.
        $this->assertLessThan(strpos($html, 'id="vote-now"'), strpos($html, 'id="brands"'));
        $this->assertLessThan(strpos($html, 'id="brands"'), strpos($html, '<h1'));
        $home->assertSee('View All Brands')->assertSee(route('brands.index'), false)->assertSee(route('brands.show', $verified->slug), false);

        // Exactly one card seal: the verified brand's (the legend and other sections aside).
        $grid = Str::between($html, 'id="ourBrandsTitle"', 'View All Brands');
        $this->assertSame(1, substr_count($grid, 'images/badges/metasoft-verified.png'));
    }

    public function test_vote_entry_point_links_open_campaign_nominees_to_their_vote_pages(): void
    {
        $brand = $this->brand('Shop Basket');
        $award = Award::create(['title' => self::AWARD, 'slug' => 'bbea-2026', 'year' => 2026, 'status' => 'voting']);
        $this->campaign($award, 'program', [$brand], 'Peoples Choice');

        $this->get('/')->assertOk()
            ->assertSee('Vote for Your Favorite Brand')
            ->assertSee('data-vote-finder', false)
            ->assertSee('data-url="'.route('vote.show', $brand->slug).'"', false)
            ->assertSee(route('voting.campaign', 'peoples-choice'), false);
    }

    public function test_vote_entry_point_shows_upcoming_and_ended_states_without_a_vote_finder(): void
    {
        $brand = $this->brand('Shop Basket');
        [$entry] = $this->campaign(null, 'daily', [$brand], 'Later Round');
        $entry->campaign->update(['starts_at' => now()->addDays(5)]);

        $this->get('/')->assertOk()->assertSee('Starts soon')->assertSee('opens '.now()->addDays(5)->format('j M Y'))->assertDontSee('data-vote-finder', false);

        $entry->campaign->update(['starts_at' => now()->subDays(10), 'ends_at' => now()->subDay()]);
        $this->get('/')->assertOk()->assertSee('Voting ended')->assertDontSee('data-vote-finder', false);
    }

    public function test_directory_cards_show_only_logo_name_category_and_profile_link(): void
    {
        $brand = $this->brand('Shop Basket', ['description' => 'Secret description text', 'district' => 'Khulna', 'division' => 'Khulna']);

        $html = $this->get(route('brands.index'))->assertOk()->getContent();
        $grid = Str::between($html, '1 brand</p>', 'Own a Bangladeshi brand?');

        $this->assertStringContainsString('Shop Basket', $grid);
        $this->assertStringContainsString('View Profile', $grid);
        $this->assertStringContainsString(route('brands.show', $brand->slug), $grid);
        $this->assertStringContainsString(e($brand->category->name), $grid);
        foreach (['Secret description text', 'Khulna', 'metasoft-verified.png'] as $hidden) {
            $this->assertStringNotContainsString($hidden, $grid);
        }
    }

    // --- 8. homepage admin ----------------------------------------------------------------------

    public function test_super_admin_manages_homepage_content(): void
    {
        $award = Award::create(['title' => self::AWARD, 'slug' => 'bbea-2026', 'year' => 2026, 'status' => 'nominations_open']);
        $this->admin()->get(route('super.homepage.edit'))->assertOk()->assertSee('Newly listed brands — automatic')->assertSee('Featured brands — manual');

        $this->put(route('super.homepage.update'), [
            'announcement' => 'Nominations now open', 'hero_pill' => 'National awards 2026', 'hero_title' => 'Celebrate the makers', 'hero_highlight' => 'of Bangladesh.',
            'hero_sub' => 'Real brands, real votes.', 'award_id' => $award->id, 'sample_fallback' => '1', 'show_stories' => '1', 'show_sponsors' => '1',
        ])->assertSessionHas('success');

        $home = $this->get('/')->assertOk();
        $home->assertSee('Nominations now open')->assertSee('Celebrate the makers')->assertSee('of Bangladesh.')
            ->assertSee(route('awards.show', $award->slug), false)
            ->assertDontSee('id="events"', false)->assertDontSee('href="#events"', false)
            ->assertSee('id="stories"', false);
    }

    public function test_brand_owner_cannot_reach_homepage_or_award_admin(): void
    {
        $brand = $this->brand('Shop Basket');
        $this->actingAs($brand->owner, 'brand_owner');

        $this->get(route('super.homepage.edit'))->assertRedirect();
        $this->put(route('super.homepage.update'), ['announcement' => 'hijack'])->assertRedirect();
        $this->post(route('super.awards.setup-program'))->assertRedirect();
        $this->assertSame([], PlatformSetting::get('homepage'));
        $this->assertSame(0, Award::count());
    }
}
