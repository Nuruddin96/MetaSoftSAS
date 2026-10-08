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
        return $this->vote_limit === 'once' ? 'once' : now()->toDateString();
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
