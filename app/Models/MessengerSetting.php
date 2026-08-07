<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class MessengerSetting extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected $casts = [
        'page_access_token' => 'encrypted',
        'is_active'         => 'boolean',
    ];
}
