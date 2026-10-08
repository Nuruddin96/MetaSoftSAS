<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VoteCategory extends Model
{
    protected $guarded = [];

    public function campaign()
    {
        return $this->belongsTo(VoteCampaign::class, 'campaign_id');
    }

    public function entries()
    {
        return $this->hasMany(VoteEntry::class);
    }

    /** Active entries, highest valid votes first (ties: earliest added). */
    public function rankedEntries()
    {
        return $this->entries()->where('is_active', true)->orderByDesc('votes_count')->orderBy('id');
    }
}
