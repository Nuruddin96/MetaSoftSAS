<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Services\Inbox\UnifiedInboxService;
use Illuminate\Http\Request;

/**
 * The unified inbox LIST only — Phase 5's canonical merge layer. Opening a
 * specific conversation always hands off to that channel's own native
 * controller/view (MessengerInboxController::show(), unchanged; the new
 * WhatsAppInboxController::show()) — this controller never renders a
 * conversation thread itself.
 */
class InboxController extends Controller
{
    public function index(Request $request, UnifiedInboxService $inbox)
    {
        $channel = $this->normalizeChannel($request->query('channel'));
        $search = $this->normalizeSearch($request->query('search'));
        $unread = $request->boolean('unread');
        $result = $inbox->paginate(null, 20, $channel, $search, $unread);

        return view('tenant.inbox.index', [
            'conversations' => $result['conversations'],
            'nextCursor' => $result['nextCursor'],
            'hasMore' => $result['hasMore'],
            'channel' => $channel,
            'search' => $search,
            'unread' => $unread,
        ]);
    }

    /** "Load more" — keyset cursor pagination, see UnifiedInboxService's docblock for why. */
    public function more(Request $request, UnifiedInboxService $inbox)
    {
        $channel = $this->normalizeChannel($request->query('channel'));
        $search = $this->normalizeSearch($request->query('search'));
        $unread = $request->boolean('unread');
        $result = $inbox->paginate($request->query('cursor'), 20, $channel, $search, $unread);

        return response()->json([
            'conversations' => $result['conversations']->map(fn ($c) => $c->toArray())->values(),
            'next_cursor' => $result['nextCursor'],
            'has_more' => $result['hasMore'],
        ]);
    }

    protected function normalizeChannel(?string $channel): ?string
    {
        return in_array($channel, ['messenger', 'whatsapp'], true) ? $channel : null;
    }

    protected function normalizeSearch(?string $search): ?string
    {
        $search = trim((string) $search);

        return $search === '' ? null : $search;
    }
}
