<?php

namespace App\Support\Home;

/**
 * Geometry and animation timeline for the homepage hero's glass map
 * (x-plat.bd-network): the Bangladesh outline, district pointers and the
 * routes that carry a light pulse from Dhaka outwards.
 *
 * The outline is resources/data/bangladesh-outline.json (Natural Earth
 * 1:10m, already projected into a 600-wide SVG box). District headquarters
 * are projected here with the same constants, so pointers sit exactly
 * where they belong on the map.
 *
 * Timeline: everything repeats on one CYCLE-second clock so routes and
 * pointers never drift apart. Each district has exactly one incoming route
 * (PARENT), so each pointer has one unambiguous arrival per cycle; the
 * pointer's double red flash (app.css `bd-flash`) starts at that arrival.
 * Onward routes leave HANDOFF seconds after their parent pulse arrives,
 * so arrivals ripple out across the country. Routes are illustrative,
 * not trade data — the hero labels them as such.
 */
class BangladeshNetwork
{
    public const HUB = 'Dhaka';

    /** Seconds; every route and pointer animation repeats on this clock. */
    public const CYCLE = 7;

    /** The pulse is a 0.12-long dash on a pathLength=1 route, so its head reaches the end at 1/1.12 of the travel. */
    public const HEAD = 1 / 1.12;

    /** Seconds between a pulse arriving and the onward route leaving. */
    public const HANDOFF = 0.15;

    /** District headquarters, [longitude, latitude]. */
    public const DISTRICTS = [
        'Dhaka' => [90.41, 23.81], 'Chattogram' => [91.78, 22.36], 'Khulna' => [89.54, 22.85], 'Rajshahi' => [88.60, 24.37],
        'Sylhet' => [91.87, 24.90], 'Barishal' => [90.37, 22.70], 'Rangpur' => [89.25, 25.74], 'Mymensingh' => [90.41, 24.75],
        'Cox\'s Bazar' => [92.01, 21.43], 'Cumilla' => [91.18, 23.46], 'Bogura' => [89.37, 24.85], 'Jashore' => [89.21, 23.17],
        'Dinajpur' => [88.64, 25.63], 'Tangail' => [89.92, 24.25], 'Noakhali' => [91.10, 22.87], 'Kushtia' => [89.12, 23.90],
        'Faridpur' => [89.84, 23.61], 'Rangamati' => [92.17, 22.65], 'Patuakhali' => [90.33, 22.35], 'Satkhira' => [89.07, 22.72],
        'Sunamganj' => [91.40, 25.07], 'Kishoreganj' => [90.78, 24.43], 'Thakurgaon' => [88.46, 26.03], 'Kurigram' => [89.64, 25.81],
        'Pabna' => [89.24, 24.0], 'Moulvibazar' => [91.77, 24.48], 'Feni' => [91.40, 23.02], 'Bandarban' => [92.22, 22.19],
    ];

    /** Destination => origin. Every district except the hub appears exactly once. */
    public const PARENT = [
        'Chattogram' => 'Dhaka', 'Sylhet' => 'Dhaka', 'Rajshahi' => 'Dhaka', 'Khulna' => 'Dhaka', 'Barishal' => 'Dhaka', 'Mymensingh' => 'Dhaka',
        'Cumilla' => 'Dhaka', 'Bogura' => 'Dhaka', 'Jashore' => 'Dhaka', 'Faridpur' => 'Dhaka', 'Tangail' => 'Dhaka', 'Kishoreganj' => 'Dhaka',
        'Rangpur' => 'Bogura', 'Dinajpur' => 'Rangpur', 'Thakurgaon' => 'Dinajpur', 'Kurigram' => 'Rangpur', 'Pabna' => 'Rajshahi', 'Kushtia' => 'Pabna',
        'Satkhira' => 'Khulna', 'Patuakhali' => 'Barishal', 'Cox\'s Bazar' => 'Chattogram', 'Rangamati' => 'Chattogram', 'Bandarban' => 'Rangamati',
        'Feni' => 'Cumilla', 'Noakhali' => 'Feni', 'Sunamganj' => 'Sylhet', 'Moulvibazar' => 'Sylhet',
    ];

