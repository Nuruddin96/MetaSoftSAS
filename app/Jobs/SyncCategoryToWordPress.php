<?php

namespace App\Jobs;

use App\Models\Category;
use App\Models\WordPressConnection;
use App\Services\WordPress\WordPressProductSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Dispatched by App\Observers\CategoryWordPressObserver — see SyncProductToWordPress's docblock for the plain-identifier-payload reasoning. */
class SyncCategoryToWordPress implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public int $tenantId,
        public int $categoryId,
        public bool $deleted = false,
    ) {}

    public function handle(WordPressProductSyncService $sync): void
    {
        if (! WordPressConnection::tablesReady()) {
            return;
        }

        if ($this->deleted) {
            $sync->deleteCategory($this->tenantId, $this->categoryId);

            return;
        }

        $category = Category::withoutGlobalScopes()
            ->where('tenant_id', $this->tenantId)
            ->find($this->categoryId);

        if (! $category) {
            return;
        }

        $sync->pushCategory($category);
    }
}
