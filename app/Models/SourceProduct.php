<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class SourceProduct extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        static::creating(function (SourceProduct $p) {
            $p->slug = $p->slug ?: Str::slug($p->name).'-'.Str::lower(Str::random(4));
        });
    }

    public function orders()
    {
        return $this->hasMany(SourceOrder::class);
    }

    public function images()
    {
        return $this->hasMany(SourceProductImage::class)->orderBy('sort_order');
    }

    public function priceLabel(): string
    {
        if ($this->max_price && $this->max_price > $this->unit_price) {
            return number_format($this->unit_price).'-'.number_format($this->max_price);
        }

        return number_format($this->unit_price);
    }
}
