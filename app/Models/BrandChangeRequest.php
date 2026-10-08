<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** An owner's pending edit to identity fields of an approved brand. */
class BrandChangeRequest extends Model
{
    protected $guarded = [];

    protected $casts = [
        'changes' => 'array',
        'original' => 'array',
        'reviewed_at' => 'datetime',
    ];

    public function brand()
    {
        return $this->belongsTo(Brand::class)->withTrashed();
    }
}
