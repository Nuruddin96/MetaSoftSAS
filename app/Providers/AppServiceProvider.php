<?php

namespace App\Providers;

use App\Services\Domain\DomainDriver;
use App\Services\Domain\ManualProvisionDriver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(DomainDriver::class, function () {
            return match (config('domains.driver', 'manual')) {
                default => new ManualProvisionDriver,
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
