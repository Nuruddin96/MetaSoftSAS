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

    /** Heartbeat gap beyond which a device is considered offline. */
    'offline_after_seconds' => (int) env('REMOTE_SUPPORT_OFFLINE_AFTER_SECONDS', 180),
];
