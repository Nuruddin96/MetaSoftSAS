<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Resources\Json\JsonResource;

class CustomerResource extends JsonResource
{
    public static $wrap = null;

    /** @var \Illuminate\Support\Collection<int,\App\Models\Order>|null set by the controller on the detail endpoint only */
    public $recentOrders = null;

    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'phone' => $this->phone,
            'email' => $this->email,
            'address' => $this->address,
            'note' => $this->note,
            'due_balance' => (float) $this->due_balance,
            'total_orders' => (int) $this->total_orders,
            'total_spent' => (float) $this->total_spent,
            'orders' => $this->recentOrders === null ? [] : OrderResource::collection($this->recentOrders),
        ];
    }
}
