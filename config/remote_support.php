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

    /** Heartbeat gap beyond which a device is considered offline. */
    'offline_after_seconds' => (int) env('REMOTE_SUPPORT_OFFLINE_AFTER_SECONDS', 180),
];
