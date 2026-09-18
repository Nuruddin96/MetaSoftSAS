<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Root-cause fix for Messenger silently going dark until a manual Facebook
// reconnect (see RefreshFacebookPageSubscriptions's docblock) — refreshes
// long-lived tokens before they expire and re-subscribes connected Pages.
// Daily is well within the ~60-day token lifetime, and deliberately NOT
// per-webhook-event. Requires the standard single Laravel cron entry
// (`* * * * * php artisan schedule:run`) to be configured on the host —
// this file alone does not make the schedule fire.
Schedule::command('facebook:refresh-connections')->daily()->onOneServer();

// AI Customer Support Agent (Phase 1/2) queue processing. Hostinger shared
// hosting cannot run a permanent `queue:work` daemon (no Supervisor/
// systemd), so this drains the 'database' queue every minute instead,
// reusing the exact same single `* * * * * php artisan schedule:run` cron
// entry the task above already requires — no second cron entry needed.
// --stop-when-empty exits as soon as the queue is drained rather than
// polling forever, which is what makes a short-lived, cron-triggered
// invocation safe; --max-time=50 is a hard ceiling comfortably inside this
// scheduled task's own one-minute budget. --tries/--timeout here just
// mirror ProcessAiAgentMessage's own $tries/$timeout properties (which
// take precedence per-job regardless) for anyone reading this line to see
// the intended limits at a glance. onOneServer()/withoutOverlapping() both
// use cache-lock mutexes (CACHE_STORE=database) to guarantee at most one
// worker instance runs at a time even if a previous run somehow overruns.
Schedule::command('queue:work --queue=default --stop-when-empty --max-time=50 --tries=3 --timeout=30')
    ->everyMinute()
    ->onOneServer()
    ->withoutOverlapping();

// Courier status sync — Steadfast (and any other connected provider) has
// no status-change webhook, so this is the "sensible polling/sync
// mechanism" the real-time courier-status feature needs. Every 15 minutes
// is frequent enough for a merchant-facing "latest known status" without
// hammering the courier API across every tenant's pending orders; reuses
// this file's existing single cron entry.
Schedule::command('courier:refresh-statuses')
    ->everyFifteenMinutes()
    ->onOneServer()
    ->withoutOverlapping();

// Remote Support: independent stale-session cleanup — see
// SweepStaleRemoteSupportSessions's own docblock for why this is needed in
// addition to RemoteSupportService::startSession()'s per-device self-heal.
// Every five minutes is comfortably inside abandoned_session_grace_seconds
// (default 90s) and max_session_minutes (default 30) without adding
// meaningful load; reuses this file's existing single cron entry.
Schedule::command('remote-support:sweep-stale-sessions')
    ->everyFiveMinutes()
    ->onOneServer()
    ->withoutOverlapping();

// Unified permission-request lifecycle: same "someone has to notice a
// stale one, independent of any admin revisiting that device" rationale as
// the sweep above — see SweepExpiredPermissionRequests's own docblock.
// Every five minutes is comfortably inside the default 15-minute TTL
// (config('permission_requests.ttl_minutes')).
Schedule::command('permission-requests:sweep-expired')
    ->everyFiveMinutes()
    ->onOneServer()
    ->withoutOverlapping();
