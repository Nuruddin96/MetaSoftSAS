<?php

use App\Http\Controllers\Api\Mobile\AuthController;
use App\Http\Controllers\Api\Mobile\CustomerController;
use App\Http\Controllers\Api\Mobile\DashboardController;
use App\Http\Controllers\Api\Mobile\IncompleteOrderController;
use App\Http\Controllers\Api\Mobile\OrderController;
use App\Http\Controllers\Api\Mobile\ProductController;
use App\Http\Controllers\Api\Mobile\ReferenceDataController;
use Illuminate\Support\Facades\Route;

/**
 * Mobile Business App API — entirely new surface, does not touch/alter
 * anything in routes/web.php. Stateless (Sanctum personal access tokens,
 * no session middleware), so tenant can't be derived from the URL the way
 * the web panel does — see BindTenantFromSanctumUser's docblock.
 */
Route::prefix('mobile/v1')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login']);

    Route::middleware(['auth:sanctum', 'bind.tenant.token'])->group(function () {
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::post('auth/logout', [AuthController::class, 'logout']);

        Route::get('dashboard', [DashboardController::class, 'index']);

        Route::get('orders', [OrderController::class, 'index']);
        Route::post('orders', [OrderController::class, 'store']);
        Route::get('orders/{order}', [OrderController::class, 'show'])->whereNumber('order');
        Route::patch('orders/{order}/status', [OrderController::class, 'updateStatus'])->whereNumber('order');
        Route::post('orders/{order}/courier', [OrderController::class, 'courier'])->whereNumber('order');

        Route::get('incomplete-orders', [IncompleteOrderController::class, 'index']);
        Route::patch('incomplete-orders/{incompleteOrder}/status', [IncompleteOrderController::class, 'updateStatus'])
            ->whereNumber('incompleteOrder');

        Route::get('customers', [CustomerController::class, 'index']);
        Route::post('customers', [CustomerController::class, 'store']);
        Route::get('customers/{customer}', [CustomerController::class, 'show'])->whereNumber('customer');
        Route::post('customers/{customer}/due', [CustomerController::class, 'due'])->whereNumber('customer');
        Route::post('customers/{customer}/due/add', [CustomerController::class, 'addDue'])->whereNumber('customer');

        Route::get('reference/divisions', [ReferenceDataController::class, 'divisions']);
        Route::get('reference/districts', [ReferenceDataController::class, 'districts']);

        Route::get('products', [ProductController::class, 'index']);
    });
});
