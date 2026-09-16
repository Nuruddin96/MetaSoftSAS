<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * A single, GLOBAL Android APK update config Super Admin sets for the
 * mobile Business App — same "singleton settings, always id=1" shape as
 * PlatformAnnouncement (see that model's docblock), never tenant-scoped:
 * one release applies to every tenant's copy of the app.
 *
 * Read by the public, unauthenticated Api\Mobile\AppUpdateController —
 * see that controller's docblock for why every field here is safe to
 * expose as-is (nothing here is a credential or server-internal detail).
 */
class AppUpdateConfig extends Model
{
    protected $guarded = [];

    protected $casts = [
        'latest_build' => 'integer',
        'minimum_supported_build' => 'integer',
        'force_update' => 'boolean',
    ];

    public static function tablesReady(): bool
    {
        return Schema::hasTable('app_update_configs');
    }

    /**
     * The one row (id=1) — creates it on first read so Super Admin's
     * settings page always has something to show/edit. Explicit default
     * values here, not just the migration's column defaults: Eloquent's
     * `firstOrCreate` does not re-fetch a freshly inserted row, so an
     * in-memory model built from a sparse insert would otherwise report
     * these columns as null in PHP even though the DB row itself carries
     * the schema default.
     */
    public static function current(): self
    {
        return self::query()->firstOrCreate(['id' => 1], [
            'latest_version' => '1.0.0',
            'latest_build' => 1,
            'minimum_supported_version' => '1.0.0',
            'minimum_supported_build' => 1,
            'force_update' => false,
        ]);
    }

    /** Stable HTTPS URL for the currently-configured APK, or null if none has been uploaded yet — never a raw filesystem path. */
    public function apkUrl(): ?string
    {
        return $this->apk_path ? Storage::disk('public')->url($this->apk_path) : null;
    }
}
