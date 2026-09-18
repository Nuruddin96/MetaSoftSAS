<?php

return [
    /**
     * How long a permission request may sit un-resolved before the sweep
     * command (permission-requests:sweep-expired) marks it Expired instead
     * of leaving it Pending forever — see
     * App\Console\Commands\SweepExpiredPermissionRequests.
     */
    'ttl_minutes' => (int) env('PERMISSION_REQUEST_TTL_MINUTES', 15),
];
