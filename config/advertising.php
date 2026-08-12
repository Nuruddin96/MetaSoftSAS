<?php

return [
    // All "which calendar day is this" decisions for Advertising billing
    // (daily charge idempotency, day-boundary display) use this timezone,
    // independent of config('app.timezone') (UTC) which everything else
    // in the app still runs on — see AdvertisingBalanceService::today().
    'timezone' => env('AD_BILLING_TIMEZONE', 'Asia/Dhaka'),
];
