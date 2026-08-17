<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Cloudflare "Custom Hostnames for SaaS" — see
    // App\Services\Domain\CloudflareDomainService's docblock for the full
    // architecture and what this does/doesn't automate. Never hardcoded;
    // when either value is missing the service degrades to a clear
    // "Cloudflare configuration required" state rather than failing
    // silently or pretending success — see that class's isConfigured().
    // fallback_origin is optional (defaults to app.central_domain) — only
    // set it if tenant domains should CNAME to something other than the
    // main site's own hostname.
    'cloudflare' => [
        'token' => env('CLOUDFLARE_API_TOKEN'),
        'zone_id' => env('CLOUDFLARE_ZONE_ID'),
        'fallback_origin' => env('CLOUDFLARE_FALLBACK_ORIGIN'),
    ],

    // Web Push (VAPID) — see App\Services\Notifications\WebPushService.
    // Generate once per environment (never share the private key across
    // environments/tenants) and set as env vars; VAPID_SUBJECT is a
    // contact URI/mailto the push services may use to reach the sender
    // about a misbehaving subscriber, per the Web Push spec.
    'vapid' => [
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
        'subject' => env('VAPID_SUBJECT', 'mailto:support@metasoftbd.com'),
    ],

];
