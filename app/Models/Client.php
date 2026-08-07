<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Client extends Model
{
    protected $guarded = [];

    public function payments()
    {
        return $this->hasMany(ClientPayment::class)->latest('payment_date');
    }
}
