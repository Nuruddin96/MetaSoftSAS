<?php

return [
    // Set PAYMENT_ONLINE_ENABLED=true in .env once SSLCommerz/bKash is confirmed working.
    // While false, tenants see WhatsApp/Call contact instead of payment buttons.
    'online_enabled' => env('PAYMENT_ONLINE_ENABLED', false),

    'sslcommerz' => [
        'store_id' => env('SSLCZ_STORE_ID'),
        'store_password' => env('SSLCZ_STORE_PASSWORD'),
        'sandbox' => env('SSLCZ_SANDBOX', true),
    ],
    'manual_note' => env('MANUAL_PAYMENT_NOTE'),
    'support_phone' => env('SUPPORT_PHONE', '01973847204'),
    'support_whatsapp' => env('SUPPORT_WHATSAPP', '8801973847204'),
    'bkash' => [
        'username' => env('BKASH_USERNAME'),
        'password' => env('BKASH_PASSWORD'),
        'app_key' => env('BKASH_APP_KEY'),
        'app_secret' => env('BKASH_APP_SECRET'),
        'sandbox' => env('BKASH_SANDBOX', true),
    ],
];
