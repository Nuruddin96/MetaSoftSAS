<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * A single, GLOBAL message Super Admin can set for every tenant's
 * Dashboard (database/sql/chunk46.sql) — never tenant-scoped, never
 * duplicated per tenant. See SuperAdmin\AnnouncementController for the
 * singleton upsert (always id=1) and Tenant\DashboardController for how
 * it's read.
 */
class PlatformAnnouncement extends Model
{
    protected $guarded = [];

    public static function tablesReady(): bool
    {
        return Schema::hasTable('platform_announcements');
    }

    /** '' when none is set (or the table isn't imported yet) — a normal case, not an error. */
    public static function current(): string
    {
        if (! self::tablesReady()) {
            return '';
        }

        return (string) (self::query()->value('message') ?? '');
    }
}
