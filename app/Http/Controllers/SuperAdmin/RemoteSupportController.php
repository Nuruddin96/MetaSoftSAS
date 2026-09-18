<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\MobileDevice;
use App\Models\RemoteSupportSession;
use App\Models\Tenant;
use App\Services\PermissionRequestService;
use App\Services\RemoteSupport\RemoteSupportService;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Remote Support console — Super Admin only (`auth:super_admin`, see
 * routes/web.php). Never linked from, and has no equivalent in, any
 * tenant-facing controller/view (Phase 10 constraint, see
 * docs/remote-support-architecture.md §Tenant-facing visibility).
 *
 * MobileDevice/RemoteSupportSession use BelongsToTenant, whose
 * resolveRouteBinding() depends on a `currentTenant` container binding or
 * a URL-derived tenant slug — neither exists in the super-admin route
 * space (no resolve.tenant middleware runs here) — so this controller
 * deliberately does NOT rely on implicit route-model binding for those two
 * models. Every device/session is resolved manually, scoped explicitly to
 * the `{tenant}` route segment (Tenant itself doesn't use BelongsToTenant
 * — see AGENTS.md — so its own implicit binding is unaffected), matching
 * this codebase's "never trust a bound model's tenant_id implicitly"
 * convention.
 */
class RemoteSupportController extends Controller
{
    public function __construct(protected RemoteSupportService $service, protected PermissionRequestService $permissionRequests) {}

    public function index(Request $request)
    {
        $tenants = Tenant::with('remoteSupportSetting')
            ->withCount(['mobileDevices' => fn ($q) => $q->where('status', '!=', MobileDevice::STATUS_REVOKED)])
            ->when($request->q, fn ($q) => $q->where('store_name', 'like', '%'.$request->q.'%'))
            ->orderBy('store_name')
            ->paginate(25)->withQueryString();

        return view('super.remote-support.index', ['tenants' => $tenants]);
    }

    public function show(Tenant $tenant)
    {
        $devices = $this->devicesFor($tenant)->orderByDesc('last_seen_at')->get();

        // Devices with a session that's still open (not ended, not expired,
        // not the self-heal-eligible "never connected" abandoned case) must
        // link back into that SAME session instead of the list ever
        // offering a fresh Start form for them — see
        // RemoteSupportService::startSession()'s existing-session 409
        // guard, which this UI gap was bypassing by always rendering Start
        // regardless of an already-open session (confirmed 2026-09-06
        // comparing a live Redmi 23027RAD4I session against Tecno CK7n).
        $openSessions = RemoteSupportSession::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenant->id)
            ->whereIn('mobile_device_id', $devices->pluck('id'))
            ->where('status', '!=', RemoteSupportSession::STATUS_ENDED)
            ->get()
            ->filter(fn (RemoteSupportSession $s) => $s->isOpen() && ! $s->isLikelyAbandoned())
            ->keyBy('mobile_device_id');

        // Unified permission-request panel view-model — one entry per
        // capability per device, see PermissionRequestService::panelFor()'s
        // doc comment.
        $permissionPanels = $devices->mapWithKeys(fn (MobileDevice $d) => [$d->id => $this->permissionRequests->panelFor($d)]);

