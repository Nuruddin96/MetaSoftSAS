<?php

namespace Tests\Feature\AiAgent\Tools;

use App\Services\AI\Tools\AiMutatingTool;
use App\Services\AI\Tools\AiToolRegistry;
use App\Services\AI\Tools\CourierActionTool;
use App\Services\AI\Tools\CreateOrderTool;
use App\Services\AI\Tools\CreateProductTool;
use App\Services\AI\Tools\CustomerLookupTool;
use App\Services\AI\Tools\OrderLookupTool;
use App\Services\AI\Tools\ProductLookupTool;
use App\Services\AI\Tools\SalesReportTool;
use App\Services\AI\Tools\UpdateOrderStatusTool;
use Tests\TestCase;

/**
 * Covers the real container binding (AppServiceProvider) rather than a
 * manually-constructed AiToolRegistry — proves config('ai.tools') is
 * actually what populates the registry the rest of the app would get via
 * app(AiToolRegistry::class), and that every tool (Phase 3's read-only
 * lookups + Phase 5's mutating tools) is wired.
 */
class AiToolRegistryBindingTest extends TestCase
{
    protected const READ_ONLY_TOOLS = [
        OrderLookupTool::class,
        ProductLookupTool::class,
        CustomerLookupTool::class,
        SalesReportTool::class,
    ];

    protected const MUTATING_TOOLS = [
        CreateOrderTool::class,
        CreateProductTool::class,
        CourierActionTool::class,
        UpdateOrderStatusTool::class,
    ];

    public function test_the_bound_registry_contains_every_tool(): void
    {
        $registry = app(AiToolRegistry::class);

        foreach ([...self::READ_ONLY_TOOLS, ...self::MUTATING_TOOLS] as $class) {
            $this->assertTrue($registry->has((new $class)->name()), "$class must be registered");
        }

        $this->assertCount(8, $registry->all());
    }

    public function test_the_bound_registry_is_a_singleton(): void
    {
        $this->assertSame(app(AiToolRegistry::class), app(AiToolRegistry::class));
    }

    public function test_every_phase_3_lookup_tool_is_read_only(): void
    {
        $registry = app(AiToolRegistry::class);

        foreach (self::READ_ONLY_TOOLS as $class) {
            $tool = $registry->get((new $class)->name());
            $this->assertFalse($tool->isMutating(), "$class must be read-only");
        }
    }

    public function test_every_phase_5_tool_is_mutating_and_implements_ai_mutating_tool(): void
    {
        $registry = app(AiToolRegistry::class);

        foreach (self::MUTATING_TOOLS as $class) {
            $tool = $registry->get((new $class)->name());
            $this->assertTrue($tool->isMutating(), "$class must be mutating");
            $this->assertInstanceOf(AiMutatingTool::class, $tool, "$class must implement AiMutatingTool so AiToolRegistry::propose() can route to it");
        }
    }
}
