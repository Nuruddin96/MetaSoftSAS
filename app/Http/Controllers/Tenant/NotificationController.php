<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * The notification bell (layouts/panel.blade.php) has no persisted
 * "notification" record to attach a read/unread flag to — its badge counts
 * are computed live from real business state (pending orders, low stock,
 * new Messenger messages, incomplete orders). "Marking as read" here means
 * remembering, per category, when the operator last opened the bell —
 * layouts/panel.blade.php then only counts items newer than that mark as
 * unread. Stored in the session (not a new DB table/column): read-state
 * is inherently per-login-session UI state, not data worth persisting
 * past a logout, and this keeps the fix additive with zero schema change.
 */
class NotificationController extends Controller
{
    protected const CATEGORIES = ['pending_orders', 'low_stock', 'messages', 'incomplete'];

    /** Called via navigator.sendBeacon() when the bell is opened — marks every category seen right now. */
    public function markSeen(Request $request)
    {
        $now = now();

        foreach (self::CATEGORIES as $category) {
            $request->session()->put("notif_seen.$category", $now);
        }

        return response()->json(['ok' => true]);
    }
}
