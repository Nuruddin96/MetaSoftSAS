<?php

namespace Tests\Feature\Central;

use App\Models\Brand;
use App\Models\PlatformSetting;
use App\Support\Home\Showcase;
use App\Support\Platform\HomepageContent;
use App\Support\Platform\PlatformSchema;
use Illuminate\Auth\SessionGuard;
use Tests\TestCase;

/**
 * metasoftbd.com homepage — the Entrepreneur & Brand Recognition Platform
 * (HomeController + App\Support\Home\Showcase). It renders sample content
 * with no database queries, so no tables are created here.
 */
class HomepageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // No platform tables here: the homepage must fall back to sample content.
        PlatformSchema::flush();
        PlatformSetting::flush();
    }

    public function test_homepage_renders_every_platform_section(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        foreach (['id="how"', 'id="awards"', 'id="voting"', 'id="standards"', 'id="verified"', 'id="trending"', 'id="brands"', 'id="entrepreneurs"', 'id="recognition"', 'id="join"', 'id="automation"', 'id="sponsors"'] as $anchor) {
            $response->assertSee($anchor, false);
        }
        $response->assertSee('building Bangladesh.');
    }

    public function test_stories_and_events_never_render_without_real_content(): void
    {
        // No stories/events data source exists, so even with their switches on nothing is shown or linked.
        $this->get('/')->assertOk()
            ->assertDontSee('id="stories"', false)->assertDontSee('id="events"', false)
            ->assertDontSee('href="#stories"', false)->assertDontSee('href="#events"', false)
            ->assertDontSee('Example story formats')->assertDontSee('Example event formats');
    }

    public function test_verified_badge_explainer_names_the_evidence_and_voting_is_not_called_verified(): void
    {
        $home = $this->get('/')->assertOk();

        $home->assertSee('The Verified badge')->assertSee('Each verified profile lists exactly what was reviewed', false);
        foreach (Brand::VERIFICATION_EVIDENCE as $label) {
            $home->assertSee($label);
        }
        // Voting copy must not overstate phone checks.
        $home->assertSee('not verified by SMS or OTP', false)->assertSee('Numbers are not OTP-verified')
            ->assertDontSee('audited for fraud')->assertDontSee('audited public voting')->assertDontSee('Audited votes');
        $home->assertSee('What Verified means')->assertSee(route('automation'), false)->assertSee(route('owner.register'), false);
    }

    public function test_sample_cards_are_labelled_and_sample_nominees_show_no_vote_counts(): void
    {
        $home = $this->get('/')->assertOk();

        $home->assertSee('>Sample</span>', false)->assertSee('Sample nominees')->assertDontSee('votes so far');
    }

    public function test_homepage_links_to_business_automation_and_existing_auth(): void
    {
        $response = $this->get('/');

        $response->assertSee(route('automation'), false);
        $response->assertSee(route('central.login'), false);
        $response->assertSee('wa.me/', false);
    }

    public function test_showcase_brands_carry_no_paid_placement_awards_or_verification_claims(): void
    {
        foreach (Showcase::brands() as $brand) {
            $this->assertNotSame('sponsored', $brand['tag'], "{$brand['name']} is a showcase brand, not a paid placement.");
            $this->assertSame([], $brand['badges'], "{$brand['name']} must not show invented recognition.");
            $this->assertFalse($brand['verified'], "{$brand['name']} must not claim MetaSoft BD verification.");
        }

        // The legend still explains what "Sponsored" means.
        $this->get('/')->assertSee('Sponsored');
    }

    public function test_the_six_supplied_showcase_brands_use_their_supplied_images(): void
    {
        $expected = [
            'Girls Secret' => ['brand1-logo.png.jpg', 'brand1-cover.jpg.png'],
            'Li Ummati' => ['brand2-logo.png.jpg', 'brand2-cover.jpg.jpg'],
            'Ayat Fashion' => ['brand3-logo.png.jpg', 'brand3-cover.jpg.jpg'],
            'Respite Care' => ['brand4-logo.png.jpg', 'brand4-cover.jpg.png'],
            'Ragdhanu Mart' => ['brand5-logo.png.jpg', 'brand5-cover.jpg.png'],
            'Sariha Art' => ['brand6-logo.png.jpg', 'brand6-cover.jpg.jpg'],
        ];
        $brands = collect(Showcase::brands())->keyBy('name');

        $this->assertSame(array_keys($expected), $brands->keys()->all());
        foreach ($expected as $name => [$logo, $cover]) {
            $this->assertSame(asset('images/showcase/logos/'.$logo), $brands[$name]['logo']);
            $this->assertSame(asset('images/showcase/covers/'.$cover), $brands[$name]['cover']);
            $this->assertFileExists(public_path('images/showcase/logos/'.$logo));
            $this->assertFileExists(public_path('images/showcase/covers/'.$cover));
        }

        $home = $this->get('/')->assertOk();
        foreach ($expected as $name => [$logo, $cover]) {
            // Logos on the cards, which link to the brand's own page; the cover is shown there.
            $slug = $brands[$name]['slug'];
            $home->assertSee($name)->assertSee('images/showcase/logos/'.$logo, false)->assertSee('href="/brand/'.$slug.'"', false);
            $this->get('/brand/'.$slug)->assertOk()->assertSee($name)->assertSee('images/showcase/covers/'.$cover, false);
        }
        // Previous demo brands and their generated artwork are gone.
        foreach (['Nakshi Ghor', 'Krishi Bondhu', 'Jamdani House', 'PayDesh', 'Tanvir Ahmed'] as $old) {
            $home->assertDontSee($old);
        }
        $this->assertDoesNotMatchRegularExpression('#images/showcase/[a-z]+/[^"\']+\.svg#', $home->getContent());
    }

    public function test_view_profile_opens_a_page_not_a_popup(): void
    {
        $home = $this->get('/')->assertOk();

        $home->assertDontSee('data-profile="brand:', false);
        $this->assertStringNotContainsString('"brand:', $home->getContent(), 'Brand profiles are no longer shipped to the popup.');
    }

    public function test_sample_brand_page_is_labelled_unverified_and_not_indexed(): void
    {
        $this->get('/brand/ayat-fashion')
            ->assertOk()
            ->assertSee('Sample profile')
            ->assertSee('noindex', false)
            ->assertSee('All brands')
            ->assertDontSee('images/badges/metasoft-verified.png', false);

        $this->get('/brand/no-such-brand')->assertStatus(503);

        // A leftover brand-owner session must not break the page while the platform tables are missing.
        $this->withSession(['login_brand_owner_'.sha1(SessionGuard::class) => 1])
            ->get('/brand/ayat-fashion')->assertOk()->assertSee('List your brand');
    }

    public function test_no_invented_statistics_are_shown(): void
    {
        $home = $this->get('/')->assertOk();

        foreach (['1.2M', '12,400+', '8,900+', 'Verified votes', '38 categories', '2,380', '▲', 'K views', ' going<', '186 nominees', '412 nominees'] as $made_up) {
            $home->assertDontSee($made_up, false);
        }
        // Without platform tables there are no real counts, so the hero figures are hidden.
        $this->assertSame([], app(HomepageContent::class)->stats());
        $home->assertSee('Not a ranking.');
    }

    public function test_no_voting_cues_or_unlabelled_demo_content_without_a_real_campaign(): void
    {
        $home = $this->get('/')->assertOk();

        foreach (['>Voting open<', 'Vote now', '>LIVE<', 'data-countdown', 'data-vote ', '30 Nov', '৳', 'Get tickets', 'Get passes', 'going<', 'Watch ·', 'Our Partners.'] as $cue) {
            $home->assertDontSee($cue, false);
        }
        $home->assertSee('Voting not open yet')
            ->assertSee('Example content')
            ->assertSee('No partners have been announced yet.')
            ->assertSee('Sample nominees');
        $this->assertFalse(app(HomepageContent::class)->votingOpen());
        $this->assertSame([], app(HomepageContent::class)->otherAwards('x'));
    }

    public function test_preview_notice_follows_config(): void
    {
        config(['platform.showcase_preview' => true]);
        $this->get('/')->assertSee('Preview · sample data');

        config(['platform.showcase_preview' => false]);
        $this->get('/')->assertDontSee('Preview · sample data');
    }

    public function test_search_filters_brands_by_district(): void
    {
        $response = $this->get('/?q=Moghbazar');

        $response->assertOk();
        $response->assertSee('id="results"', false);
        $response->assertSee('Ayat Fashion');
        $response->assertSee('Search results');
    }

    public function test_search_with_no_match_shows_empty_state(): void
    {
        $this->get('/?q=zzzz-no-such-brand')
            ->assertOk()
            ->assertSee('No brands or entrepreneurs match');
    }

    public function test_browse_all_brands_lists_the_directory(): void
    {
        $response = $this->get('/?browse=brands');

        $response->assertOk();
        $response->assertSee('All brands');
        $response->assertSee('Sariha Art')->assertSee('Respite Care');
    }

    public function test_search_query_is_escaped(): void
    {
        $this->get('/?q='.urlencode('<script>alert(1)</script>'))
            ->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_voting_percentages_add_up_per_category(): void
    {
        foreach (Showcase::votingCategories() as $cat) {
            $sum = array_sum(array_column($cat['nominees'], 'pct'));
            $this->assertEqualsWithDelta(100, $sum, 2, "{$cat['label']} vote shares should total ~100%.");
        }
    }
}
