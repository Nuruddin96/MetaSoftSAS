<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SourceOrder extends Model
{
    protected $guarded = [];

    public function product()
    {
        return $this->belongsTo(SourceProduct::class, 'source_product_id');
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
