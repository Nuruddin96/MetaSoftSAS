<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * Key → JSON settings for the recognition platform (database/sql/chunk65.sql),
 * currently only the Super Admin "Homepage" section. get() never throws, so
 * a page rendered before the import simply falls back to its defaults.
 */
class PlatformSetting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = ['value' => 'array'];

    private static array $memo = [];

    public static function get(string $key, array $default = []): array
    {
        if (! array_key_exists($key, self::$memo)) {
            try {
                self::$memo[$key] = Schema::hasTable('platform_settings') ? (self::find($key)?->value ?? []) : [];
            } catch (\Throwable) {
                self::$memo[$key] = [];
            }
        }

        return array_replace($default, self::$memo[$key]);
    }

    public static function put(string $key, array $value): void
    {
        self::updateOrCreate(['key' => $key], ['value' => $value]);
        self::$memo[$key] = $value;
    }

    public static function flush(): void
    {
        self::$memo = [];
    }
}
