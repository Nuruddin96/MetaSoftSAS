<?php

return [
    // Set in Meta App -> Messenger -> Settings -> Webhooks, must match exactly.
    'verify_token' => env('FB_MESSENGER_VERIFY_TOKEN', 'change-this-verify-token'),

    // Meta App -> Settings -> Basic -> App Secret. Used to verify the
    // X-Hub-Signature-256 header Meta sends on every webhook POST, so a
    // request naming a real connected page_id can't be forged from outside
    // Meta. Required — receive() rejects everything if this isn't set.
    'app_secret' => env('FB_APP_SECRET'),
];
