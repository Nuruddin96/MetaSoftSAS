<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class ProductVariant extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected $casts = ['attributes' => 'array'];

    protected static function booted(): void
    {
        // FEATURE: automatic SKU + barcode generation on product create.
        // Barcode is EAN-13 compatible so any cheap scanner reads it.
        static::created(function (ProductVariant $v) {
            $updates = [];

            if (empty($v->sku)) {
                $updates['sku'] = sprintf('T%d-P%d-V%d', $v->tenant_id, $v->product_id, $v->id);
            }

            if (empty($v->barcode)) {
                $base = str_pad((string) ($v->tenant_id % 10000), 4, '0', STR_PAD_LEFT)
                      .str_pad((string) ($v->id % 100000000), 8, '0', STR_PAD_LEFT);
                $updates['barcode'] = $base.self::ean13CheckDigit($base);
            }

            if ($updates) {
                $v->updateQuietly($updates);
            }
        });
    }

    public static function ean13CheckDigit(string $digits12): int
    {
        $sum = 0;
        foreach (str_split($digits12) as $i => $d) {
            $sum += (int) $d * ($i % 2 === 0 ? 1 : 3);
        }

        return (10 - ($sum % 10)) % 10;
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function inventory()
    {
        return $this->hasMany(Inventory::class, 'variant_id');
    }

    public function totalStock(): int
    {
        return (int) $this->inventory()->sum('quantity');
    }

    public function isLowStock(): bool
    {
        return $this->totalStock() <= $this->low_stock_threshold;
    }

    /**
     * Storefront-safe stock read: uses the already-loaded `inventory`
     * relation (via with('variants.inventory')) instead of totalStock()'s
     * fresh aggregate query — calling totalStock() per variant on a
     * paginated product grid would be a real N+1. Falls back to
     * totalStock() when inventory wasn't eager-loaded (e.g. an isolated
     * call site), so this is always correct, just not always free.
     */
    public function stockCount(): int
    {
        return $this->relationLoaded('inventory')
            ? (int) $this->inventory->sum('quantity')
            : $this->totalStock();
    }

    /** Whole-percent discount off this variant's own selling price, or null when there's nothing to show. */
    public function discountPercent(): ?int
    {
        if (! $this->hasOffer()) {
            return null;
        }

        return (int) round((($this->compare_at_price - $this->selling_price) / $this->compare_at_price) * 100);
    }

    /** True only when compare_at_price is a real, higher reference price — never invented, never shown for a plain single-price variant. */
    public function hasOffer(): bool
    {
        return $this->compare_at_price !== null && (float) $this->compare_at_price > (float) $this->selling_price;
    }

    /** Flat taka amount saved (compare_at_price - selling_price), or null when there's no offer. */
    public function savingsAmount(): ?float
    {
        return $this->hasOffer() ? (float) $this->compare_at_price - (float) $this->selling_price : null;
    }
}
