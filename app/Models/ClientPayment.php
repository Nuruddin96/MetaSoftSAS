<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClientPayment extends Model
{
    protected $guarded = [];
    public $timestamps = false;

    protected $casts = ['payment_date' => 'date'];

    protected static function booted(): void
    {
        static::creating(fn ($m) => $m->created_at = now());
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }
}
