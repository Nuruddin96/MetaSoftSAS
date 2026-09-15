<?php

namespace App\Providers;

use App\Models\Category;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Observers\CategoryWordPressObserver;
use App\Observers\InventoryWordPressObserver;
use App\Observers\ProductVariantWordPressObserver;
use App\Observers\ProductWordPressObserver;
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
        // WordPress integration Phase 4 — push products/categories/stock to
        // a connected WordPress site. Unconditional registration is safe
        // for every tenant, including ones with no WordPress connection at
        // all or on an environment where chunk59.sql isn't imported yet —
        // see each observer's docblock and SyncProductToWordPress's
        // WordPressConnection::tablesReady() guard.
        Product::observe(ProductWordPressObserver::class);
        ProductVariant::observe(ProductVariantWordPressObserver::class);
        Category::observe(CategoryWordPressObserver::class);
        Inventory::observe(InventoryWordPressObserver::class);
    }
}
