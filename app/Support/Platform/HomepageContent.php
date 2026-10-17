<?php

namespace App\Support\Platform;

use App\Models\Award;
use App\Models\Brand;
use App\Models\BrandCategory;
use App\Models\PlatformSetting;
use App\Models\Vote;
use App\Models\VoteCampaign;
use App\Support\Home\Showcase;
use Illuminate\Support\Collection;

/**
 * Homepage data: real platform records first, App\Support\Home\Showcase
 * sample content only to fill the slots that real data can't fill yet.
 * As brands are approved they take over sample slots automatically, and
 * once there are enough real brands the samples simply stop appearing.
 *
 * Brand states stay separate:
 *   - featured(): Super Admin's editorial picks (is_featured) and paid
 *     placements (is_sponsored — always labelled "Sponsored")
 *   - discover(): every approved brand, newest first — no featuring or
 *     payment needed to be discoverable
 *   - nominees appear only in voting(), from a real voting campaign
 * Text and section switches come from Super Admin → Homepage
 * (platform_settings 'homepage'); everything degrades to the sample
 * showcase before database/sql/chunk64.sql + chunk65.sql are imported.
 */
class HomepageContent
{
    private ?array $settings = null;

    private ?Collection $published = null;

    private ?Collection $openCampaigns = null;

    public static function defaults(): array
    {
        return [
            // No dates or voting status here: the header adds "Vote now" only while a campaign is open.
            'announcement' => config('platform.award_name'),
            'hero_pill' => config('platform.award_name').' · '.count(config('platform.award_categories')).' categories',
            'hero_title' => 'Discover the brands & entrepreneurs',
            'hero_highlight' => 'building Bangladesh.',
            'hero_sub' => 'Discover trusted brands, meet founders and explore national awards.',
            'award_id' => null,
            'campaign_id' => null,
            'sample_fallback' => true,
            'show_stories' => true,
            'show_events' => true,
            'show_sponsors' => true,
        ];
    }

    public function settings(): array
    {
        return $this->settings ??= PlatformSchema::ready() ? PlatformSetting::get('homepage', self::defaults()) : self::defaults();
    }

    private function samples(): bool
    {
        return (bool) $this->settings()['sample_fallback'];
    }

    /** Approved, public brands (empty until the platform tables exist). */
    private function published(): Collection
    {
        return $this->published ??= PlatformSchema::ready()
            ? Brand::published()->with(Brand::CARD_RELATIONS)->latest('approved_at')->latest('id')->get()
            : collect();
    }

    /** Editorial picks + clearly labelled sponsored placements; sample featured brands fill empty slots. */
    public function featured(int $slots = 4): array
    {
        $real = $this->published()
            ->filter(fn (Brand $b) => $b->is_featured || $b->isSponsoredNow())
            ->sortBy(fn (Brand $b) => [$b->is_featured ? 0 : 1, $b->featured_order])
            ->take($slots)->map->toCard()->values()->all();

        return $this->fill($real, Showcase::featuredBrands(), $slots);
    }

    /** Every approved brand, newest first — the automatic discovery row. */
    public function discover(int $slots = 8): array
    {
        $real = $this->published()->take($slots)->map->toCard()->values()->all();
        // Samples not already in the Featured row come first, then the rest, so the row stays full.
        $featuredSamples = array_column(Showcase::featuredBrands(), 'slug');
        $samples = collect(Showcase::brands())->sortBy(fn ($b) => in_array($b['slug'], $featuredSamples, true) ? 1 : 0)->values()->all();

        return $this->fill($real, $samples, $slots);
    }

    /**
     * "Our Brands" (first section after the hero): up to four rows of the
     * 6-column desktop grid. Keeps the featured() order on top — editorial
     * picks, then sponsored placements — followed by every other approved
     * brand newest first, so no brand needs featuring to appear. Samples
     * fill empty slots only while sample_fallback is on.
     */
    public function ourBrands(int $slots = 24): array
    {
        $picked = fn (Brand $b) => $b->is_featured || $b->isSponsoredNow();
        $real = $this->published()->filter($picked)
            ->sortBy(fn (Brand $b) => [$b->is_featured ? 0 : 1, $b->featured_order])
            ->concat($this->published()->reject($picked))
            ->take($slots)->map->toCard()->values()->all();

        $featuredSamples = array_column(Showcase::featuredBrands(), 'slug');
        $samples = collect(Showcase::brands())->sortBy(fn ($b) => in_array($b['slug'], $featuredSamples, true) ? 0 : 1)->values()->all();

        return $this->fill($real, $samples, $slots);
    }

