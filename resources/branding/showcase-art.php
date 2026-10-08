<?php

/*
|--------------------------------------------------------------------------
| Homepage showcase (DEMO) brand artwork generator
|--------------------------------------------------------------------------
|
| Writes public/images/showcase/logos/{slug}.svg and covers/{slug}.svg for
| the sample brands in App\Support\Home\Showcase — original vector marks
| (one industry symbol per brand on its brand-colour tile) so the
| homepage's demo slots look finished. They depict no real business and
| are only ever used for Showcase sample content; real approved brands
| always show their own uploaded logo/images.
|
| Re-run after changing a sample brand:  php resources/branding/showcase-art.php
|
*/

use App\Support\Home\Showcase;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// 48×48 white marks. {c} = the brand's deep colour (details cut into the mark).
$motifs = [
    // Kantha embroidery flower inside a running-stitch ring
    'nakshi-ghor' => implode('', array_map(fn ($a) => '<ellipse cx="24" cy="11.5" rx="3.6" ry="7.2" fill="#fff" transform="rotate('.$a.' 24 24)"/>', range(0, 315, 45)))
        .'<circle cx="24" cy="24" r="4.4" fill="{c}"/><circle cx="24" cy="24" r="2.2" fill="#fff"/><circle cx="24" cy="24" r="22" fill="none" stroke="#fff" stroke-width="1.6" stroke-dasharray="2.6 2.8" stroke-linecap="round"/>',
    // Sprout with a signal arc (agritech)
    'krishi-bondhu' => '<path d="M24 44V22" stroke="#fff" stroke-width="3" stroke-linecap="round"/><path d="M24 31C14 31 8.5 24 8.5 15.5C17.5 15.5 24 21 24 31Z" fill="#fff"/><path d="M24 25C24 14.5 31 8 40.5 8C40.5 17.5 33.5 25 24 25Z" fill="#fff"/><path d="M12 44H36" stroke="#fff" stroke-width="3" stroke-linecap="round"/><path d="M34 31.5A7 7 0 0 1 41 38.5M34 26A12.5 12.5 0 0 1 46.5 38.5" fill="none" stroke="#fff" stroke-width="2.4" stroke-linecap="round" opacity=".8"/>',
    // Paint brush with colour drops (rickshaw-art décor)
    'rong-tuli' => '<path d="M43 5L27 21" stroke="#fff" stroke-width="6" stroke-linecap="round"/><path d="M27.5 20.5C31 24 29.5 32.5 21.5 37.5C14.5 41.8 5 42 5 42C5 42 5.5 33 9.5 27C14.5 19.5 23.5 16.5 27.5 20.5Z" fill="#fff"/><circle cx="37" cy="35" r="3.4" fill="#fff"/><circle cx="43.5" cy="27" r="2.2" fill="#fff"/><circle cx="41" cy="43" r="2.2" fill="#fff"/>',
    // Terracotta vase with a band
    'mati-ceramics' => '<path d="M18 5H30V9.5C30 11.5 28 12.5 28 14.5C35.5 17.5 40 24.5 40 31C40 39 33 44.5 24 44.5C15 44.5 8 39 8 31C8 24.5 12.5 17.5 20 14.5C20 12.5 18 11.5 18 9.5Z" fill="#fff"/><path d="M10.5 28.5H37.5M12 34.5H36" stroke="{c}" stroke-width="2" stroke-linecap="round"/><path d="M17 39.5L20 36.5 23 39.5 26 36.5 29 39.5 32 36.5" fill="none" stroke="{c}" stroke-width="1.6" stroke-linejoin="round"/>',
    // Recycle loop around a leaf (recycled textiles)
    'greencycle-bd' => '<path d="M24 6A18 18 0 0 1 41.6 20.2" fill="none" stroke="#fff" stroke-width="3.6" stroke-linecap="round"/><path d="M44.5 15.5L42.2 23.5 35.4 18.8Z" fill="#fff"/><path d="M24 42A18 18 0 0 1 6.4 27.8" fill="none" stroke="#fff" stroke-width="3.6" stroke-linecap="round"/><path d="M3.5 32.5L5.8 24.5 12.6 29.2Z" fill="#fff"/><path d="M24 32.5C16.5 32.5 14.5 25 17 17C25 17 29 22.5 24 32.5Z" fill="#fff"/><path d="M18.5 19.5L24 32" stroke="{c}" stroke-width="1.4"/>',
    // Woven jamdani lattice
    'jamdani-house' => '<path d="M24 4L44 24 24 44 4 24Z" fill="none" stroke="#fff" stroke-width="2.6" stroke-linejoin="round"/><path d="M24 13L35 24 24 35 13 24Z" fill="#fff"/><path d="M24 19L29 24 24 29 19 24Z" fill="{c}"/><circle cx="24" cy="8.5" r="2" fill="#fff"/><circle cx="39.5" cy="24" r="2" fill="#fff"/><circle cx="24" cy="39.5" r="2" fill="#fff"/><circle cx="8.5" cy="24" r="2" fill="#fff"/>',
    // Two leaves and a bud (tea)
    'sylhet-leaf-tea-co' => '<path d="M22 44C10 40.5 5.5 28.5 9.5 16C21.5 18.5 28 30.5 22 44Z" fill="#fff"/><path d="M26.5 40.5C26 28.5 32 20 42.5 18C44.5 30 38.5 38.5 26.5 40.5Z" fill="#fff"/><path d="M24 21C19.5 15 21.5 8.5 25.5 4C30 9 30 15.5 24 21Z" fill="#fff"/><path d="M11.5 19.5C17 26 20 33 21.5 42M40 21C35 26.5 30.5 32 27.5 39" fill="none" stroke="{c}" stroke-width="1.4" stroke-linecap="round"/>',
    // Payment card with a transfer arrow (fintech)
    'paydesh' => '<rect x="4" y="11" width="36" height="25" rx="5" fill="none" stroke="#fff" stroke-width="3"/><rect x="4" y="17" width="36" height="5" fill="#fff"/><rect x="9" y="27" width="11" height="3.2" rx="1.6" fill="#fff"/><circle cx="36.5" cy="34.5" r="9.5" fill="#fff"/><path d="M32 34.5H40.5M37.5 31L41 34.5 37.5 38" fill="none" stroke="{c}" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/>',
    // Honeycomb and a honey drop
    'sundarban-madhu' => (function () {
        $hex = function ($cx, $cy, $r) {
            $pts = [];
            foreach (range(0, 5) as $i) {
                $a = deg2rad(60 * $i - 30);
                $pts[] = round($cx + $r * cos($a), 2).' '.round($cy + $r * sin($a), 2);
            }

            return '<path d="M'.implode('L', $pts).'Z" fill="#fff" stroke="{c}" stroke-width="1.6"/>';
        };

        return $hex(15.5, 15, 8.6).$hex(32.5, 15, 8.6).$hex(24, 29.6, 8.6).'<path d="M24 37.5C26.6 41 28.2 43 28.2 44.6A4.2 4.2 0 0 1 19.8 44.6C19.8 43 21.4 41 24 37.5Z" fill="#fff"/>';
    })(),
    // Sunrise over the horizon (morning pitha)
    'bhorer-pitha' => '<path d="M9 31A15 15 0 0 1 39 31Z" fill="#fff"/><path d="M24 5V10M10.5 11.5L14 15M37.5 11.5L34 15M4 24H8.5M44 24H39.5" stroke="#fff" stroke-width="2.6" stroke-linecap="round"/><path d="M4 36.5H44M10 42.5H38" stroke="#fff" stroke-width="2.8" stroke-linecap="round"/>',
    // Open book with a play button (video lessons)
    'shikkhapath' => '<path d="M3.5 11.5C11.5 9.5 18.5 10.5 24 14.5V42C18.5 38 11.5 37 3.5 39Z" fill="#fff"/><path d="M44.5 11.5C36.5 9.5 29.5 10.5 24 14.5V42C29.5 38 36.5 37 44.5 39Z" fill="#fff"/><path d="M24 15V42" stroke="{c}" stroke-width="2"/><path d="M29.5 20.5L38 26 29.5 31.5Z" fill="{c}"/>',
    // Needle and a thread loop (streetwear)
    'dhaka-threads' => '<path d="M38 5L12 43" stroke="#fff" stroke-width="3.4" stroke-linecap="round"/><ellipse cx="35.3" cy="9" rx="1.2" ry="2.6" fill="{c}" transform="rotate(34 35.3 9)"/><path d="M35 10C14 9 5 22 18 27C31 32 41 33 34 41C30 45.5 22 45 16 43" fill="none" stroke="#fff" stroke-width="2.4" stroke-linecap="round"/>',
    // Leather bag with stitching
    'padma-leather' => '<path d="M15.5 18.5V13.5A8.5 8.5 0 0 1 32.5 13.5V18.5" fill="none" stroke="#fff" stroke-width="3.4" stroke-linecap="round"/><rect x="5" y="18" width="38" height="25" rx="5.5" fill="#fff"/><path d="M5 23H43L36.5 31H11.5Z" fill="{c}" opacity=".35"/><rect x="9" y="22" width="30" height="17" rx="3" fill="none" stroke="{c}" stroke-width="1.3" stroke-dasharray="2 2"/><rect x="21" y="27.5" width="6" height="5" rx="1.5" fill="{c}"/>',
    // Chilli with seeds (spices)
    'shonar-bangla-spices' => '<path d="M9.5 41C7.5 28 18 15.5 34.5 13.5C40.5 12.8 42.5 17 38.5 19.8C28 24 20.5 32.5 16.5 42.5C14.5 46.5 10.5 45.5 9.5 41Z" fill="#fff"/><path d="M34.5 13.5C35.5 8.5 39.5 5.5 44 5.5" fill="none" stroke="#fff" stroke-width="3" stroke-linecap="round"/><circle cx="17" cy="33" r="1.4" fill="{c}"/><circle cx="21.5" cy="27" r="1.4" fill="{c}"/><circle cx="27" cy="22" r="1.4" fill="{c}"/><circle cx="36" cy="38" r="2.4" fill="#fff"/><circle cx="42" cy="32" r="1.8" fill="#fff"/>',
    // Droplet with a sparkle (skincare)
    'nirvana-skincare' => '<path d="M24 3.5S38.5 20 38.5 30A14.5 14.5 0 0 1 9.5 30C9.5 20 24 3.5 24 3.5Z" fill="#fff"/><path d="M24 21.5L26 28 32.5 30 26 32 24 38.5 22 32 15.5 30 22 28Z" fill="{c}"/><path d="M39 6L40 9 43 10 40 11 39 14 38 11 35 10 38 9Z" fill="#fff"/>',
    // Flat-weave rug (shatranji)
    'rangpur-shatranji' => '<rect x="7" y="7" width="34" height="34" rx="2.5" fill="#fff"/><rect x="7" y="12" width="34" height="3.6" fill="{c}"/><rect x="7" y="32.4" width="34" height="3.6" fill="{c}"/><path d="M24 17.5L30.5 24 24 30.5 17.5 24Z" fill="{c}"/><path d="M24 21L27 24 24 27 21 24Z" fill="#fff"/>'
        .implode('', array_map(fn ($x) => '<path d="M'.$x.' 2.5V7M'.$x.' 41V45.5" stroke="#fff" stroke-width="1.6" stroke-linecap="round"/>', range(10, 38, 4))),
    // River fish over waves (seafood)
    'kirtonkhola-foods' => '<path d="M5 21C13 11 29.5 11 37.5 21C29.5 31 13 31 5 21Z" fill="#fff"/><path d="M37 21L46 12.5 43.8 21 46 29.5Z" fill="#fff"/><circle cx="12" cy="19.5" r="1.8" fill="{c}"/><path d="M17 15.5C19.5 18.5 19.5 23.5 17 26.5" fill="none" stroke="{c}" stroke-width="1.4" stroke-linecap="round"/><path d="M4 37Q10 33 16 37T28 37 40 37 46 35M4 43.5Q10 39.5 16 43.5T28 43.5 40 43.5" fill="none" stroke="#fff" stroke-width="2.6" stroke-linecap="round"/>',
    // Bamboo stalks with a leaf
    'brahmaputra-bamboo' => '<rect x="9" y="8" width="7.5" height="37" rx="3.6" fill="#fff"/><rect x="20.5" y="3" width="7.5" height="42" rx="3.6" fill="#fff"/><rect x="32" y="12" width="7.5" height="33" rx="3.6" fill="#fff"/><path d="M9 20H16.5M9 32H16.5M20.5 15H28M20.5 28H28M32 24H39.5M32 35H39.5" stroke="{c}" stroke-width="1.6"/><path d="M28.5 10C34 4.5 40 4 46 6C40.5 10 34.5 11.5 28.5 10Z" fill="#fff"/>',
    // Demo award programmes (homepage "other awards" cards)
    'award-women' => '<circle cx="24" cy="24" r="21" fill="none" stroke="#fff" stroke-width="2"/><path d="M24 9.5L28.3 18.6 38.3 19.8 30.9 26.7 32.9 36.6 24 31.6 15.1 36.6 17.1 26.7 9.7 19.8 19.7 18.6Z" fill="#fff"/>',
    'award-startup' => '<path d="M24 3.5C32.5 9.5 34.5 21.5 30.5 32H17.5C13.5 21.5 15.5 9.5 24 3.5Z" fill="#fff"/><circle cx="24" cy="17" r="3.8" fill="{c}"/><path d="M17.5 24.5L9.5 33 17.5 32ZM30.5 24.5L38.5 33 30.5 32Z" fill="#fff"/><path d="M19.5 34Q24 47 28.5 34Z" fill="#fff" opacity=".85"/>',
    'award-food' => '<path d="M5 23.5H43A19 19 0 0 1 5 23.5Z" fill="#fff"/><path d="M15 4.5C12 8 18 10.5 15 14.5M24 3C21 7 27 9.5 24 14M33 4.5C30 8 36 10.5 33 14.5" fill="none" stroke="#fff" stroke-width="2.4" stroke-linecap="round"/><path d="M12 43.5H36" stroke="#fff" stroke-width="3" stroke-linecap="round"/>',
];

