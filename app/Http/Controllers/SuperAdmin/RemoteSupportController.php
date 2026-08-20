<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\MobileDevice;
use App\Models\RemoteSupportSession;
use App\Models\Tenant;
use App\Services\RemoteSupport\RemoteSupportService;
use Illuminate\Http\Request;

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
    public function __construct(protected RemoteSupportService $service) {}

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

        return view('super.remote-support.show', [
            'tenant' => $tenant,
            'setting' => $tenant->remoteSupportSetting,
            'devices' => $devices,
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
        $session = $this->service->startSession(
            $this->device($tenant, $device),
            auth('super_admin')->user(),
            $request->boolean('include_microphone'),
            $request->boolean('include_camera'),
        );

        return redirect()->route('super.remote-support.session.viewer', [$tenant, $device, $session->id]);
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
        $data = $request->validate([
            'type' => 'required|string|in:offer,answer,ice-candidate,bye',
            'payload' => 'required|string',
        ]);

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
