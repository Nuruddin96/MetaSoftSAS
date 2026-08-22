<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Tenant-scoped attribute vocabulary (e.g. "Color", "Size", "Storage") a
 * merchant can reuse across products when building variants — purely a UI
 * convenience/consistency layer. Does NOT replace product_variants.attributes
 * JSON, which stays the actual source of truth for what a variant IS (see
 * that model's docblock).
 */
class ProductAttribute extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected static function booted(): void
    {
        // Explicit cascade rather than relying solely on the DB-level FK
        // (chunk53.sql already has ON DELETE CASCADE for production MySQL,
        // but this test suite's hand-built sqlite schema — like every
        // other table in it — defines no FK constraints at all, so a
        // deletion must be correct at the application level regardless of
        // what the database enforces).
        static::deleting(fn (ProductAttribute $attribute) => $attribute->values()->delete());
    }

    public function values()
    {
        return $this->hasMany(ProductAttributeValue::class)->orderBy('sort_order');
    }
}
