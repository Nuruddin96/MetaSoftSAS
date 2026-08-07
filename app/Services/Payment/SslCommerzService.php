<?php

namespace App\Services\Payment;

use Illuminate\Support\Facades\Http;

/**
 * Platform-level SSLCommerz gateway for tenant SUBSCRIPTION payments.
 * One gateway covers cards + bKash + Nagad + rocket.
 *
 * .env:
 *   SSLCZ_STORE_ID=
 *   SSLCZ_STORE_PASSWORD=
 *   SSLCZ_SANDBOX=true
 */
class SslCommerzService
{
    protected string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = config('payment.sslcommerz.sandbox', true)
            ? 'https://sandbox.sslcommerz.com'
            : 'https://securepay.sslcommerz.com';
    }

    /** Create a payment session. Returns GatewayPageURL to redirect the user to. */
    public function createSession(array $payload): array
    {
        $response = Http::asForm()->post($this->baseUrl . '/gwprocess/v4/api.php', array_merge([
            'store_id'     => config('payment.sslcommerz.store_id'),
            'store_passwd' => config('payment.sslcommerz.store_password'),
            'currency'     => 'BDT',
            'shipping_method' => 'NO',
            'product_category' => 'Subscription',
            'product_profile'  => 'non-physical-goods',
            'cus_country'  => 'Bangladesh',
        ], $payload))->json();

        return $response ?? [];
    }

    /** Server-side validation of a completed transaction. */
    public function validate(string $valId): array
    {
        $response = Http::get($this->baseUrl . '/validator/api/validationserverAPI.php', [
            'val_id'       => $valId,
            'store_id'     => config('payment.sslcommerz.store_id'),
            'store_passwd' => config('payment.sslcommerz.store_password'),
            'format'       => 'json',
        ])->json();

        return $response ?? [];
    }
}
