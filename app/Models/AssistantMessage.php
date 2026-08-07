<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AssistantMessage extends Model
{
    protected $guarded = [];
    public $timestamps = false;

    protected static function booted(): void
    {
        static::creating(fn ($m) => $m->created_at = now());
    }
}
