<?php

namespace App\Console\Commands;

use App\Models\VoteCampaign;
use App\Support\Platform\CampaignLifecycle;
use App\Support\Platform\PlatformSchema;
use Illuminate\Console\Command;

/** Applies date-driven voting starts/ends and sends their owner notifications once. */
class PlatformCampaignTick extends Command
{
    protected $signature = 'platform:campaign-tick';

    protected $description = 'Close expired voting campaigns and notify brand owners when voting starts or ends';

    public function handle(): int
    {
        if (! PlatformSchema::ready()) {
            return self::SUCCESS;
        }

        VoteCampaign::whereIn('status', ['active', 'ended'])
            ->where(fn ($q) => $q->whereNull('started_notified_at')->orWhereNull('ended_notified_at'))
            ->each(fn (VoteCampaign $c) => CampaignLifecycle::sync($c));

        return self::SUCCESS;
    }
}
