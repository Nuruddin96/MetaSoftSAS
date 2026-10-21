<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Support\Home\HomeMedia;
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
            $results = ['brands' => [], 'entrepreneurs' => $content->showsSamples() ? Showcase::entrepreneurs() : []];
            $resultsTitle = 'All entrepreneurs';
        } else {
            $results = $q !== '' ? $content->search($q) : null;
            $resultsTitle = '“'.$q.'”';
        }

        // Discovery listings (BrandDirectoryListing) matching the same search, shown as their own labelled group.
        $listingResults = match (true) {
            $browse === 'brands' => $content->searchListings(null),
            $results !== null && $browse !== 'entrepreneurs' => $content->searchListings($q),
            default => collect(),
        };

        [$voting, $votingNote] = $content->voting();
        $award = $content->award();

        return view('central.home', [
            'q' => $q,
            'results' => $results,
            'listingResults' => $listingResults,
            'resultsTitle' => $resultsTitle,
            'browsing' => in_array($browse, ['brands', 'entrepreneurs'], true),
            'preview' => (bool) config('platform.showcase_preview'),
            'home' => $content->settings(),
            'stats' => $content->stats(),
            'award' => $award,
            // Server-confirmed: a visible campaign in its open window with real nominees. Gates every "Voting open" / "Vote" cue.
            'votingOpen' => $content->votingOpen(),
            'otherAwards' => $content->otherAwards($award['title']),
            'voting' => $voting,
            'votingNote' => $votingNote,
            'trending' => Showcase::trending(),
            'ourBrands' => $content->ourBrands(),
            'directoryPicks' => $content->directoryPicks(),
            'voteEntry' => $content->voteEntry(),
            'realBrands' => $content->realBrandCount(),
            'categories' => $content->categories(),
            // Sample sections (trending, entrepreneurs) render only in sample mode, always labelled.
            'samples' => $content->showsSamples(),
            'verificationEvidence' => Brand::VERIFICATION_EVIDENCE,
            'entrepreneurs' => Showcase::entrepreneurs(),
            'recognition' => Showcase::recognitionTypes(),
            'labels' => Showcase::labels(),
            'stories' => Showcase::stories(),
            'events' => Showcase::events(),
            'automation' => Showcase::automationFeatures(),
            'sponsorTiers' => Showcase::sponsorTiers(),
            'profiles' => Showcase::profilesForClient(),
            // Real photos/documents for the Concept C sections (only files that exist).
            'media' => [
                'moments' => HomeMedia::moments(),
                'journey' => HomeMedia::programme(),
                'consultancy' => HomeMedia::consultancy(),
                'certificate' => HomeMedia::certificate(),
                'recipients' => HomeMedia::recipients(),
                'awards' => HomeMedia::awards(),
            ],
            'whatsapp' => preg_replace('/\D/', '', (string) config('payment.support_whatsapp')),
        ]);
    }
}
