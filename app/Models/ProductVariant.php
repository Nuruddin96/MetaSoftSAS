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
}
