<?php

namespace App\Http\Controllers;

use App\Support\Home\Showcase;
use Illuminate\Http\Request;

/**
 * metasoftbd.com homepage — the Entrepreneur & Brand Recognition Platform.
 * The Business Automation marketing page that used to live here is now
 * served unchanged by LandingController at /automation.
 */
class HomeController extends Controller
{
    public function index(Request $request)
    {
        $q = mb_substr(trim((string) $request->query('q', '')), 0, 80);
        $browse = $request->query('browse');

        // ?browse=brands|entrepreneurs lists everything (the "Browse all"
        // links) until the dedicated /brands and /entrepreneurs pages exist.
        if ($browse === 'brands') {
            $results = ['brands' => array_values(Showcase::brands()), 'entrepreneurs' => []];
            $resultsTitle = 'All brands';
        } elseif ($browse === 'entrepreneurs') {
            $results = ['brands' => [], 'entrepreneurs' => Showcase::entrepreneurs()];
            $resultsTitle = 'All entrepreneurs';
        } else {
            $results = $q !== '' ? Showcase::search($q) : null;
            $resultsTitle = '“'.$q.'”';
        }

        return view('central.home', [
            'q' => $q,
            'results' => $results,
            'resultsTitle' => $resultsTitle,
            'browsing' => in_array($browse, ['brands', 'entrepreneurs'], true),
            'preview' => (bool) config('platform.showcase_preview'),
            'stats' => Showcase::stats(),
            'heroBrand' => Showcase::brand('nakshi-ghor'),
            'award' => Showcase::featuredAward(),
            'otherAwards' => Showcase::otherAwards(),
            'divisions' => Showcase::divisions(),
            'voting' => Showcase::votingCategories(),
            'trending' => Showcase::trending(),
            'featured' => Showcase::featuredBrands(),
            'categories' => Showcase::categories(),
            'entrepreneurs' => Showcase::entrepreneurs(),
            'recognition' => Showcase::recognitionTypes(),
            'labels' => Showcase::labels(),
            'stories' => Showcase::stories(),
            'events' => Showcase::events(),
            'automation' => Showcase::automationFeatures(),
            'sponsorTiers' => Showcase::sponsorTiers(),
            'profiles' => Showcase::profilesForClient(),
            'whatsapp' => preg_replace('/\D/', '', (string) config('payment.support_whatsapp')),
        ]);
    }
}
