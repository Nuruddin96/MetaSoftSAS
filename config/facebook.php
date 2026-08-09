<?php

return [
    // Meta App ID/Secret. app_secret is the SAME value already used for the
    // Messenger webhook signature check (config('messenger.app_secret')) —
    // one Meta App serves every tenant, this is not a separate credential.
    'app_id' => env('FB_APP_ID'),
    'app_secret' => env('FB_APP_SECRET'),

    'graph_version' => env('FB_GRAPH_VERSION', 'v26.0'),

    // Must exactly match a "Valid OAuth Redirect URI" registered in the
    // Meta App dashboard — no wildcards are possible, so this is one fixed
    // central URL, never tenant-prefixed. Falls back to the named route so
    // a single APP_URL change doesn't require touching two places.
    'redirect_uri' => env('FB_OAUTH_REDIRECT_URI'),

    'scopes' => array_filter(explode(',', env('FB_OAUTH_SCOPES', 'pages_show_list,pages_manage_metadata,pages_messaging'))),

    'state_ttl_minutes' => (int) env('FB_OAUTH_STATE_TTL_MINUTES', 10),
];