$logo = fn (string $motif, string $from, string $to) => <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 96 96" width="96" height="96"><defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="{$from}"/><stop offset="1" stop-color="{$to}"/></linearGradient><radialGradient id="h" cx=".22" cy=".12" r=".95"><stop offset="0" stop-color="#fff" stop-opacity=".30"/><stop offset=".55" stop-color="#fff" stop-opacity="0"/></radialGradient></defs><rect width="96" height="96" rx="24" fill="url(#g)"/><rect width="96" height="96" rx="24" fill="url(#h)"/><rect x=".75" y=".75" width="94.5" height="94.5" rx="23.25" fill="none" stroke="#fff" stroke-opacity=".18" stroke-width="1.5"/><g transform="translate(20 20) scale(1.1667)">{$motif}</g></svg>
SVG;

$cover = fn (string $motif, string $from, string $to) => <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 640 256" width="640" height="256" preserveAspectRatio="xMidYMid slice"><defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="{$from}"/><stop offset="1" stop-color="{$to}"/></linearGradient><pattern id="p" width="72" height="72" patternUnits="userSpaceOnUse" patternTransform="rotate(-14)"><g transform="translate(18 18) scale(.75)" opacity=".11">{$motif}</g></pattern><radialGradient id="l" cx=".85" cy=".1" r=".75"><stop offset="0" stop-color="#fff" stop-opacity=".22"/><stop offset="1" stop-color="#fff" stop-opacity="0"/></radialGradient></defs><rect width="640" height="256" fill="url(#g)"/><rect width="640" height="256" fill="url(#p)"/><rect width="640" height="256" fill="url(#l)"/><circle cx="96" cy="300" r="150" fill="#000" opacity=".10"/><g transform="translate(420 6) scale(5)" opacity=".2">{$motif}</g></svg>
SVG;

$root = __DIR__.'/../../public/images/showcase';
@mkdir($root.'/logos', 0775, true);
@mkdir($root.'/covers', 0775, true);

$written = 0;
foreach (Showcase::brands() as $slug => $b) {
    $m = $motifs[$slug] ?? throw new RuntimeException("No motif for sample brand {$slug}");
    $m = str_replace('{c}', $b['to'], $m);
    file_put_contents("{$root}/logos/{$slug}.svg", $logo($m, $b['from'], $b['to']));
    file_put_contents("{$root}/covers/{$slug}.svg", $cover($m, $b['from'], $b['to']));
    $written += 2;
}
foreach (Showcase::otherAwards() as $a) {
    $key = $a['art'];
    file_put_contents("{$root}/logos/{$key}.svg", $logo(str_replace('{c}', $a['to'], $motifs[$key]), $a['from'], $a['to']));
    $written++;
}

echo "Wrote {$written} showcase SVGs to public/images/showcase\n";
