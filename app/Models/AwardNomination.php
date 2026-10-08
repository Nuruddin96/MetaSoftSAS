<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A brand's nomination in one award category. Status moves
 * submitted → accepted → shortlisted → finalist (or rejected/withdrawn).
 * 'finalist' is earned recognition in its own right and is kept apart from
 * AwardRecognition's winner / People's Choice / Jury Choice.
 */
class AwardNomination extends Model
{
    public const STATUSES = [
        'submitted' => 'Submitted',
        'accepted' => 'Nominee',
        'shortlisted' => 'Shortlisted',
        'finalist' => 'Finalist',
        'rejected' => 'Not selected',
        'withdrawn' => 'Withdrawn',
    ];

    protected $guarded = [];

    public function award()
    {
        return $this->belongsTo(Award::class);
    }

    public function category()
    {
        return $this->belongsTo(AwardCategory::class, 'award_category_id');
    }

    public function brand()
    {
        return $this->belongsTo(Brand::class)->withTrashed();
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }
}
