<?php

namespace Tests\Feature\AiAgent;

use App\Services\AI\Providers\OpenAiProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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

    public function test_the_request_sends_max_completion_tokens_never_the_legacy_max_tokens_key(): void
    {
        // Regression test for the production 400 unsupported_parameter
        // incident: newer models (gpt-5-mini among them) reject the
        // legacy 'max_tokens' key outright. OpenAI accepts
        // 'max_completion_tokens' across the whole current model lineup,
        // so this must always be the key sent, never 'max_tokens'.
        config(['ai.openai_api_key' => 'test-key', 'ai.max_tokens' => 500]);
        Http::fake(['*/chat/completions' => Http::response(['choices' => [['message' => ['content' => 'ok']]]])]);

        (new OpenAiProvider)->chat([['role' => 'user', 'content' => 'হাই']]);

        Http::assertSent(function ($request) {
            $data = $request->data();

            return ($data['max_completion_tokens'] ?? null) === 500
                && ! array_key_exists('max_tokens', $data);
        });
    }

    public function test_reasoning_effort_is_sent_for_a_reasoning_family_model(): void
    {
        config(['ai.openai_api_key' => 'test-key', 'ai.openai_model' => 'gpt-5-mini', 'ai.reasoning_effort' => 'low']);
        Http::fake(['*/chat/completions' => Http::response(['choices' => [['message' => ['content' => 'ok']]]])]);

        (new OpenAiProvider)->chat([['role' => 'user', 'content' => 'হাই']]);

        Http::assertSent(fn ($request) => ($request->data()['reasoning_effort'] ?? null) === 'low');
    }

    public function test_reasoning_effort_is_never_sent_for_a_non_reasoning_model(): void
    {
        // Sending 'reasoning_effort' to a model that doesn't support it is
        // itself an unsupported-parameter 400 -- the exact failure class
        // max_completion_tokens already fixed once for 'max_tokens'.
        config(['ai.openai_api_key' => 'test-key', 'ai.openai_model' => 'gpt-4o-mini', 'ai.reasoning_effort' => 'low']);
        Http::fake(['*/chat/completions' => Http::response(['choices' => [['message' => ['content' => 'ok']]]])]);

        (new OpenAiProvider)->chat([['role' => 'user', 'content' => 'হাই']]);

        Http::assertSent(fn ($request) => ! array_key_exists('reasoning_effort', $request->data()));
    }

    public function test_a_response_that_exhausted_its_token_budget_on_reasoning_is_logged_with_safe_diagnostic_fields(): void
    {
        // The exact real-world shape observed in production: HTTP 200,
        // finish_reason=length, no visible content, no tool calls -- the
        // model spent its whole budget on invisible reasoning.
        config(['ai.openai_api_key' => 'test-key']);
        Log::spy();

        Http::fake(['*/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => null], 'finish_reason' => 'length']],
            'usage' => ['completion_tokens' => 500, 'completion_tokens_details' => ['reasoning_tokens' => 500]],
        ])]);

        $response = (new OpenAiProvider)->chat([['role' => 'user', 'content' => 'হাই']]);

        $this->assertFalse($response->successful);
        Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context) {
            return $context['finish_reason'] === 'length'
                && $context['reasoning_tokens'] === 500
                && $context['completion_tokens'] === 500;
        })->once();
    }

    public function test_error_message_is_logged_for_an_ordinary_non_auth_failure(): void
    {
        // error.message is safe to log for an ordinary 400 (e.g. the
        // unsupported_parameter case that caused the production
        // incident this test guards against) — it's just a plain
        // description of what was wrong with the request, never a
        // credential.
        config(['ai.openai_api_key' => 'test-key']);
        Log::spy();

        Http::fake(['*/chat/completions' => Http::response([
            'error' => ['type' => 'invalid_request_error', 'code' => 'unsupported_parameter', 'param' => 'max_tokens', 'message' => 'Unsupported parameter: max_tokens'],
        ], 400)]);

        (new OpenAiProvider)->chat([['role' => 'user', 'content' => 'হাই']]);

        Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context) {
            return $context['error_code'] === 'unsupported_parameter'
                && $context['error_param'] === 'max_tokens'
                && ($context['error_message'] ?? null) === 'Unsupported parameter: max_tokens';
        })->once();
    }

    public function test_error_message_is_never_logged_for_a_401(): void
    {
        // OpenAI's invalid-API-key error text echoes back a partial key
        // ("Incorrect API key provided: sk-...xyz") — that specific field
        // must never be logged for a 401, even though the safe fields
        // (type/code) still are.
        config(['ai.openai_api_key' => 'test-key']);
        Log::spy();

        Http::fake(['*/chat/completions' => Http::response([
            'error' => ['type' => 'invalid_request_error', 'code' => 'invalid_api_key', 'message' => 'Incorrect API key provided: sk-...xyz'],
        ], 401)]);

        (new OpenAiProvider)->chat([['role' => 'user', 'content' => 'হাই']]);

        Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context) {
            return $context['error_code'] === 'invalid_api_key'
                && ! array_key_exists('error_message', $context);
        })->once();
    }
}
