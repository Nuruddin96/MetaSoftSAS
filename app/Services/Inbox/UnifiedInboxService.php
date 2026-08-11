<?php

namespace App\Services\Inbox;

use App\Models\MessengerMessage;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppPhoneNumber;
use Illuminate\Support\Collection;

/**
 * Read-model/query layer that merges Messenger + WhatsApp conversations for
 * the unified inbox list — a pure query-and-merge service, never a table.
 * messenger_messages and whatsapp_messages stay completely independent;
 * nothing here writes to either table or changes either channel's own
 * behavior. See PROJECT_KNOWLEDGE.md / the Phase 5 design note for why a
 * SQL UNION and a shared physical table were both rejected in favor of this
 * approach.
 *
 * Runs inside the normal tenant panel context — every query below relies on
 * BelongsToTenant's global scope (app('currentTenant') already bound by
 * resolve.tenant), the same tenant-isolation mechanism every other
 * tenant-scoped controller in this codebase uses. Nothing here ever calls
 * withoutGlobalScopes().
 */
class UnifiedInboxService
{
    /**
     * @return array{conversations: Collection<int, UnifiedConversation>, nextCursor: ?string, hasMore: bool}
     */
    public function paginate(?string $cursor, int $perPage = 20, ?string $channelFilter = null): array
    {
        [$cursorAt, $cursorId] = $this->decodeCursor($cursor);

        $messenger = ($channelFilter && $channelFilter !== 'messenger')
            ? collect()
            : $this->fetchMessengerCandidates($cursorAt, $cursorId, $perPage);

        // Guarded the same way every other WhatsApp-aware call site in this
        // codebase is (chunk26.sql may not be imported yet on a given
        // environment) — degrades to "no WhatsApp conversations" instead of
        // a raw SQL error, so the unified inbox (and Messenger's own
        // conversations within it) keep working for a tenant who hasn't
        // reached WhatsApp at all yet.
        $whatsapp = ($channelFilter && $channelFilter !== 'whatsapp') || ! WhatsAppPhoneNumber::tablesReady()
            ? collect()
            : $this->fetchWhatsAppCandidates($cursorAt, $cursorId, $perPage);

        // Both inputs are already sorted DESC individually — a stable sort
        // on a zero-padded (timestamp, id) composite key gives a correct
        // combined ordering without needing a custom merge routine. Never
        // more than 2*$perPage rows are ever held in memory here, regardless
        // of how many total messages either table has — see the class
        // docblock / Phase 5 design note for why this bound holds across
        // pages too (a channel's un-displayed leftover candidates are
        // simply re-fetched on the next call, since they still sit before
        // the next cursor).
        $merged = $messenger->concat($whatsapp)
            ->sortByDesc(fn (UnifiedConversation $c) => sprintf('%020d%020d', $c->lastMessageAt->timestamp, $c->lastMessageId))
            ->values();

        $page = $merged->take($perPage);

        // Conservative "maybe more" signal, not an exact count — avoids a
        // separate COUNT(*) query per channel per request. Worst case a
        // "load more" click returns an empty next page, which is a harmless
        // UI no-op, not a correctness issue.
        $hasMore = $merged->count() > $page->count()
            || $messenger->count() >= $perPage
            || $whatsapp->count() >= $perPage;

        return [
            'conversations' => $page,
            'nextCursor' => $hasMore ? $page->last()?->cursorToken() : null,
            'hasMore' => $hasMore,
        ];
    }

    /** Sum of both channels' unread-inbound counts — same definition Messenger's own nav badge already uses, applied per channel and summed. */
    public function unreadCount(): int
    {
        $count = MessengerMessage::where('status', 'new')->where('direction', 'in')->count();

        if (WhatsAppPhoneNumber::tablesReady()) {
            $count += WhatsAppMessage::where('status', 'new')->where('direction', 'in')->count();
        }

        return $count;
    }

