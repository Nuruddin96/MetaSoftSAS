<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\NotificationLog;
use App\Services\Notifications\NotificationPreferenceService;
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

    /**
     * Mirrors Tenant\NotificationPreferenceController::edit() — per-category
     * on/off list (App\Services\Notifications\NotificationPreferenceService).
     * 'security' is never included: it's always-on and not a real toggle,
     * same as the web view never rendering a checkbox for it.
     */
    public function preferences(Request $request, NotificationPreferenceService $preferences)
    {
        $categories = $preferences->forUser($request->user());

        return response()->json([
            'data' => collect($categories)->map(fn (array $meta, string $category) => [
                'category' => $category,
                'label' => $meta['label'],
                'icon' => $meta['icon'],
                'enabled' => $meta['enabled'],
            ])->values(),
        ]);
    }

    /**
     * Mirrors Tenant\NotificationPreferenceController::update()'s full-
     * replace semantics exactly: every real category not present in
     * [categories] is explicitly turned off, not left unchanged — a client
     * sending an empty list disables everything (except 'security', which
     * setEnabled() silently ignores regardless of what's submitted).
     */
    public function updatePreferences(Request $request, NotificationPreferenceService $preferences)
    {
        $data = $request->validate([
            'categories' => 'nullable|array',
            'categories.*' => 'string',
        ]);
        $enabled = $data['categories'] ?? [];

        foreach (NotificationPreferenceService::CATEGORIES as $category => $meta) {
            $preferences->setEnabled($request->user(), $category, in_array($category, $enabled, true));
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
