<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `cart_json` is a flat `{variant_id: quantity}` map (confirmed against
 * Storefront\CartController::add()/update() — the only writer of this
 * column) — resolved to product names via the $variantNames map the
 * controller passes in, mirroring Tenant\IncompleteOrderController::index()'s
 * own ProductVariant lookup rather than guessing the JSON shape.
 */
class IncompleteOrderResource extends JsonResource
{
    public static $wrap = null;

    public function __construct($resource, protected array $variantNames = [])
    {
        parent::__construct($resource);
    }

    public function toArray($request): array
    {
        $items = collect($this->cart_json ?? [])->map(fn ($qty, $variantId) => [
            'product_name' => $this->variantNames[(int) $variantId] ?? 'অজানা প্রোডাক্ট',
            'quantity' => (int) $qty,
        ])->values();

        return [
            'id' => $this->id,
            'customer_name' => $this->customer_name,
            'customer_phone' => $this->customer_phone,
            'customer_address' => $this->customer_address,
            'items' => $items,
            'total' => (float) $this->total,
            'status' => $this->status,
            'last_activity_at' => optional($this->last_activity_at)->toIso8601String(),
        ];
    }
}
