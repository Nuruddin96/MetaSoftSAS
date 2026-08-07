<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class FraudCheckLog extends Model
{
    use BelongsToTenant;

    protected $guarded = [];
    protected $casts = ['result' => 'array'];
    public $timestamps = false;

    protected static function booted(): void
    {
        static::creating(fn ($m) => $m->created_at = now());
    }
}
