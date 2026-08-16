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

    /** The variant a product card/listing shows a single price+discount for — cheapest active variant, same "min" this already reports via priceRange(). */
    public function cardVariant(): ?ProductVariant
    {
        return $this->variants->sortBy('selling_price')->first();
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
