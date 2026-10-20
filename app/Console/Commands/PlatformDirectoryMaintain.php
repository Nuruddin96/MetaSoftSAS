<?php

namespace App\Console\Commands;

use App\Support\Platform\DirectoryListings;
use App\Support\Platform\PlatformSchema;
use Illuminate\Console\Command;

/**
 * Archives (never deletes) discovery listings past their source re-check
 * date and, only when --category-cap is given, listings beyond a category's
 * capacity once genuine verified brands fill it. Dry run unless --apply.
 * Every archive is audit-logged and can be undone in Super Admin → Directory.
 */
class PlatformDirectoryMaintain extends Command
{
    protected $signature = 'platform:directory-maintain
        {--apply : Archive the listings (default: show what would change)}
        {--category-cap= : Also apply the category capacity rule with this many slots per category}';

    protected $description = 'Archive expired brand directory listings (and over-capacity ones with --category-cap)';

    public function handle(DirectoryListings $directory): int
    {
        if (! PlatformSchema::directoryReady()) {
            $this->warn('brand_directory_listings does not exist; nothing to do.');

            return self::SUCCESS;
        }
        $apply = (bool) $this->option('apply');
        $suffix = $apply ? '' : ' (dry run)';

        foreach ($directory->archiveExpired($apply) as $l) {
            $this->line("expired   {$l->slug} — re-check was due {$l->expires_at->toDateString()}{$suffix}");
        }

        $cap = $this->option('category-cap');
        if ($cap !== null) {
            if (! ctype_digit((string) $cap)) {
                $this->error('--category-cap must be a whole number.');

                return self::FAILURE;
            }
            foreach ($directory->rebalance((int) $cap, $apply) as $l) {
                $this->line("full      {$l->slug} — category capacity reached{$suffix}");
            }
        }

        $this->info($apply ? 'Done.' : 'Dry run — nothing changed. Re-run with --apply.');

        return self::SUCCESS;
    }
}
