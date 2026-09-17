<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\MobileDevice;
use App\Models\Tenant;
use Illuminate\Http\Request;

/**
 * Super-Admin-initiated "please prompt this device for permission X" —
 * the gap Remote Support's existing mic/camera/screen flow doesn't cover
 * (those three already have a full admin-request round trip via
 * RemoteSupportService::startSession()'s WebRTC capability flow on the
 * SAME Remote Support device page, left entirely untouched by this
 * controller). See MobileDevice::SUPPORTED_PERMISSION_REQUESTS for the
 * exact list this covers and why (`notifications`, `photos`) — every
 * other Android permission this app could theoretically declare either
 * has its own existing request path already (camera/microphone/screen)
 * or has no genuine app feature behind it at all (Bluetooth, broad
 * storage) and is deliberately NOT exposed here.
 *
 * The request itself is just a single-slot flag on the device row
 * (`pending_permission_request`), picked up by the device's own existing
 * 20s heartbeat poll (Api\Mobile\DeviceController::heartbeat) — no new
 * signaling channel. The device resolves it back through
 * DeviceController::resolvePermissionRequest, which writes into the SAME
 * `android_access` column Remote Support's own consent-sync already
 * owns, so this page's existing access badges reflect the result
 * automatically once the device's next heartbeat lands.
 */
class DevicePermissionController extends Controller
{
    public function request(Request $request, Tenant $tenant, int $device, string $permission)
    {
        abort_unless(in_array($permission, MobileDevice::SUPPORTED_PERMISSION_REQUESTS, true), 404);

        $deviceModel = MobileDevice::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenant->id)
            ->findOrFail($device);

        abort_if($deviceModel->status === MobileDevice::STATUS_REVOKED, 403);

        $access = $deviceModel->android_access ?? [];
        if (($access[$permission] ?? null) === MobileDevice::ACCESS_GRANTED) {
            return back()->with('success', 'এই অনুমতি ইতিমধ্যে দেওয়া আছে — নতুন করে অনুরোধ পাঠানোর প্রয়োজন নেই।');
        }

        $deviceModel->pending_permission_request = [
            'permission' => $permission,
            'requested_by_super_admin_id' => auth('super_admin')->id(),
            'requested_at' => now()->toIso8601String(),
        ];
        $deviceModel->save();

        return back()->with('success', 'অনুরোধ পাঠানো হয়েছে — ডিভাইসের পরবর্তী হার্টবিটে (২০ সেকেন্ডের মধ্যে) এটি পৌঁছাবে।');
    }
}
