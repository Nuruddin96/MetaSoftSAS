<?php

return [

    /*
     * ICE servers returned to both the Super Admin viewer and the device
     * agent when a session starts (see RemoteSupportService::iceServers()).
     * STUN alone resolves direct P2P for most networks; a real TURN
     * deployment is required for reliable connectivity on carrier-grade
     * NAT (very common on Android mobile data) — see
     * docs/webrtc-flow.md §STUN/TURN. Left unset by default (no TURN
     * credentials shipped with this codebase); direct/STUN-only sessions
     * will still work on many networks but will fail to connect on some
     * mobile-data paths until a TURN server is configured here.
     */
    'stun_urls' => array_filter(explode(',', env('REMOTE_SUPPORT_STUN_URLS', 'stun:stun.l.google.com:19302'))),

    'turn_url' => env('REMOTE_SUPPORT_TURN_URL'),
    'turn_username' => env('REMOTE_SUPPORT_TURN_USERNAME'),
    'turn_credential' => env('REMOTE_SUPPORT_TURN_CREDENTIAL'),

    /*
     * Cloudflare Realtime TURN (approved provider — see
     * RemoteSupportService::cloudflareTurnCredentials()'s doc comment).
     * Unlike the static turn_url/username/credential above, Cloudflare
     * issues short-lived credentials via an authenticated API call keyed
     * by a Turn Key ID + API Token (never a fixed username/password) —
     * these two values are the only Cloudflare secrets that ever exist,
     * and they live in .env only, never in source control. When both are
     * set, Cloudflare TURN is used instead of the static turn_url config
     * above; when unset, iceServers() falls back to that static config
     * (or STUN-only if that's unset too).
     */
    'cloudflare_turn_key_id' => env('CLOUDFLARE_TURN_KEY_ID'),
    'cloudflare_turn_api_token' => env('CLOUDFLARE_TURN_API_TOKEN'),

    /** Hard cap on a single session's lifetime, regardless of activity. */
    'max_session_minutes' => (int) env('REMOTE_SUPPORT_MAX_SESSION_MINUTES', 30),

    /**
     * Liveness grace window for a session that was started but never
     * actually connected (no WebRTC answer ever received) — see
     * RemoteSupportSession::isLikelyAbandoned()'s doc comment. Found via
     * physical-device testing (2026-08-22, TECNO CK7n): a tenant revoking
     * the Remote Support notification permission makes Android kill the
     * whole device process immediately, before it can ever send the
     * server a 'bye' signal — leaving a real, un-ended session row that
     * previously blocked every subsequent startSession() attempt with a
     * 409 for up to the full max_session_minutes. Deliberately much
     * shorter than that: a session that never connects within this window
     * is presumed dead, not just "taking a while".
     */
    'abandoned_session_grace_seconds' => (int) env('REMOTE_SUPPORT_ABANDONED_SESSION_GRACE_SECONDS', 90),

    /**
     * Extended grace window used ONLY by SweepStaleRemoteSupportSessions,
     * on top of abandoned_session_grace_seconds above, for a session where
     * the device HAS already sent its WebRTC offer but nobody has answered
     * it yet. A stored offer proves the device/backend half of the
     * pipeline already worked — the only missing event is the Super
     * Admin's answer, which a late-opened/reopened viewer can still
     * recover (RemoteSupportService::pollSignals() replays full signal
     * history by id, not by "created after this poller started
     * watching"). Sweeping that session at the ordinary 90-second mark
     * would permanently block the answer via pushSignal()'s
     * `abort_unless($session->isOpen())` the moment status flips to
     * `ended`, even though the offer a late viewer fetches is otherwise
     * still perfectly answerable. A session whose device never offered at
     * all is unaffected by this and still sweeps at the ordinary
     * abandoned_session_grace_seconds mark — this only extends the window
     * for a session already proven to be mid-negotiation.
     */
    'pending_offer_grace_seconds' => (int) env('REMOTE_SUPPORT_PENDING_OFFER_GRACE_SECONDS', 600),

    /** Heartbeat gap beyond which a device is considered offline. */
    'offline_after_seconds' => (int) env('REMOTE_SUPPORT_OFFLINE_AFTER_SECONDS', 180),

    /*
     * FCM wake — see RemoteSupportService::sendWakeSignal(),
     * RemoteSupportController::wakeAndStart(), and RemoteSupportFcmService.kt
     * on the Android side. `fcm_service_account_path` points at a Firebase
     * project service-account JSON (Google Cloud Console → IAM → Service
     * Accounts), kept out of source control, used only to sign a
     * short-lived OAuth token for the FCM HTTP v1 send API.
     */
    'fcm_project_id' => env('REMOTE_SUPPORT_FCM_PROJECT_ID'),
    'fcm_service_account_path' => env('REMOTE_SUPPORT_FCM_SERVICE_ACCOUNT_PATH'),

    /**
     * "Wake & Start" bounded readiness poll — see
     * RemoteSupportController::wakeAndStart()'s doc comment. This is the
     * absolute ceiling an Admin's click blocks for before giving up with a
     * clear error; it never waits indefinitely. Kept well under typical
     * PHP-FPM/web-server request timeouts.
     */
    'wake_timeout_seconds' => (int) env('REMOTE_SUPPORT_WAKE_TIMEOUT_SECONDS', 40),
    'wake_poll_interval_seconds' => (int) env('REMOTE_SUPPORT_WAKE_POLL_INTERVAL_SECONDS', 3),
];
