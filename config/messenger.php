<?php

return [
    // Set in Meta App -> Messenger -> Settings -> Webhooks, must match exactly.
    'verify_token' => env('FB_MESSENGER_VERIFY_TOKEN', 'change-this-verify-token'),
];
