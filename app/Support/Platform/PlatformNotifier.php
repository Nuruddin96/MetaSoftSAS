<?php

namespace App\Support\Platform;

use App\Models\Brand;
use App\Models\BrandOwner;
use App\Models\PlatformNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Creates in-app notifications for brand owners and Super Admins. Email
 * copies to owners go out only when config('platform.notify_email') is on
 * (it uses the app's existing mailer — no new third-party service), and a
 * mail failure never blocks the action that triggered it.
 */
class PlatformNotifier
{
    public static function owner(Brand|BrandOwner|null $to, string $type, string $title, ?string $body = null, ?string $url = null): void
    {
        $owner = $to instanceof Brand ? $to->owner : $to;
        if (! $owner) {
            return;
        }

        PlatformNotification::create([
            'recipient_type' => 'owner',
            'recipient_id' => $owner->id,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'url' => $url,
        ]);

        if (config('platform.notify_email') && $owner->email) {
            try {
                Mail::raw(trim($title."\n\n".$body."\n\n".($url ?? route('owner.dashboard'))."\n\n— MetaSoft BD"), function ($m) use ($owner, $title) {
                    $m->to($owner->email, $owner->name)->subject($title.' — MetaSoft BD');
                });
            } catch (\Throwable $e) {
                Log::warning('Platform owner email failed: '.$e->getMessage(), ['owner_id' => $owner->id, 'type' => $type]);
            }
        }
    }

    public static function admins(string $type, string $title, ?string $body = null, ?string $url = null): void
    {
        PlatformNotification::create([
            'recipient_type' => 'admin',
            'recipient_id' => null,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'url' => $url,
        ]);
    }
}
