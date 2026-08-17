<?php

namespace Tests\Feature\AiAgent\Tools;

use App\Services\AI\Tools\AiMutatingTool;
use App\Services\AI\Tools\AiTool;
use App\Services\AI\Tools\AiToolRegistry;
use Tests\TestCase;

/**
 * Covers App\Services\AI\Tools\AiToolRegistry's own contract, decoupled
 * from any real tool — the security-critical piece of Phase 3: proving a
 * tenant_id supplied inside $args (however it got there — a malformed
 * OpenAI function-call, an adversarial prompt injection attempt) is
 * always ignored, and the real tenant_id always comes from the trusted
 * caller parameter instead. tests/Feature/AiAgent/Tools/*LookupToolTest.php
 * cover the individual real tools' own query logic.
 */
class AiToolRegistryTest extends TestCase
{
    /** A fake tool that just echoes back whatever handle() actually received, so tests can inspect it. */
    protected function spyTool(string $name = 'spy_tool'): AiTool
    {
        return new class($name) implements AiTool
        {
            public array $received = [];

            public function __construct(private string $toolName) {}

            public function name(): string
            {
                return $this->toolName;
            }

            public function description(): string
            {
                return 'A spy tool for tests.';
            }

            public function parametersSchema(): array
            {
                return ['type' => 'object', 'properties' => ['q' => ['type' => 'string']]];
            }

            public function isMutating(): bool
            {
                return false;
            }

            public function handle(int $tenantId, array $args): array
            {
                $this->received = ['tenant_id' => $tenantId, 'args' => $args];

                return ['echo' => $args, 'received_tenant_id' => $tenantId];
            }
        };
    }

    public function test_calling_an_unknown_tool_returns_failure_without_throwing(): void
    {
        $registry = new AiToolRegistry([]);

        $result = $registry->call('does_not_exist', 1, []);

        $this->assertFalse($result->successful);
        $this->assertNotNull($result->error);
    }

    public function test_a_tenant_id_key_inside_args_is_stripped_and_never_reaches_the_tool(): void
    {
        // The central security requirement: even if a tool argument set
        // somehow contains a tenant_id-shaped key (an adversarial
        // function-call argument, a confused prompt), it must never reach
        // the tool implementation — only the trusted $tenantId parameter
        // AiToolRegistry::call() itself was given is ever used.
        $tool = $this->spyTool();
        $registry = new AiToolRegistry([$tool]);

        $registry->call('spy_tool', 42, [
            'q' => 'hello',
            'tenant_id' => 999,
            'tenant_slug' => 'attacker-shop',
            'currentTenant' => 999,
        ]);

        $this->assertSame(42, $tool->received['tenant_id'], 'the trusted caller-supplied tenant_id must be the one actually used');
        $this->assertArrayNotHasKey('tenant_id', $tool->received['args']);
        $this->assertArrayNotHasKey('tenant_slug', $tool->received['args']);
        $this->assertArrayNotHasKey('currentTenant', $tool->received['args']);
        $this->assertSame(['q' => 'hello'], $tool->received['args'], 'legitimate arguments must pass through unchanged');
    }

    public function test_different_tenant_ids_never_cross_contaminate_across_calls(): void
    {
        $tool = $this->spyTool();
        $registry = new AiToolRegistry([$tool]);

        $registry->call('spy_tool', 1, ['q' => 'a']);
        $this->assertSame(1, $tool->received['tenant_id']);

        $registry->call('spy_tool', 2, ['q' => 'b']);
        $this->assertSame(2, $tool->received['tenant_id']);
    }

    public function test_a_tool_that_throws_returns_failure_instead_of_crashing(): void
    {
        $throwing = new class implements AiTool
        {
            public function name(): string
            {
                return 'throwing_tool';
            }

            public function description(): string
            {
                return 'Always throws.';
            }

            public function parametersSchema(): array
            {
                return ['type' => 'object', 'properties' => []];
            }

            public function isMutating(): bool
            {
                return false;
            }

            public function handle(int $tenantId, array $args): array
            {
                throw new \RuntimeException('boom — must never leak into the result');
            }
        };

        $registry = new AiToolRegistry([$throwing]);

        $result = $registry->call('throwing_tool', 1, []);

        $this->assertFalse($result->successful);
        $this->assertStringNotContainsString('boom', (string) $result->error, 'the raw exception message must never be exposed');
    }

    public function test_to_openai_schema_reflects_every_registered_tool(): void
    {
        $registry = new AiToolRegistry([$this->spyTool('tool_a'), $this->spyTool('tool_b')]);

        $schema = $registry->toOpenAiSchema();

        $this->assertCount(2, $schema);
        $this->assertSame('function', $schema[0]['type']);
        $this->assertSame('tool_a', $schema[0]['function']['name']);
        $this->assertSame('tool_b', $schema[1]['function']['name']);
        $this->assertArrayHasKey('parameters', $schema[0]['function']);
    }

