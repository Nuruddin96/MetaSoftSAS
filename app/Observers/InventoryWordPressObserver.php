<?php

namespace App\Observers;

use App\Jobs\SyncStockToWordPress;
use App\Models\Inventory;

class InventoryWordPressObserver
{
    public function saved(Inventory $inventory): void
    {
        SyncStockToWordPress::dispatch($inventory->tenant_id, $inventory->variant_id);
    }
}
