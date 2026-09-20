<?php

namespace App\Console\Commands;

use App\Models\RemoteSupportSession;
use App\Models\RemoteSupportSignal;
use App\Services\RemoteSupport\RemoteSupportService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Closes the one gap left by RemoteSupportService::startSession()'s own
 * self-heal: that check only ever runs against the SAME device the next
 * time a Super Admin tries to start a session there, so a session abandoned
 * on a device nobody revisits (browser tab closed, admin moved on, device
 * never re-selected) stays `status=active` in the database indefinitely —
 * past its own `expires_at` hard cap — until someone happens to retry that
 * exact device. This runs the identical `isExpired()`/`isLikelyAbandoned()`
 * checks RemoteSupportSession already exposes, on a schedule, across every
 * tenant, independent of any admin action — see routes/console.php for the
 * cron wiring (reuses the existing single `schedule:run` cron entry, no new
 * one needed).
 *
 * Scoped with `withoutGlobalScope('tenant')` the same way
 * RemoteSupportService::registerDevice() already does for MobileDevice —
 * this is a system-level sweep across all tenants, not a request scoped to
 * one, so BelongsToTenant's `currentTenant`-bound scope doesn't apply here.
 */
class SweepStaleRemoteSupportSessions extends Command
{
    protected $signature = 'remote-support:sweep-stale-sessions';

    protected $description = 'Ends Remote Support sessions that expired or were abandoned without ever connecting, on devices nobody has retried since.';

    public function handle(RemoteSupportService $service): int
    {
        $stale = RemoteSupportSession::withoutGlobalScope('tenant')
            ->where('status', '!=', RemoteSupportSession::STATUS_ENDED)
            ->get()
            ->filter(fn (RemoteSupportSession $session) => $session->isExpired() || $this->isTrulyAbandoned($session));

        foreach ($stale as $session) {
            $service->stopSession(
                $session,
                reason: $session->isExpired() ? 'expired' : 'abandoned_no_connection',
                actorType: 'system',
            );
        }

        $this->info("Swept {$stale->count()} stale remote support session(s).");

        return self::SUCCESS;
    }

    /**
     * `isLikelyAbandoned()` alone (90s, no `connected_at`) is right for a
     * device that never even offered — see that method's doc comment. But
     * a session whose device HAS already sent an 'offer' is proven to be
     * mid-negotiation, not dead: pollSignals() replays the full signal
     * history by id regardless of when a viewer starts polling, so a Super
     * Admin who reopens/retries the viewer later can still answer that
     * same offer — UNLESS this sweep has already flipped status to
     * `ended`, which permanently 409-blocks that answer via pushSignal()'s
     * `abort_unless($session->isOpen())`. Give such a session
     * `pending_offer_grace_seconds` (config, comfortably longer than
     * `abandoned_session_grace_seconds`) measured from its OWN most recent
     * offer before treating it as genuinely dead, instead of the ordinary
     * 90-second mark.
     */
    private function isTrulyAbandoned(RemoteSupportSession $session): bool
    {
        if (! $session->isLikelyAbandoned()) {
            return false;
        }

        $lastOfferAt = RemoteSupportSignal::withoutGlobalScope('tenant')
            ->where('remote_support_session_id', $session->id)
            ->where('sender', RemoteSupportSignal::SENDER_DEVICE)
            ->where('type', 'offer')
            ->max('created_at');

        if ($lastOfferAt === null) {
            return true;
        }

        return Carbon::parse($lastOfferAt)
            ->addSeconds((int) config('remote_support.pending_offer_grace_seconds'))
            ->isPast();
    }
}
