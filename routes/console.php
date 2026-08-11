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