    /**
     * The homepage "Vote for your favourite brand" entry point. Uses the
     * same campaign as the voting section when one is open; otherwise the
     * next scheduled campaign, else the most recent paused/ended one, so the
     * block shows an honest upcoming/closed state instead of a vote button.
     * Null when there is no real campaign (sample mode). Only links into
     * the existing /vote/{slug} and /voting/{slug} flows — every vote is
     * still validated server-side by VoteController + VoteService.
     *
     * @return array{phase: string, label: string, title: string, rule: string, starts: ?string, ends: ?string, url: string, nominees: array}|null
     */
    public function voteEntry(): ?array
    {
        if (! PlatformSchema::ready()) {
            return null;
        }

        $id = $this->settings()['campaign_id'];
        $campaign = $id ? VoteCampaign::visible()->with('award')->find($id) : null;
        if (! $campaign) {
            $all = VoteCampaign::visible()->with('award')->latest('id')->get();
            $campaign = $all->first(fn ($c) => $c->isOpen() && $c->entries()->where('is_active', true)->exists())
                ?? $all->filter(fn ($c) => $c->phase() === 'scheduled')->sortBy('starts_at')->first()
                ?? $all->first();
        }
        if (! $campaign) {
            return null;
        }

        $nominees = [];
        if ($campaign->isOpen()) {
            $nominees = $campaign->entries()->where('is_active', true)
                ->whereHas('brand', fn ($b) => $b->published())->with('brand:id,name,slug')->get()
                ->pluck('brand')->unique('id')->sortBy('name')
                ->map(fn (Brand $b) => ['name' => $b->name, 'url' => $b->voteUrl()])->values()->all();
        }

        return [
            'phase' => $campaign->phase(),
            'label' => $campaign->phaseLabel(),
            'title' => $campaign->award?->title ?? $campaign->title,
            'rule' => $campaign->voteRuleText(),
            'starts' => $campaign->starts_at?->format('j M Y, g:i A'),
            'ends' => $campaign->ends_at?->format('j M Y, g:i A'),
            'url' => route('voting.campaign', $campaign->slug),
            'nominees' => $nominees,
        ];
    }

    public function realBrandCount(): int
    {
        return $this->published()->count();
    }

    /**
     * Hero figures, from real records only: published brand profiles and
     * valid votes. Zero counts are left out, and nothing is shown before
     * the platform tables exist — sample numbers are never displayed.
     */
    public function stats(): array
    {
        if (! PlatformSchema::ready()) {
            return [];
        }

        return array_values(array_filter([
            ['value' => number_format($this->realBrandCount()), 'label' => 'Brand profiles', 'n' => $this->realBrandCount()],
            ['value' => number_format($votes = Vote::where('status', 'valid')->count()), 'label' => 'Votes counted', 'n' => $votes],
        ], fn ($s) => $s['n'] > 0));
    }

    /** Homepage search: matching real brands first, then sample matches. */
    public function search(string $q): array
    {
        $sample = Showcase::search($q);
        $needle = mb_strtolower(trim($q));
        $real = $needle === '' ? [] : $this->published()->filter(fn (Brand $b) => collect([$b->name, $b->district, $b->division, $b->category?->name, $b->description, $b->founder_name])
            ->contains(fn ($f) => str_contains(mb_strtolower((string) $f), $needle)))->map->toCard()->values()->all();

        return [
            'brands' => array_slice(array_merge($real, $this->samples() ? $sample['brands'] : []), 0, max(8, count($real))),
            'entrepreneurs' => $this->samples() ? $sample['entrepreneurs'] : [],
        ];
    }

    /** "Browse all brands": every real brand, then the samples. */
    public function allBrands(): array
    {
        $real = $this->published()->map->toCard()->values()->all();

        return array_merge($real, $this->samples() ? array_values(Showcase::brands()) : []);
    }

    /**
     * Visible campaigns the server confirms are open right now (phase()
     * === 'open': not draft/paused/ended, inside their start/end window)
     * and that have at least one active entry for a published brand.
     * Nothing on the homepage says "Voting open", shows a voting countdown
     * or a "Vote" button unless this is non-empty.
     */
    public function openCampaigns(): Collection
    {
        if (! PlatformSchema::ready()) {
            return collect();
        }

        return $this->openCampaigns ??= VoteCampaign::visible()->get()
            ->filter(fn ($c) => $c->isOpen() && $c->entries()->where('is_active', true)->whereHas('brand', fn ($b) => $b->published())->exists())
            ->values();
    }