        return view('super.remote-support.show', [
            'tenant' => $tenant,
            'setting' => $tenant->remoteSupportSetting,
            'devices' => $devices,
            'openSessions' => $openSessions,
            'permissionPanels' => $permissionPanels,
        ]);
    }

    public function toggleTenant(Request $request, Tenant $tenant)
    {
        $enabled = $request->boolean('enabled');
        $this->service->setTenantEnabled($tenant, $enabled, auth('super_admin')->user());

        return back()->with('success', $enabled ? 'রিমোট সাপোর্ট চালু করা হয়েছে।' : 'রিমোট সাপোর্ট বন্ধ করা হয়েছে।');
    }

    public function revokeDevice(Request $request, Tenant $tenant, int $device)
    {
        $data = $request->validate(['reason' => 'nullable|string|max:255']);

        $this->service->revokeDevice($this->device($tenant, $device), auth('super_admin')->user(), $data['reason'] ?? null);

        return back()->with('success', 'ডিভাইসের অনুমতি প্রত্যাহার করা হয়েছে।');
    }

    public function toggleDevice(Request $request, Tenant $tenant, int $device)
    {
        $this->service->toggleDevice($this->device($tenant, $device), $request->boolean('enabled'), auth('super_admin')->user());

        return back()->with('success', 'ডিভাইস স্ট্যাটাস আপডেট হয়েছে।');
    }

    public function startSession(Request $request, Tenant $tenant, int $device)
    {
        try {
            $session = $this->service->startSession(
                $this->device($tenant, $device),
                auth('super_admin')->user(),
                $request->boolean('include_microphone'),
                $request->boolean('include_camera'),
                $request->boolean('include_screen'),
                $request->boolean('include_device_audio'),
            );
        } catch (HttpException $e) {
            if ($e->getStatusCode() !== 409) {
                throw $e;
            }

            // Conflict protection itself is untouched — this only surfaces
            // the service's own Bengali message instead of the generic
            // uncustomized-409-view "Oops!" page, matching the
            // back()->with(...) pattern every sibling action here uses.
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('super.remote-support.session.viewer', [$tenant, $device, $session->id]);
    }

    /**
     * "Wake & Start Remote Support" — for a device that's currently
     * offline/not-ready. Sends exactly one FCM wake message (see
     * RemoteSupportService::sendWakeSignal()'s doc comment — this never
     * touches the device's on-device permission flow; it only asks the
     * device's own existing HeadlessEngineHost.startIfNeeded() to resume
     * heartbeat), then polls this SAME device row for a bounded window
     * waiting for it to report itself `on_ready` via its own normal
     * heartbeat — never starting a session before that's genuinely true.
     * Once (and only if) it is, this calls the EXACT SAME
     * RemoteSupportService::startSession() the ordinary Start button uses
     * — no second session-creation path exists. A device that never wakes
     * within the bounded window gets a clear, actionable error instead of
     * this request hanging indefinitely.
     */
    public function wakeAndStart(Request $request, Tenant $tenant, int $device)
    {
        $deviceModel = $this->device($tenant, $device);

        if (! $deviceModel->fcm_token) {
            return back()->with('error', 'ডিভাইসের কোনো FCM টোকেন নেই — ডিভাইসে অ্যাপটি অন্তত একবার খুলে চালু করা প্রয়োজন।');
        }

        if (! $this->service->isWakeConfigured()) {
            return back()->with('error', 'সার্ভারে FCM কনফিগারেশন সেট করা নেই।');
        }

        if (! $this->service->sendWakeSignal($deviceModel)) {
            return back()->with('error', 'ওয়েক সিগন্যাল পাঠানো যায়নি।');
        }

        $timeoutSeconds = (int) config('remote_support.wake_timeout_seconds', 40);
        $pollIntervalSeconds = max(1, (int) config('remote_support.wake_poll_interval_seconds', 3));
        $deadline = now()->addSeconds($timeoutSeconds);

        while (now()->lt($deadline)) {
            sleep($pollIntervalSeconds);
            $deviceModel->refresh();

            if ($deviceModel->isEligibleForSession()) {
                try {
                    $session = $this->service->startSession(
                        $deviceModel,
                        auth('super_admin')->user(),
                        $request->boolean('include_microphone'),
                        $request->boolean('include_camera'),
                    );
                } catch (HttpException $e) {
                    return back()->with('error', $e->getMessage());
                }

                return redirect()->route('super.remote-support.session.viewer', [$tenant, $deviceModel, $session->id]);
            }
        }

        return back()->with('error', 'ডিভাইসটিকে জাগানো যায়নি। অ্যাপের ব্যাকগ্রাউন্ড/অটোস্টার্ট অনুমতি এবং ইন্টারনেট সংযোগ যাচাই করুন।');
    }

    public function viewer(Tenant $tenant, int $device, int $session)
    {
        return view('super.remote-support.viewer', [
            'tenant' => $tenant,
            'device' => $this->device($tenant, $device),
            'session' => $this->session($tenant, $device, $session),
            'iceServers' => $this->service->iceServers(),
        ]);
    }

    public function stopSession(Tenant $tenant, int $device, int $session)
    {
        $this->service->stopSession($this->session($tenant, $device, $session), actorId: auth('super_admin')->id());

        return redirect()->route('super.remote-support.show', $tenant)->with('success', 'সেশন বন্ধ করা হয়েছে।');
    }

    public function sendSignal(Request $request, Tenant $tenant, int $device, int $session)
    {
        // 'reconnect-request' is the admin's manual Reconnect button
        // (viewer.blade.php) — carries an empty payload, relayed to the
        // device as-is via pushSignal() below, and handled entirely by
        // WebRtcSessionController._handleSignal() on the Dart side by
        // re-running the SAME ICE-restart path an automatic reconnect
        // already uses (same session, same PeerConnection, same live
        // capture — never a new session or a fresh consent prompt).
        // 'capability-start'/'capability-stop' are the admin's 4
        // independent capability buttons on an ALREADY-active session
        // (viewer.blade.php) — payload is the plain capability wire
        // string (screen|camera|microphone|device_audio), relayed as-is
        // and handled by WebRtcSessionController.startCapability/
        // stopCapability on the Dart side. See docs/remote-support-architecture.md
        // §Independent capabilities.
        $data = $request->validate([
            'type' => 'required|string|in:offer,answer,ice-candidate,bye,reconnect-request,capability-start,capability-stop',
            'payload' => 'nullable|string',
        ]);
        $data['payload'] ??= '';

        $signal = $this->service->pushSignal($this->session($tenant, $device, $session), 'admin', $data['type'], $data['payload']);

        return response()->json(['id' => $signal->id], 201);
    }

    public function pollSignal(Request $request, Tenant $tenant, int $device, int $session)
    {
        $sessionModel = $this->session($tenant, $device, $session);
        $since = (int) $request->query('since', 0);
        $signals = $this->service->pollSignals($sessionModel, $since, 'admin');

        return response()->json([
            'session_status' => $sessionModel->fresh()->status,
            'signals' => $signals->map(fn ($s) => ['id' => $s->id, 'type' => $s->type, 'payload' => $s->payload])->values(),
        ]);
    }

    private function devicesFor(Tenant $tenant)
    {
        return MobileDevice::withoutGlobalScope('tenant')->where('tenant_id', $tenant->id);
    }

    private function device(Tenant $tenant, int $deviceId): MobileDevice
    {
        return $this->devicesFor($tenant)->findOrFail($deviceId);
    }

    private function session(Tenant $tenant, int $deviceId, int $sessionId): RemoteSupportSession
    {
        return RemoteSupportSession::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenant->id)
            ->where('mobile_device_id', $deviceId)
            ->findOrFail($sessionId);
    }
}
