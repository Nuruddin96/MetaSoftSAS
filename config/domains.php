<?php

return [
    // 'manual' is the only driver today — real automation (Nginx/Caddy vhost + SSL)
    // needs server-level access this app doesn't have on Hostinger shared hosting.
    'driver' => env('DOMAIN_DRIVER', 'manual'),
];
