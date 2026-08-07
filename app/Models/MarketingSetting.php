<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class MarketingSetting extends Model
{
    use BelongsToTenant;

    protected $guarded = [];
    public $timestamps = false;

    protected $casts = [
        'fb_capi_token'     => 'encrypted',
        'meta_app_secret'   => 'encrypted',
        'meta_access_token' => 'encrypted',
    ];
}
