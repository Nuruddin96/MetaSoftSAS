<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\RemoteSupportSession;
use App\Services\RemoteSupport\RemoteSupportService;
use Illuminate\Http\Request;

/**
 * Device side of the WebRTC signaling exchange (see
 * database/migrations/..._create_remote_support_signals_table.php's
 * docblock for why this is poll-based REST rather than a WebSocket). Runs
 * under the device credential (`ability:device:signal`); the Super Admin
 * side of the same exchange is SuperAdmin\RemoteSupportController.
 *
 * The route carries the opaque `session_token`, not the sequential id, so
 * a session can't be enumerated/guessed from the URL — but this
 * deliberately does NOT use Laravel implicit route-model binding for it:
 * RemoteSupportSession's BelongsToTenant::resolveRouteBinding() needs a
 * bound `currentTenant` (this route group only runs
 * `ability:device:signal`, not `bind.tenant.token`, so nothing binds it),
 * and would 404 on every request otherwise. Resolved manually here
 * instead, then re-checked against *this* token's own device — never
 * trusting the lookup alone to imply ownership, matching this codebase's
 * "never trust a bound model implicitly" convention.
 */
class SignalController extends Controller
{
    public function __construct(protected RemoteSupportService $service) {}

    public function send(Request $request, string $sessionToken)
    {
        $session = $this->authorizedSession($request, $sessionToken);

        $data = $request->validate([
            'type' => 'required|string|in:offer,answer,ice-candidate,bye',
            'payload' => 'required|string',
        ]);

        $signal = $this->service->pushSignal($session, 'device', $data['type'], $data['payload']);

        return response()->json(['id' => $signal->id], 201);
    }

    public function poll(Request $request, string $sessionToken)
    {
        $session = $this->authorizedSession($request, $sessionToken);

        $since = (int) $request->query('since', 0);
        $signals = $this->service->pollSignals($session, $since, 'device');

        return response()->json([
            'session_status' => $session->fresh()->status,
            'signals' => $signals->map(fn ($s) => ['id' => $s->id, 'type' => $s->type, 'payload' => $s->payload])->values(),
        ]);
    }

    private function authorizedSession(Request $request, string $sessionToken): RemoteSupportSession
    {
        $device = app(DeviceController::class)->deviceFromToken($request);

        $session = RemoteSupportSession::withoutGlobalScope('tenant')
            ->where('session_token', $sessionToken)
            ->firstOrFail();

        abort_unless($session->mobile_device_id === $device->id, 404);

        return $session;
    }
}
