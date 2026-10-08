<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Earned result of an award: winner, People's Choice or Jury Choice — three distinct types, never merged. */
class AwardRecognition extends Model
{
    public const TYPES = [
        'winner' => 'Winner',
        'peoples_choice' => 'People’s Choice',
        'jury_choice' => 'Jury Choice',
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

    public function label(): string
    {
        return $this->title ?: self::TYPES[$this->type].($this->award ? ' '.$this->award->year : '');
    }
}
