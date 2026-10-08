<?php

/*
|--------------------------------------------------------------------------
| Central homepage — Entrepreneur & Brand Recognition Platform
|--------------------------------------------------------------------------
|
| The homepage (HomeController) currently renders sample content from
| App\Support\Home\Showcase. While `showcase_preview` is true a visible
| "sample preview" notice is shown and vote buttons explain that votes are
| not recorded yet — so no visitor mistakes placeholder brands or numbers
| for real ones. The homepage sections still read only the showcase, so
| keep this on until they are switched to real data.
|
| The real brand directory, brand profiles, voting pages, brand-owner
| dashboard and Super Admin management (database/sql/chunk64.sql) work
| regardless of this flag.
|
*/

return [
    'showcase_preview' => env('PLATFORM_SHOWCASE_PREVIEW', true),

    // Email copies of owner notifications through the app's existing
    // mailer. In-app notifications are always created.
    'notify_email' => env('PLATFORM_NOTIFY_EMAIL', false),

    /*
    | Public voting anti-abuse thresholds (App\Support\Platform\VoteService).
    | There is no SMS OTP yet: no SMS gateway is configured on this
    | platform, and adding one is a paid-service decision.
    */
    'voting' => [
        // Different phone numbers one device cookie may vote with, per category per period.
        'device_phone_limit' => (int) env('PLATFORM_VOTE_DEVICE_PHONES', 3),
        // Votes from one IP for the same entry within an hour before they get flagged.
        'ip_burst_per_hour' => (int) env('PLATFORM_VOTE_IP_BURST', 10),
        // Votes from one IP per campaign per day before they get flagged (CGNAT-friendly)…
        'ip_daily_soft_limit' => (int) env('PLATFORM_VOTE_IP_SOFT', 40),
        // …and before they are refused outright.
        'ip_daily_hard_limit' => (int) env('PLATFORM_VOTE_IP_HARD', 300),
        // Vote submissions per IP per minute (request throttle).
        'attempts_per_minute' => (int) env('PLATFORM_VOTE_ATTEMPTS', 12),
    ],

    // Optional Cloudflare Turnstile (free) on the vote form — enabled only
    // when both keys are set.
    'turnstile' => [
        'site_key' => env('TURNSTILE_SITE_KEY'),
        'secret' => env('TURNSTILE_SECRET_KEY'),
    ],
];
