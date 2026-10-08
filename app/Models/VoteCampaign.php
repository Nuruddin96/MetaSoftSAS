<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A public voting campaign. `status` is what Super Admin set; phase()
 * combines it with starts_at/ends_at, so an 'active' campaign whose
 * start date is still ahead reads as 'scheduled' and one past its end
 * date reads as 'ended' without anyone having to flip it.
 */
class VoteCampaign extends Model
{
    public const PHASE_LABELS = [
        'draft' => 'Draft',
        'scheduled' => 'Starts soon',
        'open' => 'Voting open',
        'paused' => 'Paused',
        'ended' => 'Voting ended',
    ];

    /** vote_limit: how often one phone number may vote. 'program' spans every category and every campaign of the same award. */
    public const VOTE_LIMITS = [
        'daily' => 'One vote per number per category, every day',
        'once' => 'One vote per number per category, for the whole campaign',
        'program' => 'One vote per number in the entire award programme',
    ];

    protected $guarded = [];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'show_counts' => 'boolean',
        'started_notified_at' => 'datetime',
        'ended_notified_at' => 'datetime',
    ];

    public function award()
    {
        return $this->belongsTo(Award::class);
    }

    public function categories()
    {
        return $this->hasMany(VoteCategory::class, 'campaign_id')->orderBy('sort_order')->orderBy('id');
    }

    public function entries()
    {
        return $this->hasMany(VoteEntry::class, 'campaign_id');
    }

    public function votes()
    {
        return $this->hasMany(Vote::class, 'campaign_id');
    }

    public function phase(): string
    {
        if ($this->status === 'draft' || $this->status === 'paused') {
            return $this->status;
        }
        if ($this->status === 'ended' || ($this->ends_at && $this->ends_at->isPast())) {
            return 'ended';
        }
        if ($this->starts_at && $this->starts_at->isFuture()) {
            return 'scheduled';
        }

        return 'open';
    }

    public function phaseLabel(): string
    {
        return self::PHASE_LABELS[$this->phase()];
    }

    public function isOpen(): bool
    {
        return $this->phase() === 'open';
    }

    /** Campaigns visible to the public / owners (not drafts). */
    public function scopeVisible($q)
    {
        return $q->where('status', '!=', 'draft');
    }

    public function periodKey(): string
    {
        return in_array($this->vote_limit, ['once', 'program'], true) ? 'once' : now()->toDateString();
    }

    /** Campaign ids that share one 'program' vote: every campaign of the same award (or just this one when it has no award). */
    public function programCampaignIds(): array
    {
        return $this->award_id ? self::where('award_id', $this->award_id)->pluck('id')->all() : [$this->id];
    }

    /** Short public wording of the vote rule. */
    public function voteRuleText(): string
    {
        return match ($this->vote_limit) {
            'program' => 'One vote per mobile number in the entire '.($this->award?->title ?? 'programme'),
            'once' => 'One vote per mobile number per category for this campaign',
            default => 'One vote per mobile number per category each day',
        };
    }

    public static function uniqueSlug(string $title, ?int $exceptId = null): string
    {
        $base = Str::slug(Str::limit($title, 180, '')) ?: 'campaign';
        $slug = $base;
        $n = 2;
        while (self::where('slug', $slug)->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))->exists()) {
            $slug = $base.'-'.$n++;
        }

        return $slug;
    }
}
