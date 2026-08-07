<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Inventory extends Model
{
    use BelongsToTenant;

    protected $table = 'inventory';
    protected $guarded = [];
    public $timestamps = false;

    protected static function booted(): void
    {
        static::saving(fn ($m) => $m->updated_at = now());
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }
}
