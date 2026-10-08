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
            $home->assertSee($name)->assertSee('images/showcase/logos/'.$logo, false)->assertSee('images/showcase/covers/'.$cover, false);
        }
        // Previous demo brands and their generated artwork are gone.
        foreach (['Nakshi Ghor', 'Krishi Bondhu', 'Jamdani House', 'PayDesh', 'Tanvir Ahmed'] as $old) {
            $home->assertDontSee($old);
        }
        $this->assertDoesNotMatchRegularExpression('#images/showcase/[a-z]+/[^"\']+\.svg#', $home->getContent());
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
