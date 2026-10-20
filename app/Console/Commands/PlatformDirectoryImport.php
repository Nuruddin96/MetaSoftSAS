<?php

namespace App\Console\Commands;

use App\Support\Platform\DirectoryListings;
use App\Support\Platform\PlatformSchema;
use DomainException;
use Illuminate\Console\Command;

/**
 * Loads the researched discovery listings (database/data/brand-directory.json)
 * into brand_directory_listings (chunk70.sql). Dry run unless --apply; safe
 * to re-run — see DirectoryListings::import() for what it never overwrites.
 */
class PlatformDirectoryImport extends Command
{
    protected $signature = 'platform:directory-import
        {--apply : Write the listings (default: show what would change)}
        {--file= : Data file (default: database/data/brand-directory.json)}';

    protected $description = 'Import the researched brand directory listings (never touches registered brands)';

    public function handle(DirectoryListings $directory): int
    {
        if (! PlatformSchema::directoryReady()) {
            $this->warn('brand_directory_listings does not exist — import database/sql/chunk70.sql first.');

            return self::FAILURE;
        }

        try {
            $report = $directory->import($directory->load($this->option('file')), (bool) $this->option('apply'));
        } catch (DomainException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        foreach ($report as [$result, $slug, $detail]) {
            $this->line(str_pad($result, 10).$slug.($detail !== '' ? " — {$detail}" : ''));
        }
        $counts = collect($report)->countBy(0)->map(fn ($n, $k) => "{$n} {$k}")->implode(', ');
        $this->info(($this->option('apply') ? 'Applied: ' : 'Dry run — nothing changed. Re-run with --apply. Would be: ').($counts ?: 'nothing'));

        return self::SUCCESS;
    }
}
