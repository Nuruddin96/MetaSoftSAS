<?php

namespace App\Observers;

use App\Jobs\SyncCategoryToWordPress;
use App\Models\Category;

class CategoryWordPressObserver
{
    public function saved(Category $category): void
    {
        SyncCategoryToWordPress::dispatch($category->tenant_id, $category->id);
    }

    public function deleted(Category $category): void
    {
        SyncCategoryToWordPress::dispatch($category->tenant_id, $category->id, true);
    }
}
