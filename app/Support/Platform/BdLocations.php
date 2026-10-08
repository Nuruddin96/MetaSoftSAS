<?php

namespace App\Support\Platform;

use Illuminate\Support\Facades\DB;

/**
 * Bangladesh divisions → districts, read from the shared bd_divisions /
 * bd_districts reference tables (database/sql/schema.sql).
 */
class BdLocations
{
    private static ?array $map = null;

    /** ['Dhaka' => ['Dhaka', 'Gazipur', …], …] keyed by division name, districts sorted. */
    public static function map(): array
    {
        if (self::$map !== null) {
            return self::$map;
        }

        $rows = DB::table('bd_districts')
            ->join('bd_divisions', 'bd_divisions.id', '=', 'bd_districts.division_id')
            ->orderBy('bd_divisions.name')->orderBy('bd_districts.name')
            ->get(['bd_divisions.name as division', 'bd_districts.name as district']);

        $map = [];
        foreach ($rows as $r) {
            $map[$r->division][] = $r->district;
        }

        return self::$map = $map;
    }

    public static function divisions(): array
    {
        return array_keys(self::map());
    }

    public static function valid(string $division, string $district): bool
    {
        return in_array($district, self::map()[$division] ?? [], true);
    }

    public static function flush(): void
    {
        self::$map = null;
    }
}
