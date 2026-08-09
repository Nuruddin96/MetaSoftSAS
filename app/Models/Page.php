<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Page extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::saving(function (Page $p) {
            $p->slug = $p->slug ?: Str::slug($p->title) ?: 'page-'.Str::lower(Str::random(5));
        });
    }
}
