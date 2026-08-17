<?php

namespace Tests\Feature\Facebook;

use App\Services\Messenger\MessengerApi;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * MessengerApi's Graph API base URL now comes from config('facebook.graph_version')
 * instead of a hard-coded v19.0 — see the v19.0 -> v26.0 migration. Endpoint
 * shape (recipient/message/messaging_type, /me/messages, GET /{psid} profile
 * lookup) is unchanged and confirmed compatible against current official
 * Meta documentation; these tests exercise that behavior, not new logic.
 */
class MessengerApiTest extends TestCase
{
    public function test_send_message_uses_the_configured_graph_api_version(): void
    {
        config(['facebook.graph_version' => 'v26.0']);
        Http::fake(['*/me/messages*' => Http::response(['message_id' => 'mid-123'])]);

        (new MessengerApi)->sendMessage('psid-1', 'hello', 'page-token-abc');

        Http::assertSent(function ($request) {
            $url = (string) $request->url();

            return str_starts_with($url, 'https://graph.facebook.com/v26.0/me/messages')
                && str_contains($url, 'access_token=page-token-abc');
        });
    }

    public function test_send_message_posts_the_correct_recipient_and_payload_shape(): void
    {
        Http::fake(['*/me/messages*' => Http::response(['message_id' => 'mid-123'])]);

        (new MessengerApi)->sendMessage('psid-42', 'hi there', 'tok');

        Http::assertSent(function ($request) {
            $body = json_decode((string) $request->body(), true);

            return $request->method() === 'POST'
                && $body['recipient']['id'] === 'psid-42'
                && $body['message']['text'] === 'hi there'
                && $body['messaging_type'] === 'RESPONSE';
        });
    }

    public function test_send_message_returns_the_meta_response_on_success(): void
    {
        Http::fake(['*/me/messages*' => Http::response(['message_id' => 'mid-999', 'recipient_id' => 'psid-1'])]);

        $result = (new MessengerApi)->sendMessage('psid-1', 'hi', 'tok');

        $this->assertSame('mid-999', $result['message_id']);
    }

    public function test_send_message_on_graph_api_error_returns_the_error_body_without_throwing(): void
    {
        Http::fake(['*/me/messages*' => Http::response([
            'error' => ['message' => 'invalid recipient', 'type' => 'OAuthException', 'code' => 100],
        ], 400)]);

        $result = (new MessengerApi)->sendMessage('bad-psid', 'hi', 'tok');

        $this->assertArrayHasKey('error', $result);
        $this->assertSame('invalid recipient', $result['error']['message']);
    }

    public function test_send_typing_on_posts_the_correct_sender_action_shape(): void
    {
        Http::fake(['*/me/messages*' => Http::response([])]);

        (new MessengerApi)->sendTypingOn('psid-42', 'tok');

        Http::assertSent(function ($request) {
            $body = json_decode((string) $request->body(), true);

            return $request->method() === 'POST'
                && $body['recipient']['id'] === 'psid-42'
                && $body['sender_action'] === 'typing_on'
                && ! isset($body['message']);
        });
    }

    public function test_get_profile_uses_the_configured_graph_api_version_and_token(): void
    {
        config(['facebook.graph_version' => 'v26.0']);
        Http::fake(['*/psid-42*' => Http::response(['first_name' => 'Karim', 'last_name' => 'Ahmed'])]);

        $profile = (new MessengerApi)->getProfile('psid-42', 'page-token-xyz');

        $this->assertSame('Karim', $profile['first_name']);
        Http::assertSent(function ($request) {
            $url = (string) $request->url();

            return str_starts_with($url, 'https://graph.facebook.com/v26.0/psid-42')
                && str_contains($url, 'access_token=page-token-xyz')
                && str_contains($url, 'fields=first_name%2Clast_name');
        });
    }

    /**
     * getProfile() now returns the raw Response (not null) on a Graph API
     * error — FacebookMessengerCustomerService needs the actual status/
     * error body to log it and to decide whether to persist a minimal
     * identity row despite the failure (see its class docblock). Callers
     * decide success/failure via $response->successful(), not getProfile()
     * itself collapsing that decision into null.
     */
    public function test_get_profile_returns_the_response_with_error_details_on_graph_api_error(): void
    {
        Http::fake(['*/psid-99*' => Http::response(['error' => ['message' => 'permission denied', 'type' => 'OAuthException', 'code' => 10, 'error_subcode' => 2018001]], 403)]);

        $response = (new MessengerApi)->getProfile('psid-99', 'tok');

        $this->assertFalse($response->successful());
        $this->assertSame(403, $response->status());
        $this->assertSame('permission denied', $response->json('error.message'));
        $this->assertSame('OAuthException', $response->json('error.type'));
        $this->assertSame(10, $response->json('error.code'));
        $this->assertSame(2018001, $response->json('error.error_subcode'));
    }
}
