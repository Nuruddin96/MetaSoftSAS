<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/** Storefront customer review/testimonial — mirrors App\Models\Banner exactly. */
class Review extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    public static function tablesReady(): bool
    {
        return Schema::hasTable('reviews');
    }
}
