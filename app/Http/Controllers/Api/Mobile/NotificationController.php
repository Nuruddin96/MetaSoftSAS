<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\NotificationLog;
use Illuminate\Http\Request;

/**
 * The real, durable, per-user notification log (`NotificationLog`, table
 * `notifications` — database/sql/chunk31.sql), written by
 * `WebPushService::sendToUser()` on real business events. That table's own
 * doc comment already anticipated this: "gives... any future in-app
 * notification list something to reference/mark-read... migrating the
 * existing working [web] bell fully onto this table is a separate, later
 * piece" — this controller is that later piece, for mobile. The web bell
 * itself (`Tenant\NotificationController`, session-based "seen" marks) is
 * untouched — a genuinely different, older mechanism, not migrated here.
 *
 * Scoped by BOTH tenant_id (BelongsToTenant) AND user_id — notifications
 * are written per-user (`WebPushService::sendToUser(User $user, ...)`), so
 * one tenant's staff must never see each other's notification log.
 */
class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $userId = $request->user()->id;

        $notifications = NotificationLog::where('user_id', $userId)->latest()->paginate(30);

        return response()->json([
            'data' => $notifications->getCollection()->map(fn (NotificationLog $n) => $this->present($n))->all(),
            'meta' => [
                'current_page' => $notifications->currentPage(),
                'last_page' => $notifications->lastPage(),
                'per_page' => $notifications->perPage(),
                'total' => $notifications->total(),
                'unread_count' => NotificationLog::where('user_id', $userId)->whereNull('read_at')->count(),
            ],
        ]);
    }

    public function markSeen(Request $request)
    {
        $userId = $request->user()->id;
        $data = $request->validate([
            'all' => 'nullable|boolean',
            'ids' => 'nullable|array',
            'ids.*' => 'integer',
        ]);

        $query = NotificationLog::where('user_id', $userId)->whereNull('read_at');

        if (! empty($data['all'])) {
            $query->update(['read_at' => now()]);
        } elseif (! empty($data['ids'])) {
            $query->whereIn('id', $data['ids'])->update(['read_at' => now()]);
        }

        return response()->json(['ok' => true]);
    }

    protected function present(NotificationLog $n): array
    {
        return [
            'id' => (string) $n->id,
            'type' => $n->category,
            'title' => $n->title,
            'body' => $n->body,
            'url' => $n->url,
            'created_at' => $n->created_at?->toIso8601String(),
            'is_read' => $n->read_at !== null,
        ];
    }
}
