<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One public vote. The phone number is never stored — only a keyed hash
 * (voter_hash) and a masked display form. `flags` lists the anomaly
 * signals VoteService saw when the vote was cast; flagged votes count
 * until a Super Admin reviews and invalidates them.
 */
class Vote extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = ['invalidated_at' => 'datetime'];

    public function campaign()
    {
        return $this->belongsTo(VoteCampaign::class, 'campaign_id');
    }

    public function entry()
    {
        return $this->belongsTo(VoteEntry::class, 'vote_entry_id');
    }

    public function flagList(): array
    {
        return $this->flags ? explode(',', $this->flags) : [];
    }
}
