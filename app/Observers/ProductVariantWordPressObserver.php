<?php

namespace App\Observers;

use App\Jobs\SyncProductToWordPress;
use App\Models\ProductVariant;

/**
 * A variant has no independent existence on the WooCommerce side (it's
 * pushed as part of its parent product's `variants` array — see
 * WordPressProductSyncService::productPayload()), so both save and delete
 * resync the whole parent product rather than needing their own
 * variant-shaped sync path.
 */
class ProductVariantWordPressObserver
{
    public function saved(ProductVariant $variant): void
    {
        SyncProductToWordPress::dispatch($variant->tenant_id, $variant->product_id);
    }

    public function deleted(ProductVariant $variant): void
    {
        SyncProductToWordPress::dispatch($variant->tenant_id, $variant->product_id);
    }
}
