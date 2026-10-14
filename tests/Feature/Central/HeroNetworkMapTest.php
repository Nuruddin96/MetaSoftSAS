<?php

namespace Tests\Feature\Central;

use App\Models\PlatformSetting;
use App\Support\Home\BangladeshNetwork;
use App\Support\Platform\PlatformSchema;
use Tests\TestCase;

/**
 * Homepage hero glass map (x-plat.bd-network + App\Support\Home\BangladeshNetwork):
 * accurate pointer placement, one incoming route per pointer, and a red
 * flash timeline that starts exactly when each route's pulse arrives.
 */
class HeroNetworkMapTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        PlatformSchema::flush();
        PlatformSetting::flush();
    }

    public function test_hero_shows_the_glass_map_instead_of_the_brand_cards(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('building Bangladesh.')
            ->assertSee('data-bd-network', false)
            ->assertSee('id="heroSearch"', false)
            ->assertSee('One Nation. One Stage.')
            ->assertSee('64 districts · one network')
            ->assertSee('Route (illustrative)')
            ->assertDontSee('View profiles');
    }

    public function test_every_district_except_dhaka_has_exactly_one_incoming_route(): void
    {
        $districts = array_keys(BangladeshNetwork::DISTRICTS);
        $destinations = array_keys(BangladeshNetwork::PARENT);

        sort($destinations);
        $expected = array_values(array_diff($districts, [BangladeshNetwork::HUB]));
        sort($expected);
        $this->assertSame($expected, $destinations);

        foreach (BangladeshNetwork::PARENT as $to => $from) {
            $this->assertArrayHasKey($from, BangladeshNetwork::DISTRICTS, "$to's route starts at an unknown district.");
        }

        $nodes = collect(BangladeshNetwork::data()['nodes'])->keyBy('name');
        $this->assertNull($nodes[BangladeshNetwork::HUB]['arrive'], 'Dhaka is the origin and never flashes.');
        $this->assertSame(count($destinations), $nodes->whereNotNull('arrive')->count());
    }

    public function test_every_district_pointer_lies_inside_the_bangladesh_outline(): void
    {
        $net = BangladeshNetwork::data();
        $rings = collect(explode('Z', $net['path']))->filter()->map(
            fn (string $ring) => array_map(fn (string $p) => array_map('floatval', explode(' ', $p)), explode('L', ltrim($ring, 'M')))
        );

        foreach ($net['nodes'] as $node) {
            $inside = false;
            foreach ($rings as $ring) {
                for ($i = 0, $j = count($ring) - 1; $i < count($ring); $j = $i++) {
                    [$xi, $yi] = $ring[$i];
                    [$xj, $yj] = $ring[$j];
                    if (($yi > $node['y']) !== ($yj > $node['y']) && $node['x'] < ($xj - $xi) * ($node['y'] - $yi) / ($yj - $yi) + $xi) {
                        $inside = ! $inside;
                    }
                }
            }
            $this->assertTrue($inside, "{$node['name']} is drawn outside the map outline.");
        }
    }

    public function test_pointer_flash_starts_when_its_pulse_arrives_and_onward_routes_follow(): void
    {
        $routes = collect(BangladeshNetwork::data()['routes'])->keyBy('to');

        foreach ($routes as $to => $r) {
            $this->assertEqualsWithDelta($r['start'] + $r['travel'] * BangladeshNetwork::HEAD, $r['arrive'], 0.001, "$to arrival is off its pulse.");
            // The pulse plus the ~1s double flash must fit inside one cycle, so nothing overlaps the next arrival.
            $this->assertLessThan(BangladeshNetwork::CYCLE, $r['travel'] + 1.0, "$to's route is too slow for the cycle.");

            if ($r['from'] !== BangladeshNetwork::HUB) {
                $parent = $routes[$r['from']];
                $this->assertEqualsWithDelta($parent['arrive'] + BangladeshNetwork::HANDOFF, $r['start'], 0.01, "$to should leave when the {$r['from']} pulse arrives.");
            }
        }
    }

    public function test_rendered_animation_uses_the_computed_timeline(): void
    {
        $html = $this->get('/')->getContent();
        $net = BangladeshNetwork::data();

        foreach ($net['routes'] as $r) {
            $this->assertStringContainsString("@keyframes bd-pk{$r['i']}{0%{stroke-dashoffset:.12}{$r['travel_pct']}%,100%{stroke-dashoffset:-1}}", $html);
            $this->assertStringContainsString("animation:bd-pk{$r['i']} {$net['cycle']}s linear {$r['start']}s infinite", $html);
            $this->assertStringContainsString("--bd-cycle:{$net['cycle']}s;--bd-arrive:{$r['arrive']}s", $html);
        }
    }
}
