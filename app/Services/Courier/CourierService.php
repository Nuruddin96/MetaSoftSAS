<?php

namespace App\Services\Courier;

use App\Models\Order;

/**
 * Every courier provider (Pathao, Steadfast, RedX) implements this.
 * Tenant clicks "Send to Courier" -> we call createShipment()
 * -> consignment id + tracking code saved on the order.
 */
interface CourierService
{
    /** Push an order to the courier panel. Returns ['consignment_id' => ..., 'tracking_code' => ...] */
    public function createShipment(Order $order): array;

    /** Latest delivery status from courier. */
    public function getStatus(Order $order): string;

    /** Fraud check: delivery history of a phone number. Returns [total, delivered, returned]. */
    public function checkPhoneHistory(string $phone): array;
}