    public function votingOpen(): bool
    {
        return $this->openCampaigns()->isNotEmpty();
    }

    /**
     * The smaller award cards beside the featured one: other real public
     * awards only (never invented programmes), with the same server-checked
     * voting rule as the featured card.
     */
    public function otherAwards(string $exceptTitle): array
    {
        if (! PlatformSchema::ready()) {
            return [];
        }

        return Award::public()->where('status', '!=', 'archived')->where('title', '!=', $exceptTitle)
            ->latest('year')->latest('id')->take(3)->get()
            ->map(function (Award $a) {
                $campaign = $this->openCampaigns()->firstWhere('award_id', $a->id);
                $state = match (true) {
                    $campaign !== null => 'voting',
                    $a->acceptsNominations() => 'nominate',
                    $a->status === 'jury_review' => 'jury',
                    default => 'info',
                };
                $ends = $state === 'voting' ? ($campaign->ends_at ?? $a->voting_ends_at) : ($state === 'nominate' ? $a->nomination_ends_at : null);

                return [
                    'title' => $a->title,
                    'category' => 'National · '.$a->year,
                    'status' => $a->status === 'voting' && ! $campaign ? 'Voting not open' : $a->statusLabel(),
                    'state' => $state,
                    'when' => $ends ? 'Closes '.$ends->format('j M') : null,
                    'initials' => mb_strtoupper(collect(preg_split('/\s+/', $a->title))->take(2)->map(fn ($w) => mb_substr($w, 0, 1))->implode('')),
                    'from' => '#1769AA',
                    'to' => '#073B66',
                    'url' => route('awards.show', $a->slug),
                ];
            })->all();
    }

    /** Featured award card: the award chosen in Super Admin, else a public featured award, else the sample. */
    public function award(): array
    {
        $award = null;
        if (PlatformSchema::ready()) {
            $id = $this->settings()['award_id'];
            $award = ($id ? Award::public()->find($id) : null)
                ?? Award::public()->where('is_featured', true)->latest('year')->latest('id')->first();
        }
        if (! $award) {
            return Showcase::featuredAward();
        }

        $award->loadCount(['categories', 'nominations as nominees_count' => fn ($q) => $q->whereIn('status', ['accepted', 'shortlisted', 'finalist'])]);
        $votes = Vote::whereIn('campaign_id', $award->campaigns()->pluck('id'))->where('status', 'valid')->count();
        $order = ['nominations_open' => 0, 'voting' => 1, 'jury_review' => 2, 'completed' => 3, 'archived' => 3];
        $at = $order[$award->status] ?? 0;
        $state = fn ($i) => $i < $at ? 'done' : ($i === $at ? 'current' : 'next');

        // Countdown only for a window the server confirms: an open campaign of this award, or open nominations.
        $campaign = $this->openCampaigns()->firstWhere('award_id', $award->id);
        [$closes, $closesTitle] = match (true) {
            $campaign !== null => [$campaign->ends_at ?? $award->voting_ends_at, 'Voting closes'],
            $award->acceptsNominations() => [$award->nomination_ends_at, 'Nominations close'],
            default => [null, null],
        };

        return [
            'live' => true,
            'voting_open' => $campaign !== null,
            'title' => $award->title,
            'bn' => $award->bn_title ?? '',
            'edition' => 'National · '.$award->year,
            // The award's own status says "Public voting", but votes can only be cast in an open campaign.
            'status' => $award->status === 'voting' && ! $campaign ? 'Voting not open' : $award->statusLabel(),
            'closes_at' => $closes?->toIso8601String() ?? '',
            'closes_label' => $closesTitle ? ($closes?->format('j M Y, g:i A') ?? 'To be announced') : null,
            'closes_title' => $closesTitle,
            'stats' => [
                ['value' => (string) $award->categories_count, 'label' => 'Categories'],
                ['value' => number_format($award->nominees_count), 'label' => 'Nominees'],
                ['value' => number_format($votes), 'label' => 'Votes counted'],
            ],
            'steps' => [
                ['label' => 'Nomination', 'meta' => $award->nomination_ends_at ? 'Until '.$award->nomination_ends_at->format('j M') : 'Free', 'state' => $state(0)],
                ['label' => 'Public voting', 'meta' => 'People’s Choice', 'state' => $state(1)],
                ['label' => 'Jury review', 'meta' => 'Jury Choice', 'state' => $state(2)],
                ['label' => 'Winners', 'meta' => $award->voting_ends_at ? 'After '.$award->voting_ends_at->format('j M') : 'Announced', 'state' => $state(3)],
            ],
            'url' => route('awards.show', $award->slug),
        ];
    }