    public function test_has_and_all_reflect_registered_tools(): void
    {
        $registry = new AiToolRegistry([$this->spyTool('tool_a')]);

        $this->assertTrue($registry->has('tool_a'));
        $this->assertFalse($registry->has('tool_z'));
        $this->assertCount(1, $registry->all());
    }

    /** A fake mutating tool that echoes what preview()/handle() actually received. */
    protected function spyMutatingTool(string $name = 'spy_mutating_tool'): AiMutatingTool
    {
        return new class($name) implements AiMutatingTool
        {
            public array $previewReceived = [];

            public array $handleReceived = [];

            public function __construct(private string $toolName) {}

            public function name(): string
            {
                return $this->toolName;
            }

            public function description(): string
            {
                return 'A spy mutating tool for tests.';
            }

            public function parametersSchema(): array
            {
                return ['type' => 'object', 'properties' => []];
            }

            public function isMutating(): bool
            {
                return true;
            }

            public function preview(int $tenantId, array $args): array
            {
                $this->previewReceived = ['tenant_id' => $tenantId, 'args' => $args];

                if (($args['q'] ?? null) === 'invalid') {
                    return ['error' => 'invalid input'];
                }

                return ['summary' => 'Do the thing?', 'resolved_args' => ['q' => $args['q'] ?? null]];
            }

            public function handle(int $tenantId, array $args): array
            {
                $this->handleReceived = ['tenant_id' => $tenantId, 'args' => $args];

                return ['success' => true];
            }
        };
    }

    public function test_propose_returns_the_previews_summary_and_resolved_args(): void
    {
        $tool = $this->spyMutatingTool();
        $registry = new AiToolRegistry([$tool]);

        $result = $registry->propose('spy_mutating_tool', 7, ['q' => 'hello']);

        $this->assertTrue($result->successful);
        $this->assertSame('Do the thing?', $result->data['summary']);
        $this->assertSame(['q' => 'hello'], $result->data['resolved_args']);
        $this->assertSame(7, $tool->previewReceived['tenant_id']);
    }

    public function test_propose_never_calls_handle(): void
    {
        // The entire point of the confirmation system: proposing a
        // mutation must never perform it.
        $tool = $this->spyMutatingTool();
        $registry = new AiToolRegistry([$tool]);

        $registry->propose('spy_mutating_tool', 1, ['q' => 'hello']);

        $this->assertSame([], $tool->handleReceived, 'propose() must never reach handle()');
    }

    public function test_propose_strips_a_tenant_id_key_from_args_same_as_call(): void
    {
        $tool = $this->spyMutatingTool();
        $registry = new AiToolRegistry([$tool]);

        $registry->propose('spy_mutating_tool', 7, ['q' => 'hello', 'tenant_id' => 999]);

        $this->assertArrayNotHasKey('tenant_id', $tool->previewReceived['args']);
    }

    public function test_propose_returns_failure_when_preview_reports_an_error(): void
    {
        $tool = $this->spyMutatingTool();
        $registry = new AiToolRegistry([$tool]);

        $result = $registry->propose('spy_mutating_tool', 1, ['q' => 'invalid']);

        $this->assertFalse($result->successful);
        $this->assertSame('invalid input', $result->error);
    }

    public function test_propose_refuses_a_plain_non_mutating_tool(): void
    {
        // propose() must never become a second way to invoke a read-only
        // tool — that stays call()'s job exclusively.
        $tool = $this->spyTool();
        $registry = new AiToolRegistry([$tool]);

        $result = $registry->propose('spy_tool', 1, []);

        $this->assertFalse($result->successful);
    }

    public function test_propose_returns_failure_for_an_unknown_tool(): void
    {
        $registry = new AiToolRegistry([]);

        $result = $registry->propose('does_not_exist', 1, []);

        $this->assertFalse($result->successful);
    }

    public function test_propose_catches_a_preview_exception_without_leaking_its_message(): void
    {
        $throwing = new class implements AiMutatingTool
        {
            public function name(): string
            {
                return 'throwing_mutating_tool';
            }

            public function description(): string
            {
                return '...';
            }

            public function parametersSchema(): array
            {
                return ['type' => 'object', 'properties' => []];
            }

            public function isMutating(): bool
            {
                return true;
            }

            public function preview(int $tenantId, array $args): array
            {
                throw new \RuntimeException('boom — must never leak into the result');
            }

            public function handle(int $tenantId, array $args): array
            {
                return ['success' => true];
            }
        };

        $result = (new AiToolRegistry([$throwing]))->propose('throwing_mutating_tool', 1, []);

        $this->assertFalse($result->successful);
        $this->assertStringNotContainsString('boom', (string) $result->error);
    }

    public function test_get_returns_the_registered_tool_or_null(): void
    {
        $tool = $this->spyTool('tool_a');
        $registry = new AiToolRegistry([$tool]);

        $this->assertSame($tool, $registry->get('tool_a'));
        $this->assertNull($registry->get('tool_z'));
    }
}
