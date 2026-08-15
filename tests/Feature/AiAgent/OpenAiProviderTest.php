<?php

namespace Tests\Feature\AiAgent;

use App\Services\AI\Providers\OpenAiProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Covers App\Services\AI\Providers\OpenAiProvider directly — the concrete
 * implementation extracted out of AiAgentService for the Phase 2 provider
 * abstraction. AiAgentServiceTest covers the abstraction boundary itself
 * (a fake provider); ProcessAiAgentMessageJobTest covers this same class
 * again end-to-end through the real dispatch/job pipeline. This file is
 * the one place OpenAI-specific request/response shape handling is
 * unit-tested in isolation.
 */
class OpenAiProviderTest extends TestCase
{
    public function test_returns_failure_when_api_key_is_not_configured(): void
    {
        config(['ai.openai_api_key' => null]);
        Http::fake(); // any call at all here is a bug

        $response = (new OpenAiProvider)->chat([['role' => 'user', 'content' => 'হাই']]);

        $this->assertFalse($response->successful);
        Http::assertNothingSent();
    }

    public function test_returns_failure_on_transport_exception(): void
    {
        config(['ai.openai_api_key' => 'test-key']);
        Http::fake(fn () => throw new ConnectionException('boom'));

        $response = (new OpenAiProvider)->chat([['role' => 'user', 'content' => 'হাই']]);

        $this->assertFalse($response->successful);
    }

    public function test_returns_failure_on_openai_error_response(): void
    {
        config(['ai.openai_api_key' => 'test-key']);
        Http::fake(['*/chat/completions' => Http::response(['error' => ['type' => 'server_error']], 500)]);

        $response = (new OpenAiProvider)->chat([['role' => 'user', 'content' => 'হাই']]);

        $this->assertFalse($response->successful);
    }

    public function test_returns_failure_on_empty_reply_text(): void
    {
        config(['ai.openai_api_key' => 'test-key']);
        Http::fake(['*/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => '   ']]],
        ])]);

        $response = (new OpenAiProvider)->chat([['role' => 'user', 'content' => 'হাই']]);

        $this->assertFalse($response->successful);
    }

    public function test_returns_success_with_reply_and_token_usage(): void
    {
        config(['ai.openai_api_key' => 'test-key', 'ai.openai_model' => 'gpt-5-mini']);
        Http::fake(['*/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => '  দাম ৫০০ টাকা।  ']]],
            'usage' => ['prompt_tokens' => 40, 'completion_tokens' => 10],
        ])]);

        $response = (new OpenAiProvider)->chat([['role' => 'user', 'content' => 'দাম কত?']]);

        $this->assertTrue($response->successful);
        $this->assertSame('দাম ৫০০ টাকা।', $response->reply, 'reply text must be trimmed');
        $this->assertSame(40, $response->inputTokens);
        $this->assertSame(10, $response->outputTokens);
        $this->assertSame('gpt-5-mini', $response->model);
    }

    public function test_defaults_token_usage_to_zero_when_usage_is_missing_from_the_response(): void
    {
        // Never crashes on a response shape that omits 'usage' entirely.
        config(['ai.openai_api_key' => 'test-key']);
        Http::fake(['*/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => 'ok']]],
        ])]);

        $response = (new OpenAiProvider)->chat([['role' => 'user', 'content' => 'হাই']]);

        $this->assertTrue($response->successful);
        $this->assertSame(0, $response->inputTokens);
        $this->assertSame(0, $response->outputTokens);
    }

    public function test_a_tools_schema_is_included_in_the_request_when_provided(): void
    {
        config(['ai.openai_api_key' => 'test-key']);
        Http::fake(['*/chat/completions' => Http::response(['choices' => [['message' => ['content' => 'ok']]]])]);

        $schema = [['type' => 'function', 'function' => ['name' => 'lookup_orders', 'description' => '...', 'parameters' => []]]];
        (new OpenAiProvider)->chat([['role' => 'user', 'content' => 'হাই']], $schema);

        Http::assertSent(fn ($request) => ($request['tools'] ?? null) === $schema);
    }

    public function test_no_tools_key_is_sent_when_the_tools_schema_is_empty(): void
    {
        // Preserves the exact pre-Phase-4 request shape for the Messenger
        // flow (AiAgentService), which never passes a schema.
        config(['ai.openai_api_key' => 'test-key']);
        Http::fake(['*/chat/completions' => Http::response(['choices' => [['message' => ['content' => 'ok']]]])]);

        (new OpenAiProvider)->chat([['role' => 'user', 'content' => 'হাই']]);

        Http::assertSent(fn ($request) => ! array_key_exists('tools', $request->data()));
    }

    public function test_a_pure_tool_call_response_with_no_text_content_is_a_success(): void
    {
        config(['ai.openai_api_key' => 'test-key']);
        Http::fake(['*/chat/completions' => Http::response([
            'choices' => [['message' => [
                'content' => null,
                'tool_calls' => [[
                    'id' => 'call_1',
                    'type' => 'function',
                    'function' => ['name' => 'lookup_orders', 'arguments' => '{"order_number":"ORD-000123"}'],
                ]],
            ]]],
            'usage' => ['prompt_tokens' => 20, 'completion_tokens' => 5],
        ])]);

        $response = (new OpenAiProvider)->chat([['role' => 'user', 'content' => 'ORD-000123 এর স্ট্যাটাস কী?']], [['type' => 'function', 'function' => []]]);

        $this->assertTrue($response->successful, 'a tool-call-only response (no text) must still be a success');
        $this->assertNull($response->reply);
        $this->assertCount(1, $response->toolCalls);
        $this->assertSame('call_1', $response->toolCalls[0]['id']);
        $this->assertSame('lookup_orders', $response->toolCalls[0]['name']);
        $this->assertSame(['order_number' => 'ORD-000123'], $response->toolCalls[0]['arguments']);
    }

    public function test_malformed_tool_call_arguments_json_degrades_to_an_empty_array_not_a_crash(): void
    {
        config(['ai.openai_api_key' => 'test-key']);
        Http::fake(['*/chat/completions' => Http::response([
            'choices' => [['message' => [
                'content' => null,
                'tool_calls' => [[
                    'id' => 'call_1',
                    'type' => 'function',
                    'function' => ['name' => 'lookup_orders', 'arguments' => 'not valid json{{{'],
                ]],
            ]]],
        ])]);

        $response = (new OpenAiProvider)->chat([['role' => 'user', 'content' => 'হাই']], [['type' => 'function', 'function' => []]]);

        $this->assertTrue($response->successful);
        $this->assertSame([], $response->toolCalls[0]['arguments']);
    }

    public function test_no_reply_and_no_tool_calls_is_still_a_failure(): void
    {
        config(['ai.openai_api_key' => 'test-key']);
        Http::fake(['*/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => null]]],
        ])]);

        $response = (new OpenAiProvider)->chat([['role' => 'user', 'content' => 'হাই']]);

        $this->assertFalse($response->successful);
    }
}
