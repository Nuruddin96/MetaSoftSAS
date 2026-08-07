<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class MessengerMessage extends Model
{
    use BelongsToTenant;

    protected $guarded = [];
    public $timestamps = false;

    protected static function booted(): void
    {
        static::creating(fn ($m) => $m->created_at = $m->created_at ?: now());
    }
}
