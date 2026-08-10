<?php

namespace App\Console\Commands;

use App\Models\FacebookPage;
use App\Services\Facebook\FacebookOAuthService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * One-time backfill after message_echoes was added to
 * FacebookOAuthService::subscribePageToWebhook()'s subscribed_fields —
 * Pages connected before that change are still subscribed with the old
 * field list and won't receive echo events until re-subscribed. New
 * connections already get the current field list automatically via
 * FacebookConnectController::connect(); this command only needs to run
 * once per environment to backfill Pages connected before this change
 * shipped. Manual-only — never invoked from a request/webhook path.
 */
class ResubscribeFacebookPages extends Command
{
    protected $signature = 'facebook:resubscribe-pages {--dry-run : List pages without calling Meta}';

    protected $description = 'Re-subscribes already-connected Facebook Pages to the webhook with the current field list (e.g. after adding message_echoes)';

    public function handle(FacebookOAuthService $fb): int
    {
        if (! FacebookPage::tablesReady()) {
            $this->error('Facebook OAuth tables are not fully migrated yet — nothing to do.');

            return self::FAILURE;
        }

        $pages = FacebookPage::withoutGlobalScopes()
            ->where('is_active', 1)
            ->where('status', 'active')
            ->get();

        if ($pages->isEmpty()) {
            $this->info('No active connected Pages found.');

            return self::SUCCESS;
        }

        $this->info("Found {$pages->count()} active connected Page(s).");

        foreach ($pages as $page) {
            if ($this->option('dry-run')) {
                $this->line("[dry-run] would resubscribe: tenant_id={$page->tenant_id} page_id={$page->page_id}");

                continue;
            }

            try {
                $subscribed = $fb->subscribePageToWebhook($page->page_id, $page->page_access_token);
            } catch (\Throwable $e) {
                $subscribed = false;
                Log::error('facebook:resubscribe-pages failed for a page.', [
                    'tenant_id' => $page->tenant_id,
                    'page_id' => $page->page_id,
                    'error' => $e->getMessage(),
                ]);
            }

            if ($subscribed) {
                $this->info("  OK   tenant_id={$page->tenant_id} page_id={$page->page_id}");
            } else {
                $this->warn(" FAIL  tenant_id={$page->tenant_id} page_id={$page->page_id} — see log");
            }
        }

        return self::SUCCESS;
    }
}
