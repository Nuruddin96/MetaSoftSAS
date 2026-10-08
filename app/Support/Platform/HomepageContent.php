<?php

namespace App\Support\Platform;

use App\Models\Award;
use App\Models\Brand;
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

    public static function defaults(): array
    {
        return [
            'announcement' => config('platform.award_name').' · Public voting closes 30 Nov',
            'hero_pill' => config('platform.award_name').' · '.count(config('platform.award_categories')).' categories',
            'hero_title' => 'Discover the brands & entrepreneurs',
            'hero_highlight' => 'building Bangladesh.',
            'hero_sub' => 'Explore verified brand profiles, celebrate founders, vote in transparent national awards and connect with opportunities — all in one trusted network.',
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

    public function realBrandCount(): int
    {
        return $this->published()->count();
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
        $closes = $award->voting_ends_at ?? $award->nomination_ends_at;
        $order = ['nominations_open' => 0, 'voting' => 1, 'jury_review' => 2, 'completed' => 3, 'archived' => 3];
        $at = $order[$award->status] ?? 0;
        $state = fn ($i) => $i < $at ? 'done' : ($i === $at ? 'current' : 'next');

        return [
            'live' => true,
            'title' => $award->title,
            'bn' => $award->bn_title ?? '',
            'edition' => 'National · '.$award->year,
            'status' => $award->statusLabel(),
            'closes_at' => $closes?->toIso8601String() ?? '',
            'closes_label' => $closes?->format('j M Y, g:i A') ?? 'To be announced',
            'closes_title' => $award->voting_ends_at ? 'Voting closes' : 'Nominations close',
            'stats' => [
                ['value' => (string) $award->categories_count, 'label' => 'Categories'],
                ['value' => (string) ($award->categories_count * 2), 'label' => 'Awards'],
                ['value' => number_format($award->nominees_count), 'label' => 'Nominees'],
                ['value' => number_format($votes), 'label' => 'Verified votes'],
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

        return [Showcase::votingCategories(), 'Public votes count for 40% of each final result — the jury decides the other 60%. One verified vote per person, per category, per day.'];
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
