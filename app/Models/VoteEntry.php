<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A brand competing in one voting category. votes_count caches the
 * number of VALID votes and is only changed by App\Support\Platform\
 * VoteService — never by any owner-facing code path.
 */
class VoteEntry extends Model
{
    protected $guarded = [];

    protected $casts = ['is_active' => 'boolean'];

    public function campaign()
    {
        return $this->belongsTo(VoteCampaign::class, 'campaign_id');
    }

    public function category()
    {
        return $this->belongsTo(VoteCategory::class, 'vote_category_id');
    }

    public function brand()
    {
        return $this->belongsTo(Brand::class)->withTrashed();
    }

    public function votes()
    {
        return $this->hasMany(Vote::class);
    }

    /** 1-based position within its category (ties share the better rank). */
    public function rank(): int
    {
        return self::where('vote_category_id', $this->vote_category_id)
            ->where('is_active', true)
            ->where('votes_count', '>', $this->votes_count)
            ->count() + 1;
    }
}
