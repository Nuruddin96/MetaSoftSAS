<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class Plan extends Model
{
    protected $guarded = [];

    protected $casts = [
        'features' => 'array',
    ];

    /** True once database/sql/chunk27.sql is imported — mirrors the tablesReady() pattern used by every other additive column/table in this codebase (e.g. FacebookPage::tablesReady()). */
    public static function featuresColumnReady(): bool
    {
        return Schema::hasColumn('plans', 'features');
    }

    /** True once database/sql/chunk45.sql (tagline/is_featured) is imported. */
    public static function cmsColumnsReady(): bool
    {
        return Schema::hasColumn('plans', 'tagline');
    }

    /**
     * Whether this plan includes the given feature key (see config/features.php
     * for the full catalog). Schema-guarded: a plan saved before chunk27.sql
     * was imported, or on an environment where it hasn't run yet, has
     * features === null — treated as "no optional features enabled" rather
     * than throwing, same fail-safe posture as every other partial-import
     * guard in this codebase.
     */
    public function hasFeature(string $key): bool
    {
        return in_array($key, $this->features ?? [], true);
    }
}
