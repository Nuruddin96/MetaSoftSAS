<?php

namespace Tests\Feature\Central;

use App\Models\PlatformSetting;
use App\Support\Home\Showcase;
use App\Support\Platform\PlatformSchema;
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
        foreach (['id="awards"', 'id="voting"', 'id="trending"', 'id="brands"', 'id="entrepreneurs"', 'id="recognition"', 'id="join"', 'id="stories"', 'id="events"', 'id="automation"', 'id="sponsors"'] as $anchor) {
            $response->assertSee($anchor, false);
        }
        $response->assertSee('building Bangladesh.');
    }

    public function test_homepage_links_to_business_automation_and_existing_auth(): void
    {
        $response = $this->get('/');

        $response->assertSee(route('automation'), false);
        $response->assertSee(route('central.login'), false);
        $response->assertSee('wa.me/', false);
    }

    public function test_sponsored_placement_is_labelled_and_never_carries_an_award_badge(): void
    {
        $sponsored = array_filter(Showcase::brands(), fn ($b) => $b['tag'] === 'sponsored');

        $this->assertNotEmpty($sponsored);
        foreach ($sponsored as $brand) {
            $this->assertSame([], $brand['badges'], "{$brand['name']} is a paid placement and must not show earned recognition.");
        }

        $this->get('/')->assertSee('Sponsored');
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
        $response = $this->get('/?q=Rajshahi');

        $response->assertOk();
        $response->assertSee('id="results"', false);
        $response->assertSee('Krishi Bondhu');
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
        $response->assertSee('Brahmaputra Bamboo');
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
