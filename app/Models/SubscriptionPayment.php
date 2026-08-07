<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubscriptionPayment extends Model
{
    protected $guarded = [];

    protected $casts = [
        'gateway_response' => 'array',
        'paid_at'          => 'datetime',
    ];
}
