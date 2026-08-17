<?php

namespace App\Providers;

use App\Services\AI\Providers\AiProviderInterface;
use App\Services\AI\Providers\OpenAiProvider;
use App\Services\AI\Tools\AiToolRegistry;
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

        $this->app->bind(AiProviderInterface::class, function () {
            return match (config('ai.provider', 'openai')) {
                default => new OpenAiProvider,
            };
        });

        // Singleton — the registry's tool list is static config, no
        // reason to re-resolve every tool class on every injection.
        $this->app->singleton(AiToolRegistry::class, function ($app) {
            return new AiToolRegistry(array_map(
                fn (string $class) => $app->make($class),
                config('ai.tools', [])
            ));
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
