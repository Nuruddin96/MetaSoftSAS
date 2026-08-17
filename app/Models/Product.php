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

    /**
     * The option axes (e.g. ["Size", "Color"]) this product's variants can
     * be selected by separately on the storefront — only when EVERY active
     * variant genuinely has the exact same set of attribute keys, since a
     * mixed/partial set can't be rendered as clean independent selectors.
     * Empty when the product has one variant, no attributes were ever set
     * (the pre-existing flat variant_name picker still works exactly as
     * before), or the keys are inconsistent across variants.
     *
     * @return array<int, string>
     */
    public function optionAxes(): array
    {
        $variants = $this->variants->where('is_active', 1);

        if ($variants->count() < 2) {
            return [];
        }

        // NOTE: must call getAttribute('attributes') rather than $v->attributes here —
        // this closure's scope is Product, which (like ProductVariant) extends Model, so
        // direct property access resolves the shared protected Model::$attributes bag
        // (all raw columns) instead of going through the magic accessor/cast for the
        // JSON "attributes" column.
        $keySets = $variants->map(fn (ProductVariant $v) => collect($v->getAttribute('attributes') ?? [])->keys()->sort()->values()->all());

        if ($keySets->contains(fn ($keys) => empty($keys))) {
            return [];
        }

        $first = $keySets->first();

        return $keySets->every(fn ($keys) => $keys === $first) ? $first : [];
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
