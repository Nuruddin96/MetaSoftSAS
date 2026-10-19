<?php

namespace App\Console\Commands;

use App\Models\Brand;
use App\Models\BrandCategory;
use App\Models\PlatformAuditLog;
use App\Support\Platform\PlatformSchema;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Moves brands out of categories retired by database/sql/chunk68.sql, but
 * only where the old category has exactly one obvious equivalent among the
 * 50 (MAP). Ambiguous ones (e.g. "Services", which could be several of the
 * 50) are listed and left for Super Admin to choose. Dry run unless --apply;
 * every move is audit-logged; no category row is renamed or deleted.
 */
class PlatformMapLegacyCategories extends Command
{
    /** Retired slug => replacement slug, only where the meaning is the same. */
    public const MAP = [
        'fashion-apparel' => 'clothing-fashion',
    ];

    protected $signature = 'platform:map-legacy-categories {--apply : Move the brands (default: show what would change)}';

    protected $description = 'Move brands from retired categories to their unambiguous equivalent among the 50 business categories';

    public function handle(): int
    {
        if (! PlatformSchema::ready()) {
            $this->warn('Platform tables are not imported; nothing to do.');

            return self::SUCCESS;
        }

        $retired = BrandCategory::where('is_active', false)->get()->keyBy('slug');
        $moved = 0;
        foreach (Brand::withTrashed()->whereIn('brand_category_id', $retired->pluck('id'))->with('category')->orderBy('id')->get() as $brand) {
            $from = $brand->category;
            $to = isset(self::MAP[$from->slug]) ? BrandCategory::where('slug', self::MAP[$from->slug])->where('is_active', true)->first() : null;
            if (! $to) {
                $this->line("#{$brand->id} {$brand->name}: \"{$from->name}\" has no unambiguous equivalent — left for Super Admin to choose.");

                continue;
            }

            $this->line("#{$brand->id} {$brand->name}: \"{$from->name}\" → \"{$to->name}\"".($this->option('apply') ? '' : ' (dry run)'));
            if ($this->option('apply')) {
                DB::transaction(function () use ($brand, $from, $to) {
                    $brand->update(['brand_category_id' => $to->id]);
                    PlatformAuditLog::record('brand.category_mapped', $brand,
                        ['brand_category_id' => [$from->id, $to->id], 'from' => $from->name, 'to' => $to->name],
                        'Category retired with the 50 business categories (chunk68.sql); mapped to its direct equivalent.');
                });
                $moved++;
            }
        }

        $this->info($this->option('apply') ? "Moved {$moved} brand(s)." : 'Dry run — nothing changed. Re-run with --apply.');

        return self::SUCCESS;
    }
}
