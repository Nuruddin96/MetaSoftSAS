<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\PushSubscription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Registers/deregisters this browser's Web Push subscription — the
 * server-side half of resources/js/app.js's push-notifications section.
 * A user may have several active subscriptions at once (phone, tablet,
 * desktop, per Part 9 of the mobile audit); each browser/device gets its
 * own row, keyed by endpoint_hash so re-subscribing the same browser
 * updates rather than duplicates.
 */
class PushSubscriptionController extends Controller
{
    public function store(Request $request)
    {
        if (! PushSubscription::tablesReady()) {
            return response()->json(['message' => 'Push notifications are not set up on this server yet.'], 503);
        }

        $data = $request->validate([
            'subscription.endpoint' => ['required', 'string'],
            'subscription.keys.p256dh' => ['required', 'string'],
            'subscription.keys.auth' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:150'],
        ]);

        $sub = $data['subscription'];
        $user = Auth::guard('tenant')->user();

        PushSubscription::updateOrCreate(
            ['endpoint_hash' => hash('sha256', $sub['endpoint'])],
            [
                'tenant_id' => $user->tenant_id,
                'user_id' => $user->id,
                'endpoint' => $sub['endpoint'],
                'p256dh_key' => $sub['keys']['p256dh'],
                'auth_key' => $sub['keys']['auth'],
                'platform' => 'web',
                'device_name' => $data['device_name'] ?? null,
                'user_agent' => substr((string) $request->userAgent(), 0, 255),
                'last_seen_at' => now(),
                'is_active' => true,
            ]
        );

        return response()->json(['status' => 'subscribed']);
    }

    public function destroy(Request $request)
    {
        if (! PushSubscription::tablesReady()) {
            return response()->json(['status' => 'ok']);
        }

        $data = $request->validate(['endpoint' => ['required', 'string']]);

        // Scoped to the authenticated user's own subscriptions only (plus
        // BelongsToTenant's own tenant scope) — an endpoint string alone is
        // never trusted to identify whose row to touch.
        PushSubscription::where('user_id', Auth::guard('tenant')->user()->id)
            ->where('endpoint_hash', hash('sha256', $data['endpoint']))
            ->update(['is_active' => false]);

        return response()->json(['status' => 'unsubscribed']);
    }
}
