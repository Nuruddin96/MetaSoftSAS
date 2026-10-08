<?php

namespace App\Support\Platform;

use App\Models\VoteCampaign;

/**
 * "Voting started" / "Voting ended" owner notifications, sent exactly once
 * per campaign (started_notified_at / ended_notified_at). Called when Super
 * Admin changes a campaign's status and every few minutes by
 * `platform:campaign-tick`, which also catches date-driven starts/ends.
 */
class CampaignLifecycle
{
    public static function sync(VoteCampaign $campaign): void
    {
        $phase = $campaign->phase();

        if ($phase === 'ended' && $campaign->status === 'active') {
            $campaign->update(['status' => 'ended']);
        }

        if ($phase === 'open' && ! $campaign->started_notified_at) {
            $campaign->update(['started_notified_at' => now()]);
            foreach (self::entries($campaign) as $entry) {
                PlatformNotifier::owner($entry->brand, 'voting_started', 'Voting is open: '.$campaign->title,
                    'Your brand is competing in '.$entry->category->name.'. Share your voting link: '.$entry->brand->voteUrl(),
                    route('owner.voting'));
            }
        }

        if ($phase === 'ended' && $campaign->started_notified_at && ! $campaign->ended_notified_at) {
            $campaign->update(['ended_notified_at' => now()]);
            foreach (self::entries($campaign) as $entry) {
                $body = $campaign->show_counts
                    ? 'Final count in '.$entry->category->name.': '.number_format($entry->votes_count).' votes (rank #'.$entry->rank().'). Results are announced after review.'
                    : 'Thank you for taking part in '.$entry->category->name.'. Results are announced after review.';
                PlatformNotifier::owner($entry->brand, 'voting_ended', 'Voting has ended: '.$campaign->title, $body, route('owner.voting'));
            }
        }
    }

    private static function entries(VoteCampaign $campaign)
    {
        return $campaign->entries()->where('is_active', true)->with('brand.owner', 'category')->get()->filter(fn ($e) => $e->brand?->owner);
    }
}
