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

    private static ?bool $reviewReady = null;

    private static ?bool $coverReady = null;

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

    /** Has database/sql/chunk66.sql (owner password reset + admin review fields) been imported? */
    public static function reviewReady(): bool
    {
        if (self::$reviewReady === null) {
            try {
                self::$reviewReady = self::ready()
                    && Schema::hasTable('brand_owner_password_reset_tokens')
                    && Schema::hasColumn('brands', 'contact_checked_at')
                    && Schema::hasColumn('brands', 'verification_basis');
            } catch (\Throwable) {
                self::$reviewReady = false;
            }
        }

        return self::$reviewReady;
    }

    /** Has database/sql/chunk69.sql (brands.cover_path) been imported? */
    public static function coverReady(): bool
    {
        if (self::$coverReady === null) {
            try {
                self::$coverReady = self::ready() && Schema::hasColumn('brands', 'cover_path');
            } catch (Throwable) {
                self::$coverReady = false;
            }
        }

        return self::$coverReady;
    }

    /** Tests create the tables mid-process; let them reset the memoized answer. */
    public static function flush(): void
    {
        self::$ready = null;
        self::$reviewReady = null;
        self::$coverReady = null;
    }
}
