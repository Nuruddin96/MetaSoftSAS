<?php

namespace App\Support\Platform;

use Illuminate\Support\Facades\Schema;

/**
 * "Has database/sql/chunk64.sql been imported?" — the single readiness
 * check for the recognition platform, same pattern as
 * FacebookPage::tablesReady(). Code that lands before the SQL import
 * shows a friendly "coming soon" page instead of an SQL error.
 */
class PlatformSchema
{
    private static ?bool $ready = null;

    public static function ready(): bool
    {
        if (self::$ready === null) {
            try {
                self::$ready = Schema::hasTable('brands') && Schema::hasTable('votes') && Schema::hasTable('platform_audit_logs');
            } catch (\Throwable) {
                self::$ready = false;
            }
        }

        return self::$ready;
    }

    /** Tests create the tables mid-process; let them reset the memoized answer. */
    public static function flush(): void
    {
        self::$ready = null;
    }
}
