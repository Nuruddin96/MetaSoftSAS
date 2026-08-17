<?php

return [
    // Set in Meta App -> Messenger -> Settings -> Webhooks, must match exactly.
    'verify_token' => env('FB_MESSENGER_VERIFY_TOKEN', 'change-this-verify-token'),

    // Meta App -> Settings -> Basic -> App Secret. Used to verify the
    // X-Hub-Signature-256 header Meta sends on every webhook POST, so a
    // request naming a real connected page_id can't be forged from outside
    // Meta. Required — receive() rejects everything if this isn't set.
    'app_secret' => env('FB_APP_SECRET'),

    // How long a Messenger customer identity (name + profile photo,
    // FacebookMessengerCustomerService) is trusted before it's refetched
    // from Graph — see MessengerCustomer/needsRefresh(). Keeps ordinary
    // message traffic from calling Graph on every message once an identity
    // is known, while still picking up a customer's occasional name/photo
    // change without a manual "Refresh Profile" click.
    'profile_refresh_hours' => (int) env('MESSENGER_PROFILE_REFRESH_HOURS', 24),
];
