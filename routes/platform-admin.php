<?php

/*
|--------------------------------------------------------------------------
| Recognition platform — Super Admin control center
|--------------------------------------------------------------------------
|
| Required from routes/web.php inside the `super-admin` prefix's
| auth:super_admin group, so every route here is `super.*` and
| super-admin-only. Every mutation writes a PlatformAuditLog row.
|
*/

use App\Http\Controllers\SuperAdmin\Platform\AuditController as PlatformAuditController;
use App\Http\Controllers\SuperAdmin\Platform\AwardController as PlatformAwardController;
use App\Http\Controllers\SuperAdmin\Platform\BrandCategoryController as PlatformCategoryController;
use App\Http\Controllers\SuperAdmin\Platform\BrandController as PlatformBrandController;
use App\Http\Controllers\SuperAdmin\Platform\VoteCampaignController as PlatformCampaignController;
use Illuminate\Support\Facades\Route;

Route::middleware('platform.ready')->group(function () {
    Route::get('brands', [PlatformBrandController::class, 'index'])->name('brands.index');
    Route::get('brands/create', [PlatformBrandController::class, 'create'])->name('brands.create');
    Route::post('brands', [PlatformBrandController::class, 'store'])->name('brands.store');
    Route::get('brands/{brand}', [PlatformBrandController::class, 'show'])->whereNumber('brand')->name('brands.show');
    Route::get('brands/{brand}/edit', [PlatformBrandController::class, 'edit'])->whereNumber('brand')->name('brands.edit');
    Route::put('brands/{brand}', [PlatformBrandController::class, 'update'])->whereNumber('brand')->name('brands.update');
    Route::delete('brands/{brand}', [PlatformBrandController::class, 'destroy'])->whereNumber('brand')->name('brands.destroy');
    Route::post('brands/{brand}/status', [PlatformBrandController::class, 'status'])->whereNumber('brand')->name('brands.status');
    Route::post('brands/{brand}/verification', [PlatformBrandController::class, 'verification'])->whereNumber('brand')->name('brands.verification');
    Route::post('brands/{brand}/featured', [PlatformBrandController::class, 'featured'])->whereNumber('brand')->name('brands.featured');
    Route::post('brands/{brand}/sponsorship', [PlatformBrandController::class, 'sponsorship'])->whereNumber('brand')->name('brands.sponsorship');
    Route::post('brands/{brand}/owner-password', [PlatformBrandController::class, 'resetOwnerPassword'])->whereNumber('brand')->name('brands.owner-password');
    Route::post('brands/{brand}/owner-status', [PlatformBrandController::class, 'ownerStatus'])->whereNumber('brand')->name('brands.owner-status');
    Route::get('brand-changes', [PlatformBrandController::class, 'changes'])->name('brands.changes');
    Route::post('brand-changes/{change}/approve', [PlatformBrandController::class, 'approveChange'])->whereNumber('change')->name('brands.changes.approve');
    Route::post('brand-changes/{change}/reject', [PlatformBrandController::class, 'rejectChange'])->whereNumber('change')->name('brands.changes.reject');

    Route::get('brand-categories', [PlatformCategoryController::class, 'index'])->name('brand-categories.index');
    Route::post('brand-categories', [PlatformCategoryController::class, 'store'])->name('brand-categories.store');
    Route::put('brand-categories/{category}', [PlatformCategoryController::class, 'update'])->whereNumber('category')->name('brand-categories.update');
    Route::delete('brand-categories/{category}', [PlatformCategoryController::class, 'destroy'])->whereNumber('category')->name('brand-categories.destroy');
    Route::post('brand-categories/reorder', [PlatformCategoryController::class, 'reorder'])->name('brand-categories.reorder');

    Route::get('awards', [PlatformAwardController::class, 'index'])->name('awards.index');
    Route::get('awards/create', [PlatformAwardController::class, 'create'])->name('awards.create');
    Route::post('awards', [PlatformAwardController::class, 'store'])->name('awards.store');
    Route::get('awards/{award}', [PlatformAwardController::class, 'show'])->whereNumber('award')->name('awards.show');
    Route::get('awards/{award}/edit', [PlatformAwardController::class, 'edit'])->whereNumber('award')->name('awards.edit');
    Route::put('awards/{award}', [PlatformAwardController::class, 'update'])->whereNumber('award')->name('awards.update');
    Route::delete('awards/{award}', [PlatformAwardController::class, 'destroy'])->whereNumber('award')->name('awards.destroy');
    Route::post('awards/{award}/categories', [PlatformAwardController::class, 'storeCategory'])->whereNumber('award')->name('awards.categories.store');
    Route::delete('award-categories/{category}', [PlatformAwardController::class, 'destroyCategory'])->whereNumber('category')->name('awards.categories.destroy');
    Route::post('awards/{award}/nominations', [PlatformAwardController::class, 'storeNomination'])->whereNumber('award')->name('awards.nominations.store');
    Route::put('award-nominations/{nomination}', [PlatformAwardController::class, 'updateNomination'])->whereNumber('nomination')->name('awards.nominations.update');
    Route::post('awards/{award}/recognitions', [PlatformAwardController::class, 'storeRecognition'])->whereNumber('award')->name('awards.recognitions.store');
    Route::delete('award-recognitions/{recognition}', [PlatformAwardController::class, 'destroyRecognition'])->whereNumber('recognition')->name('awards.recognitions.destroy');

    Route::get('campaigns', [PlatformCampaignController::class, 'index'])->name('campaigns.index');
    Route::get('campaigns/create', [PlatformCampaignController::class, 'create'])->name('campaigns.create');
    Route::post('campaigns', [PlatformCampaignController::class, 'store'])->name('campaigns.store');
    Route::get('campaigns/{campaign}', [PlatformCampaignController::class, 'show'])->whereNumber('campaign')->name('campaigns.show');
    Route::get('campaigns/{campaign}/edit', [PlatformCampaignController::class, 'edit'])->whereNumber('campaign')->name('campaigns.edit');
    Route::put('campaigns/{campaign}', [PlatformCampaignController::class, 'update'])->whereNumber('campaign')->name('campaigns.update');
    Route::delete('campaigns/{campaign}', [PlatformCampaignController::class, 'destroy'])->whereNumber('campaign')->name('campaigns.destroy');
    Route::post('campaigns/{campaign}/status', [PlatformCampaignController::class, 'status'])->whereNumber('campaign')->name('campaigns.status');
    Route::post('campaigns/{campaign}/categories', [PlatformCampaignController::class, 'storeCategory'])->whereNumber('campaign')->name('campaigns.categories.store');
    Route::delete('vote-categories/{category}', [PlatformCampaignController::class, 'destroyCategory'])->whereNumber('category')->name('campaigns.categories.destroy');
    Route::post('campaigns/{campaign}/entries', [PlatformCampaignController::class, 'storeEntry'])->whereNumber('campaign')->name('campaigns.entries.store');
    Route::post('vote-entries/{entry}/toggle', [PlatformCampaignController::class, 'toggleEntry'])->whereNumber('entry')->name('campaigns.entries.toggle');
    Route::post('campaigns/{campaign}/import', [PlatformCampaignController::class, 'importFromAward'])->whereNumber('campaign')->name('campaigns.import');
    Route::get('campaigns/{campaign}/votes', [PlatformCampaignController::class, 'votes'])->whereNumber('campaign')->name('campaigns.votes');
    Route::post('campaigns/{campaign}/votes/invalidate', [PlatformCampaignController::class, 'invalidate'])->whereNumber('campaign')->name('campaigns.votes.invalidate');
    Route::post('votes/{vote}/restore', [PlatformCampaignController::class, 'restoreVote'])->whereNumber('vote')->name('campaigns.votes.restore');
    Route::get('campaigns/{campaign}/export', [PlatformCampaignController::class, 'export'])->whereNumber('campaign')->name('campaigns.export');

    Route::get('platform-audit', [PlatformAuditController::class, 'index'])->name('platform-audit');
    Route::get('platform-notifications', [PlatformAuditController::class, 'notifications'])->name('platform-notifications');
    Route::post('platform-notifications/read', [PlatformAuditController::class, 'markRead'])->name('platform-notifications.read');
});