    /** Districts whose name is printed next to the pointer. */
    public const LABELS = ['Dhaka', 'Chattogram', 'Sylhet', 'Rajshahi', 'Khulna', 'Rangpur', 'Barishal', 'Mymensingh', 'Cox\'s Bazar'];

    private static ?array $data = null;

    /**
     * @return array{width:int, height:int, path:string, cycle:int,
     *               nodes:list<array{name:string, x:float, y:float, hub:bool, label:bool, arrive:?float}>,
     *               routes:list<array{i:int, from:string, to:string, d:string, start:float, travel:float, arrive:float, travel_pct:float}>}
     */
    public static function data(): array
    {
        if (self::$data !== null) {
            return self::$data;
        }

        $outline = json_decode(file_get_contents(resource_path('data/bangladesh-outline.json')), true, flags: JSON_THROW_ON_ERROR);
        $points = array_map(fn (array $ll) => self::project($ll, $outline['projection']), self::DISTRICTS);

        $routes = [];
        $i = 0;
        foreach (self::PARENT as $to => $from) {
            $routes[$to] = ['i' => $i, 'from' => $from, 'to' => $to] + self::curve($points[$from], $points[$to], $i);
            $i++;
        }

        $hubRoutes = array_keys(array_filter($routes, fn (array $r) => $r['from'] === self::HUB));
        foreach (array_keys($routes) as $to) {
            self::schedule($routes, $to, $hubRoutes);
        }

        $nodes = [];
        foreach ($points as $name => [$x, $y]) {
            $nodes[] = [
                'name' => $name, 'x' => $x, 'y' => $y,
                'hub' => $name === self::HUB,
                'label' => in_array($name, self::LABELS, true),
                'arrive' => $routes[$name]['arrive'] ?? null,
            ];
        }

        return self::$data = [
            'width' => $outline['width'],
            'height' => $outline['height'],
            'path' => $outline['path'],
            'cycle' => self::CYCLE,
            'nodes' => $nodes,
            'routes' => array_values(array_map(fn (array $r) => array_diff_key($r, ['len' => 0]), $routes)),
        ];
    }

    /** Same equirectangular projection the outline file was built with. */
    private static function project(array $lonLat, array $p): array
    {
        [$lon, $lat] = $lonLat;

        return [
            round($p['pad'] + ($lon - $p['min_lon']) * $p['cos_lat'] * $p['scale'], 1),
            round($p['pad'] + ($p['max_lat'] - $lat) * $p['scale'], 1),
        ];
    }

    /** Quadratic arc, bowed alternately left/right and lifted a little, so routes read as floating above the glass. */
    private static function curve(array $a, array $b, int $i): array
    {
        [$x1, $y1] = $a;
        [$x2, $y2] = $b;
        $len = hypot($x2 - $x1, $y2 - $y1);
        $bow = $len * 0.2 * ($i % 2 ? 1 : -1);
        $cx = ($x1 + $x2) / 2 - ($y2 - $y1) / $len * $bow;
        $cy = ($y1 + $y2) / 2 + ($x2 - $x1) / $len * $bow - $len * 0.1;

        return ['d' => sprintf('M%s %sQ%.1f %.1f %s %s', $x1, $y1, $cx, $cy, $x2, $y2), 'len' => $len];
    }

    /** Hub routes start staggered across the first ~3s; every other route leaves when its parent's pulse arrives. */
    private static function schedule(array &$routes, string $to, array $hubRoutes): void
    {
        $r = &$routes[$to];
        if (isset($r['start'])) {
            return;
        }

        $r['travel'] = round(1.0 + $r['len'] / 240, 2);
        if ($r['from'] === self::HUB) {
            $index = array_search($to, $hubRoutes, true);
            $r['start'] = round(fmod($index * 0.618, 1) * 3.2, 2);
        } else {
            self::schedule($routes, $r['from'], $hubRoutes);
            $r['start'] = round($routes[$r['from']]['arrive'] + self::HANDOFF, 2);
        }
        $r['arrive'] = round($r['start'] + $r['travel'] * self::HEAD, 3);
        $r['travel_pct'] = round($r['travel'] / self::CYCLE * 100, 2);
    }
}
