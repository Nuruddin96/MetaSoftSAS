<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Coupon extends Model
{
    use BelongsToTenant;

    protected $guarded = [];
    public $timestamps = true;
}
