<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Product extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::creating(function (Product $p) {
            $p->slug = $p->slug ?: Str::slug($p->name).'-'.Str::random(4);
        });
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function variants()
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function images()
    {
        return $this->hasMany(ProductImage::class);
    }

    public function priceRange(): string
    {
        $prices = $this->variants->pluck('selling_price');
        if ($prices->isEmpty()) {
            return '0';
        }
        $min = number_format($prices->min());
        $max = number_format($prices->max());

        return $min === $max ? $min : "$min - $max";
    }
}
