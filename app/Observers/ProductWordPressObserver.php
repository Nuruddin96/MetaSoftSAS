<?php

namespace App\Observers;

use App\Jobs\SyncProductToWordPress;
use App\Models\Product;

/**
 * Registered in AppServiceProvider::boot(). Fires for every tenant's
 * every product save/delete regardless of whether that tenant has
 * WordPress connected — the dispatched job's very first check
 * (WordPressConnection::tablesReady(), then WordPressProductSyncService::
 * connectionFor()) is what makes that a cheap no-op for everyone else, so
 * this observer itself stays unconditional and simple.
 */
class ProductWordPressObserver
{
    public function saved(Product $product): void
    {
        SyncProductToWordPress::dispatch($product->tenant_id, $product->id);
    }

    public function deleted(Product $product): void
    {
        SyncProductToWordPress::dispatch($product->tenant_id, $product->id, true);
    }
}
