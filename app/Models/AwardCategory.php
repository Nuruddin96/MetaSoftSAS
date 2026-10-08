<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AwardCategory extends Model
{
    protected $guarded = [];

    public function award()
    {
        return $this->belongsTo(Award::class);
    }

    public function brandCategory()
    {
        return $this->belongsTo(BrandCategory::class);
    }

    public function nominations()
    {
        return $this->hasMany(AwardNomination::class);
    }

    /** NULL brand_category_id = open to every brand. */
    public function accepts(Brand $brand): bool
    {
        return ! $this->brand_category_id || $this->brand_category_id === $brand->brand_category_id;
    }
}
