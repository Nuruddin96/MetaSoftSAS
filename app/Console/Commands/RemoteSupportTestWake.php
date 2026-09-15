<?php

namespace App\Console\Commands;

use App\Models\MobileDevice;
use App\Services\RemoteSupport\RemoteSupportService;
use Illuminate\Console\Command;

/**
 * Manual, developer-run tool to send one test FCM wake message outside the
 * Admin UI — useful for isolating "did FCM even reach the device" from the
 * full Admin wakeAndStart flow (RemoteSupportController::wakeAndStart, which
 * additionally polls for readiness and starts a session). Sending itself is
 * implemented once, in RemoteSupportService::sendWakeSignal(), which this
 * command and the Admin flow both call — no duplicated JWT/HTTP logic.
 */
class RemoteSupportTestWake extends Command
{
    protected $signature = 'remote-support:test-wake {device : MobileDevice id}';

    protected $description = 'Sends one high-priority FCM data wake message to a device\'s stored fcm_token (manual testing only, not part of the Admin flow).';

    public function handle(RemoteSupportService $service): int
    {
        $device = MobileDevice::withoutGlobalScope('tenant')->find($this->argument('device'));
        if (! $device) {
            $this->error('No such device.');

            return self::FAILURE;
        }

        if (! $device->fcm_token) {
            $this->error('This device has no fcm_token stored yet.');

            return self::FAILURE;
        }

        if (! $service->isWakeConfigured()) {
            $this->error('REMOTE_SUPPORT_FCM_PROJECT_ID / REMOTE_SUPPORT_FCM_SERVICE_ACCOUNT_PATH not configured or file missing.');

            return self::FAILURE;
        }

        $sent = $service->sendWakeSignal($device);
        $this->line($sent ? 'FCM accepted the wake message.' : 'FCM send failed — see log for details.');

        // "Accepted by FCM" only — says nothing about actual delivery, OEM
        // filtering, or whether the wake path ran. Confirm on the device's
        // own logcat (RemoteSupportFcm / RemoteSupport tags) and a fresh
        // heartbeat arriving server-side before treating this as proof.
        return $sent ? self::SUCCESS : self::FAILURE;
    }
}
