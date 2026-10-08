<?php

use App\Models\Affiliate;
use App\Models\BrandOwner;
use App\Models\SuperAdmin;
use App\Models\User;

return [
    'defaults' => [
        'guard' => 'tenant',
        'passwords' => 'users',
    ],

    'guards' => [
        'tenant' => [
            'driver' => 'session',
            'provider' => 'tenant_users',
        ],
        'super_admin' => [
            'driver' => 'session',
            'provider' => 'super_admins',
        ],
        'affiliate' => [
            'driver' => 'session',
            'provider' => 'affiliates',
        ],
        // Brand & Entrepreneur Recognition Platform — brand owner dashboard.
        'brand_owner' => [
            'driver' => 'session',
            'provider' => 'brand_owners',
        ],
        // Laravel default guard name kept for compatibility
        'web' => [
            'driver' => 'session',
            'provider' => 'tenant_users',
        ],
    ],

    'providers' => [
        'tenant_users' => [
            'driver' => 'eloquent',
            'model' => User::class,
        ],
        'super_admins' => [
            'driver' => 'eloquent',
            'model' => SuperAdmin::class,
        ],
        'affiliates' => [
            'driver' => 'eloquent',
            'model' => Affiliate::class,
        ],
        'brand_owners' => [
            'driver' => 'eloquent',
            'model' => BrandOwner::class,
        ],
    ],

    'passwords' => [
        'users' => [
            'provider' => 'tenant_users',
            'table' => 'password_reset_tokens',
            'expire' => 60,
            'throttle' => 60,
        ],
    ],

    'password_timeout' => 10800,
];
