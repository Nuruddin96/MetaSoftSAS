<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class CourierSetting extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected $casts = [
        'credentials' => 'encrypted:array',
        'is_active'   => 'boolean',
    ];
}
