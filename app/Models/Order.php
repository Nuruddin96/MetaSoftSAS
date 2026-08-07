<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Order extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected $casts = [
        'fraud_summary' => 'array',
        'confirmed_at'  => 'datetime',
        'delivered_at'  => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (Order $o) {
            if (empty($o->order_number)) {
                // per-tenant sequential number, race-safe enough for shared hosting
                $last = DB::table('orders')
                    ->where('tenant_id', $o->tenant_id)
                    ->lockForUpdate()
                    ->max('id');
                $o->order_number = 'ORD-' . str_pad((string) (($last ?? 0) + 1), 6, '0', STR_PAD_LEFT);
            }
        });
    }

    public function items()    { return $this->hasMany(OrderItem::class); }
    public function customer() { return $this->belongsTo(Customer::class); }

    public function profit(): float
    {
        return (float) $this->items->sum(
            fn ($i) => ($i->unit_price - $i->purchase_price) * $i->quantity
        );
    }
}
