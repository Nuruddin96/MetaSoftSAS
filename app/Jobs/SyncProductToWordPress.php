<?php

namespace App\Jobs;

use App\Models\Product;
use App\Models\WordPressConnection;
use App\Services\WordPress\WordPressProductSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched by App\Observers\ProductWordPressObserver /
 * ProductVariantWordPressObserver whenever a product or one of its
 * variants is saved/deleted. Receives plain identifiers, not the Eloquent
 * model itself — same reasoning as ProcessAiAgentMessage's docblock: the
 * database queue driver serializes the whole payload as JSON, and a
 * worker picking this up moments later should see fresh state, not
 * whatever the model looked like at dispatch time.
 */
class SyncProductToWordPress implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public int $tenantId,
        public int $productId,
        public bool $deleted = false,
    ) {}

    public function handle(WordPressProductSyncService $sync): void
    {
        // Cheapest possible no-op for every tenant without chunk59.sql
        // imported yet — must run before any query touches
        // wordpress_connections. See WordPressConnection::tablesReady()'s
        // docblock.
        if (! WordPressConnection::tablesReady()) {
            return;
        }

        if ($this->deleted) {
            $sync->deleteProduct($this->tenantId, $this->productId);

            return;
        }

        $product = Product::withoutGlobalScopes()
            ->where('tenant_id', $this->tenantId)
            ->with(['variants', 'images', 'category'])
            ->find($this->productId);

        // Deleted (or never existed) by the time this job actually ran —
        // nothing to push. A genuine delete dispatches with deleted=true
        // separately (see the observer), so this is not that case being
        // missed, just a harmless race with a since-superseded save.
        if (! $product) {
            return;
        }

        $sync->pushProduct($product);
    }
}
