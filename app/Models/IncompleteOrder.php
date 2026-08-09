<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class IncompleteOrder extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected $casts = ['cart_json' => 'array', 'last_activity_at' => 'datetime'];

    public $timestamps = true;
}
