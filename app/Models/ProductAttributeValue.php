<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One selectable value (e.g. "Red") under a tenant's ProductAttribute (e.g.
 * "Color"). No tenant_id of its own — scoped through product_attribute_id,
 * same as ProductVariant is scoped through product_id for images.
 */
class ProductAttributeValue extends Model
{
    protected $guarded = [];

    public function attribute()
    {
        return $this->belongsTo(ProductAttribute::class, 'product_attribute_id');
    }
}
