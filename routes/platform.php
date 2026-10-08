<?php

/*
|--------------------------------------------------------------------------
| Brand & Entrepreneur Recognition Platform — public + brand owner routes
|--------------------------------------------------------------------------
|
| Required from routes/web.php inside the central-domain group. Every
| route needs database/sql/chunk64.sql (platform.ready). Brand owners use
| their own guard (auth:brand_owner); nothing here can change verification,
| featuring, sponsorship, award results or vote counts — those routes live
| in routes/platform-admin.php behind auth:super_admin.
|
*/

use App\Http\Controllers\BrandOwner\AccountController as OwnerAccountController;
use App\Http\Controllers\BrandOwner\AuthController as OwnerAuthController;
use App\Http\Controllers\BrandOwner\AwardController as OwnerAwardController;
use App\Http\Controllers\BrandOwner\BrandProfileController as OwnerBrandController;
use App\Http\Controllers\BrandOwner\DashboardController as OwnerDashboardController;
use App\Http\Controllers\BrandOwner\NotificationController as OwnerNotificationController;
use App\Http\Controllers\BrandOwner\RegisterController as OwnerRegisterController;
use App\Http\Controllers\BrandOwner\VotingController as OwnerVotingController;
use App\Http\Controllers\Platform\AwardPageController;
use App\Http\Controllers\Platform\BrandDirectoryController;
use App\Http\Controllers\Platform\VoteController;
use Illuminate\Support\Facades\Route;

Route::middleware('platform.ready')->group(function () {
    Route::get('brands', [BrandDirectoryController::class, 'index'])->name('brands.index');
    Route::get('brand/{slug}', [BrandDirectoryController::class, 'show'])->where('slug', '[a-z0-9-]+')->name('brands.show');

    Route::get('vote/{slug}', [VoteController::class, 'show'])->where('slug', '[a-z0-9-]+')->name('vote.show');
    Route::post('vote/{slug}', [VoteController::class, 'cast'])->where('slug', '[a-z0-9-]+')
        ->middleware('throttle:platform-vote')->name('vote.cast');
    Route::get('voting/{slug}', [VoteController::class, 'campaign'])->where('slug', '[a-z0-9-]+')->name('voting.campaign');

    Route::get('awards/{slug}', [AwardPageController::class, 'show'])->where('slug', '[a-z0-9-]+')->name('awards.show');

    Route::get('list-your-brand', [OwnerRegisterController::class, 'show'])->name('owner.register');
    Route::post('list-your-brand', [OwnerRegisterController::class, 'store'])->middleware('throttle:6,10')->name('owner.register.store');

    Route::prefix('brand-owner')->name('owner.')->group(function () {
        Route::get('login', [OwnerAuthController::class, 'show'])->name('login');
        Route::post('login', [OwnerAuthController::class, 'login'])->middleware('throttle:10,1')->name('login.attempt');
        Route::post('logout', [OwnerAuthController::class, 'logout'])->name('logout');

        Route::middleware(['auth:brand_owner', 'owner.active'])->group(function () {
            Route::get('/', [OwnerDashboardController::class, 'index'])->name('dashboard');

            Route::get('brand', [OwnerBrandController::class, 'edit'])->name('brand.edit');
            Route::put('brand', [OwnerBrandController::class, 'update'])->name('brand.update');
            Route::post('brand/gallery', [OwnerBrandController::class, 'addGallery'])->name('brand.gallery.store');
            Route::delete('brand/gallery/{index}', [OwnerBrandController::class, 'removeGallery'])->whereNumber('index')->name('brand.gallery.destroy');
            Route::post('brand/resubmit', [OwnerBrandController::class, 'resubmit'])->name('brand.resubmit');
            Route::delete('brand/change-request', [OwnerBrandController::class, 'cancelChange'])->name('brand.change.cancel');

            Route::get('voting', [OwnerVotingController::class, 'index'])->name('voting');

            Route::get('awards', [OwnerAwardController::class, 'index'])->name('awards');
            Route::post('awards/nominate', [OwnerAwardController::class, 'nominate'])->name('awards.nominate');
            Route::post('awards/nominations/{nomination}/withdraw', [OwnerAwardController::class, 'withdraw'])->whereNumber('nomination')->name('awards.withdraw');

            Route::get('notifications', [OwnerNotificationController::class, 'index'])->name('notifications');
            Route::post('notifications/read', [OwnerNotificationController::class, 'markAllRead'])->name('notifications.read');

            Route::get('account', [OwnerAccountController::class, 'show'])->name('account');
            Route::put('account/password', [OwnerAccountController::class, 'updatePassword'])->name('account.password');
        });
    });
});
