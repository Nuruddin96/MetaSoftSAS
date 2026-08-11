<?php

return [
    // Set in Meta App -> WhatsApp -> Configuration -> Webhook, must match
    // exactly. Deliberately a separate value from messenger.verify_token
    // (config/messenger.php) — different webhook URL, own secret, even
    // though both live under the same Meta App.
    'verify_token' => env('WHATSAPP_VERIFY_TOKEN', 'change-this-verify-token'),

    // Same Meta App Secret already used for the Messenger webhook signature
    // check (config('messenger.app_secret')) and Facebook OAuth
    // (config('facebook.app_secret')) — one Meta App serves every tenant's
    // WhatsApp connection too, this is not a separate credential. Required
    // — receive() rejects everything if this isn't set.
    'app_secret' => env('FB_APP_SECRET'),

    // Phase 3 (send service). Own env var so it can be bumped independently
    // of Messenger/Facebook OAuth's FB_GRAPH_VERSION, but falls back to that
    // same value (then a hard default) since in practice one Meta App
    // usually tracks one Graph version across every product it uses.
    'graph_version' => env('WHATSAPP_GRAPH_VERSION', env('FB_GRAPH_VERSION', 'v26.0')),

    // Phase 4 (Embedded Signup). The Meta App ID/Secret themselves are
    // deliberately NOT duplicated here — config('facebook.app_id')/
    // config('facebook.app_secret') are reused as-is (same "one Meta App
    // serves every tenant" rule as app_secret above). This is the one value
    // Embedded Signup needs that has no Facebook OAuth equivalent: the
    // WhatsApp Signup configuration created in Meta App -> WhatsApp ->
    // Configuration -> Embedded Signup, which controls what the popup shows
    // and what permissions it requests.
    'embedded_signup_config_id' => env('WHATSAPP_EMBEDDED_SIGNUP_CONFIG_ID'),

    // How long a WhatsAppOauthState stays valid before the tenant must
    // restart the connect flow — same role/default as
    // facebook.state_ttl_minutes, own env var since Embedded Signup's state
    // lifetime (bounded by how long a tenant takes to complete Meta's popup)
    // doesn't have to track Facebook's redirect-based flow.
    'state_ttl_minutes' => (int) env('WHATSAPP_OAUTH_STATE_TTL_MINUTES', 10),
];
