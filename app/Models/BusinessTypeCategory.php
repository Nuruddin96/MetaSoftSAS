<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One "starter" category offered for a BusinessType in onboarding Step 3.
 * Copied by name into the tenant's own `categories` table on selection —
 * never referenced live afterward, so editing/removing these rows only
 * changes what FUTURE tenants get seeded, never past ones.
 */
class BusinessTypeCategory extends Model
{
    protected $guarded = [];

    public $timestamps = false;

    public function businessType()
    {
        return $this->belongsTo(BusinessType::class);
    }
}