    /**
     * @return Collection<int, UnifiedConversation>
     */
    protected function fetchMessengerCandidates(?int $cursorAt, ?int $cursorId, int $limit): Collection
    {
        $query = MessengerMessage::query()
            ->selectRaw('sender_psid, MAX(id) as latest_id, MAX(created_at) as last_at')
            ->groupBy('sender_psid');

        $this->applyCursorHaving($query, $cursorAt, $cursorId);

        $candidates = $query->orderByRaw('last_at DESC, latest_id DESC')->limit($limit)->get();

        if ($candidates->isEmpty()) {
            return collect();
        }

        $rows = MessengerMessage::whereIn('id', $candidates->pluck('latest_id'))->get()->keyBy('id');
        $psids = $candidates->pluck('sender_psid');

        // Same "don't trust the latest row's own customer_name — it might
        // be an outbound row that never carries one" reasoning as
        // MessengerInboxController::applyResolvedNames().
        $resolvedNames = MessengerMessage::whereIn('sender_psid', $psids)
            ->whereNotNull('customer_name')->orderByDesc('id')
            ->get(['sender_psid', 'customer_name'])->unique('sender_psid')
            ->pluck('customer_name', 'sender_psid');

        $unreadCounts = MessengerMessage::whereIn('sender_psid', $psids)
            ->where('status', 'new')->where('direction', 'in')
            ->selectRaw('sender_psid, COUNT(*) as c')->groupBy('sender_psid')->pluck('c', 'sender_psid');

        return $candidates->map(function ($c) use ($rows, $resolvedNames, $unreadCounts) {
            $row = $rows->get($c->latest_id);

            return new UnifiedConversation(
                channel: 'messenger',
                externalCustomerId: $c->sender_psid,
                customerName: $resolvedNames->get($c->sender_psid) ?: $row->customer_name,
                lastMessageText: $row->message_text,
                lastMessageAttachmentType: $row->attachment_type, // null-safe: missing column just reads as null, same as everywhere else this project reads it
                lastMessageDirection: $row->direction,
                lastMessageAt: $row->created_at,
                lastMessageId: $row->id,
                status: $row->status,
                unreadCount: (int) ($unreadCounts->get($c->sender_psid) ?? 0),
                showUrl: route('tenant.messenger.show', $c->sender_psid),
            );
        })->values();
    }

    /**
     * @return Collection<int, UnifiedConversation>
     */
    protected function fetchWhatsAppCandidates(?int $cursorAt, ?int $cursorId, int $limit): Collection
    {
        $query = WhatsAppMessage::query()
            ->selectRaw('wa_id, MAX(id) as latest_id, MAX(created_at) as last_at')
            ->groupBy('wa_id');

        $this->applyCursorHaving($query, $cursorAt, $cursorId);

        $candidates = $query->orderByRaw('last_at DESC, latest_id DESC')->limit($limit)->get();

        if ($candidates->isEmpty()) {
            return collect();
        }

        $rows = WhatsAppMessage::whereIn('id', $candidates->pluck('latest_id'))->get()->keyBy('id');
        $waIds = $candidates->pluck('wa_id');

        $resolvedNames = WhatsAppMessage::whereIn('wa_id', $waIds)
            ->whereNotNull('customer_name')->orderByDesc('id')
            ->get(['wa_id', 'customer_name'])->unique('wa_id')
            ->pluck('customer_name', 'wa_id');

        $unreadCounts = WhatsAppMessage::whereIn('wa_id', $waIds)
            ->where('status', 'new')->where('direction', 'in')
            ->selectRaw('wa_id, COUNT(*) as c')->groupBy('wa_id')->pluck('c', 'wa_id');

        return $candidates->map(function ($c) use ($rows, $resolvedNames, $unreadCounts) {
            $row = $rows->get($c->latest_id);

            return new UnifiedConversation(
                channel: 'whatsapp',
                externalCustomerId: $c->wa_id,
                customerName: $resolvedNames->get($c->wa_id) ?: $row->customer_name,
                lastMessageText: $row->message_text,
                lastMessageAttachmentType: $row->attachment_type,
                lastMessageDirection: $row->direction,
                lastMessageAt: $row->created_at,
                lastMessageId: $row->id,
                status: $row->status,
                unreadCount: (int) ($unreadCounts->get($c->wa_id) ?? 0),
                showUrl: route('tenant.whatsapp.show', $c->wa_id),
            );
        })->values();
    }

    protected function applyCursorHaving($query, ?int $cursorAt, ?int $cursorId): void
    {
        if ($cursorAt === null) {
            return;
        }

        $cursorAtFormatted = date('Y-m-d H:i:s', $cursorAt);

        $query->havingRaw('(MAX(created_at) < ?) OR (MAX(created_at) = ? AND MAX(id) < ?)', [
            $cursorAtFormatted, $cursorAtFormatted, $cursorId,
        ]);
    }

    /** @return array{0: ?int, 1: ?int} */
    protected function decodeCursor(?string $cursor): array
    {
        if (! $cursor || ! str_contains($cursor, ':')) {
            return [null, null];
        }

        [$at, $id] = explode(':', $cursor, 2);

        return [(int) $at, (int) $id];
    }
}
