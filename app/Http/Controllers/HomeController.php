<?php

namespace App\Http\Controllers;

use App\Support\Home\Showcase;
use App\Support\Platform\HomepageContent;
use Illuminate\Http\Request;

/**
 * metasoftbd.com homepage — the Entrepreneur & Brand Recognition Platform.
 * The Business Automation marketing page that used to live here is now
 * served unchanged by LandingController at /automation.
 *
 * Brands, the featured award and live voting come from real platform data
 * first (App\Support\Platform\HomepageContent); App\Support\Home\Showcase
 * sample content only fills what real data can't fill yet. Sections that
 * have no real data source (stories, events, sponsors…) stay sample
 * content and can be hidden in Super Admin → Homepage.
 */
class HomeController extends Controller
{
    public function index(Request $request, HomepageContent $content)
    {
        $q = mb_substr(trim((string) $request->query('q', '')), 0, 80);
        $browse = $request->query('browse');

        // ?browse=brands|entrepreneurs lists everything (the "Browse all" links).
        if ($browse === 'brands') {
            $results = ['brands' => $content->allBrands(), 'entrepreneurs' => []];
            $resultsTitle = 'All brands';
        } elseif ($browse === 'entrepreneurs') {
            $results = ['brands' => [], 'entrepreneurs' => Showcase::entrepreneurs()];
            $resultsTitle = 'All entrepreneurs';
        } else {
            $results = $q !== '' ? $content->search($q) : null;
            $resultsTitle = '“'.$q.'”';
        }

        [$voting, $votingNote] = $content->voting();

        return view('central.home', [
            'q' => $q,
            'results' => $results,
            'resultsTitle' => $resultsTitle,
            'browsing' => in_array($browse, ['brands', 'entrepreneurs'], true),
            'preview' => (bool) config('platform.showcase_preview'),
            'home' => $content->settings(),
            'stats' => Showcase::stats(),
            'heroBrand' => Showcase::brand('nakshi-ghor'),
            'heroRising' => Showcase::votingCategories()['rising'],
            'award' => $content->award(),
            'otherAwards' => Showcase::otherAwards(),
            'divisions' => Showcase::divisions(),
            'voting' => $voting,
            'votingNote' => $votingNote,
            'trending' => Showcase::trending(),
            'featured' => $content->featured(),
            'discover' => $content->discover(),
            'realBrands' => $content->realBrandCount(),
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
