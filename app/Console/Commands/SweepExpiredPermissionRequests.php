<?php

namespace App\Console\Commands;

use App\Services\PermissionRequestService;
use Illuminate\Console\Command;

/**
 * Same shape as SweepStaleRemoteSupportSessions — see that class's doc
 * comment for the general rationale. This is the fix for "a request must
 * not stay Pending forever without an explanation or timeout state":
 * anything past its own `expires_at` (set at creation, see
 * PermissionRequestService::create()) that never resolved flips to
 * Expired here, on a schedule, independent of any admin revisiting that
 * device.
 */
class SweepExpiredPermissionRequests extends Command
{
    protected $signature = 'permission-requests:sweep-expired';

    protected $description = 'Marks unresolved permission requests past their expiry as Expired instead of leaving them Pending forever.';

    public function handle(PermissionRequestService $service): int
    {
        $count = $service->expireStale();

        $this->info("Expired {$count} stale permission request(s).");

        return self::SUCCESS;
    }
}