    /**
     * Voting tabs: the campaign chosen in Super Admin, else the open
     * campaign with the most nominees; sample tabs only when there is none.
     * Returns [tabs, note].
     */
    public function voting(): array
    {
        $campaign = null;
        if (PlatformSchema::ready()) {
            $id = $this->settings()['campaign_id'];
            $campaign = $id ? VoteCampaign::visible()->find($id) : null;
            $campaign ??= VoteCampaign::visible()->with('award')->withCount(['entries' => fn ($q) => $q->where('is_active', true)])->get()
                ->filter(fn ($c) => $c->isOpen() && $c->entries_count > 0)->sortByDesc('entries_count')->first();
        }

        $tabs = [];
        if ($campaign) {
            $campaign->load(['award', 'categories.entries' => fn ($q) => $q->where('is_active', true)->whereHas('brand', fn ($b) => $b->published())
                ->orderByDesc('votes_count')->orderBy('id')->with(['brand' => fn ($b) => $b->with(Brand::CARD_RELATIONS)])]);
            foreach ($campaign->categories as $cat) {
                $entries = $cat->entries->take(8)->values();
                if ($entries->isEmpty()) {
                    continue;
                }
                $total = (int) $cat->entries->sum('votes_count');
                $max = max(1, (int) $entries->max('votes_count'));
                $tabs['c'.$cat->id] = [
                    'key' => 'c'.$cat->id,
                    'label' => $cat->name,
                    'heading' => $cat->name.' · '.($campaign->award?->title ?? $campaign->title),
                    'total' => $total,
                    'show_counts' => $campaign->show_counts,
                    // Vote buttons only while the server confirms this campaign is open.
                    'open' => $campaign->isOpen(),
                    'nominees' => $entries->map(fn ($e, $i) => [
                        'brand' => $e->brand->toCard(),
                        'votes' => $e->votes_count,
                        'pct' => $total ? (int) round($e->votes_count / $total * 100) : 0,
                        'bar' => (int) round($e->votes_count / $max * 100),
                        'rank' => $i + 1,
                    ])->all(),
                ];
            }
        }

        if ($tabs) {
            return [$tabs, $campaign->voteRuleText().'. Votes are checked for duplicate numbers, devices and unusual patterns.'];
        }

        if (! $this->samples()) {
            return [[], 'Voting opens when an award campaign starts. Nominees will appear here.'];
        }

        return [Showcase::votingCategories(), 'Sample brands shown for preview. Voting opens when an award campaign starts.'];
    }

    /**
     * "Browse by category": the real active brand categories (linking to the
     * filtered directory) once the platform tables exist; the sample list
     * only in sample mode; otherwise nothing.
     *
     * @return array<int, array{name: string, bn: ?string, icon: string, url: string}>
     */
    public function categories(): array
    {
        if (PlatformSchema::ready()) {
            $real = BrandCategory::active()->ordered()->get()
                ->map(fn (BrandCategory $c) => ['name' => $c->name, 'bn' => $c->bn_name, 'icon' => $c->icon ?: 'store', 'url' => route('brands.index', ['category' => $c->slug])])
                ->all();
            if ($real) {
                return $real;
            }
        }

        if (! $this->samples()) {
            return [];
        }

        return array_map(fn ($c) => $c + ['url' => url('/').'?q='.urlencode(explode(' ', $c['name'])[0]).'#results'], Showcase::categories());
    }

    /** Whether sample content may fill empty slots (Super Admin → Homepage "sample_fallback"). */
    public function showsSamples(): bool
    {
        return $this->samples();
    }

    /** Real cards first, then sample cards (if allowed) until the slots are full. */
    private function fill(array $real, array $samples, int $slots): array
    {
        if (! $this->samples() || count($real) >= $slots) {
            return array_slice($real, 0, $slots);
        }
        $taken = array_flip(array_map(fn ($b) => mb_strtolower($b['name']), $real));
        foreach ($samples as $s) {
            if (count($real) >= $slots) {
                break;
            }
            if (! isset($taken[mb_strtolower($s['name'])])) {
                $real[] = $s + ['sample' => true];
            }
        }

        return $real;
    }
}
