<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Banner extends Model
{
    use BelongsToTenant;

    protected $guarded = [];
}
