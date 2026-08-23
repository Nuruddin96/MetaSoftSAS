<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

class OrderResource extends JsonResource
{
    public static $wrap = null;

    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'order_number' => $this->order_number,
            'source' => $this->source,
            'channel' => $this->channel,
            'status' => $this->status,
            'customer_id' => $this->customer_id,
            'customer_name' => $this->customer_name,
            'customer_phone' => $this->customer_phone,
            'customer_address' => $this->customer_address,
            'division_name' => $this->division_id
                ? DB::table('bd_divisions')->where('id', $this->division_id)->value('bn_name')
                : null,
            'district_name' => $this->district_id
                ? DB::table('bd_districts')->where('id', $this->district_id)->value('bn_name')
                : null,
            'upazila_name' => $this->upazila_id
                ? DB::table('bd_upazilas')->where('id', $this->upazila_id)->value('bn_name')
                : null,
            'subtotal' => (float) $this->subtotal,
            'discount' => (float) $this->discount,
            'delivery_charge' => (float) $this->delivery_charge,
            'total' => (float) $this->total,
            'payment_method' => $this->payment_method,
            'courier_provider' => $this->courier_provider,
            'courier_consignment_id' => $this->courier_consignment_id,
            'courier_tracking_code' => $this->courier_tracking_code,
            'courier_status' => $this->courier_status,
            'note' => $this->note,
            'confirmed_at' => optional($this->confirmed_at)->toIso8601String(),
            'delivered_at' => optional($this->delivered_at)->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
            'items' => OrderItemResource::collection($this->items),
        ];
    }
}
