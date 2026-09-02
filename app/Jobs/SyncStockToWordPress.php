<?php

namespace App\Jobs;

use App\Models\ProductVariant;
use App\Models\WordPressConnection;
use App\Services\WordPress\WordPressProductSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched by App\Observers\InventoryWordPressObserver on every
 * inventory row save (POS sale, restock, manual adjustment, courier
 * return) — by far the highest-frequency event this integration pushes,
 * hence its own lightweight payload (see WordPressProductSyncService::
 * pushStock()) instead of reusing SyncProductToWordPress's full graph.
 */
class SyncStockToWordPress implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public int $tenantId,
        public int $variantId,
    ) {}

    public function handle(WordPressProductSyncService $sync): void
    {
        if (! WordPressConnection::tablesReady()) {
            return;
        }

        $variant = ProductVariant::withoutGlobalScopes()
            ->where('tenant_id', $this->tenantId)
            ->find($this->variantId);

        if (! $variant) {
            return;
        }

        $sync->pushStock($variant);
    }
}
