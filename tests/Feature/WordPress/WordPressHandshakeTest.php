<?php

namespace Tests\Feature\WordPress;

use App\Models\WordPressConnection;
use App\Services\WordPress\WordPressConnectorService;

class WordPressHandshakeTest extends WordPressFeatureTestCase
{
    protected function validPayload(string $token): array
    {
        return [
            'connection_token' => $token,
            'site_url' => 'https://example-shop.com',
            'site_name' => 'Example Shop',
            'wp_rest_url' => 'https://example-shop.com/wp-json',
            'plugin_version' => '0.1.0',
            'wp_version' => '6.5',
            'woocommerce_active' => true,
            'woocommerce_version' => '8.0',
        ];
    }

    public function test_handshake_with_a_valid_token_establishes_a_connection(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $state = (new WordPressConnectorService)->createConnectionToken($tenant, $user);

        $response = $this->postJson('/api/wordpress/v1/handshake', $this->validPayload($state->token));

        $response->assertOk();
        $response->assertJson(['connected' => true]);
        $response->assertJsonStructure(['api_token', 'outbound_secret', 'tenant_name']);

        $connection = WordPressConnection::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();
        $this->assertNotNull($connection);
        $this->assertSame('connected', $connection->status);
        $this->assertSame('https://example-shop.com', $connection->site_url);
        $this->assertTrue($connection->woocommerce_active);
        $this->assertNotNull($connection->connected_at);
    }

    public function test_handshake_rejects_an_unknown_token(): void
    {
        $response = $this->postJson('/api/wordpress/v1/handshake', $this->validPayload('not-a-real-token'));

        $response->assertStatus(422);
        $response->assertJson(['reason' => 'unknown_token']);
        $this->assertSame(0, WordPressConnection::withoutGlobalScopes()->count());
    }

    public function test_handshake_rejects_an_expired_token(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $state = (new WordPressConnectorService)->createConnectionToken($tenant, $user);
        $state->update(['expires_at' => now()->subMinute()]);

        $response = $this->postJson('/api/wordpress/v1/handshake', $this->validPayload($state->token));

        $response->assertStatus(422);
        $response->assertJson(['reason' => 'expired_token']);
    }

    public function test_handshake_rejects_a_replayed_token(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $state = (new WordPressConnectorService)->createConnectionToken($tenant, $user);

        $this->postJson('/api/wordpress/v1/handshake', $this->validPayload($state->token))->assertOk();

        $response = $this->postJson('/api/wordpress/v1/handshake', $this->validPayload($state->token));

        $response->assertStatus(422);
        $response->assertJson(['reason' => 'already_used']);

        // Replay must not have overwritten/duplicated the already-established connection.
        $this->assertSame(1, WordPressConnection::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count());
    }

    public function test_handshake_validates_required_fields(): void
    {
        $response = $this->postJson('/api/wordpress/v1/handshake', ['connection_token' => 'x']);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['site_url']);
    }

    public function test_reconnect_revokes_the_previous_api_token(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $service = new WordPressConnectorService;

        $firstState = $service->createConnectionToken($tenant, $user);
        $this->postJson('/api/wordpress/v1/handshake', $this->validPayload($firstState->token))->assertOk();

        $connection = WordPressConnection::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();
        $this->assertSame(1, $connection->tokens()->count());
        $firstTokenId = $connection->tokens()->first()->id;

        $secondState = $service->createConnectionToken($tenant, $user);
        $this->postJson('/api/wordpress/v1/handshake', $this->validPayload($secondState->token))->assertOk();

        $connection->refresh();
        $this->assertSame(1, $connection->tokens()->count());
        $this->assertNotSame($firstTokenId, $connection->tokens()->first()->id);
    }

    // --- ping (authenticated) -------------------------------------------------

    public function test_ping_authenticates_via_the_sanctum_token_from_handshake_and_binds_the_right_tenant(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $state = (new WordPressConnectorService)->createConnectionToken($tenant, $user);

        $handshake = $this->postJson('/api/wordpress/v1/handshake', $this->validPayload($state->token));
        $apiToken = $handshake->json('api_token');

        $response = $this->withHeader('Authorization', 'Bearer '.$apiToken)->getJson('/api/wordpress/v1/ping');

        $response->assertOk();
        $response->assertJson(['connected' => true, 'tenant_name' => $tenant->store_name]);
    }

    public function test_ping_rejects_a_disconnected_connections_revoked_token(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $service = new WordPressConnectorService;
        $state = $service->createConnectionToken($tenant, $user);

        $handshake = $this->postJson('/api/wordpress/v1/handshake', $this->validPayload($state->token));
        $apiToken = $handshake->json('api_token');

        $connection = WordPressConnection::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();
        $service->disconnect($connection);

        $response = $this->withHeader('Authorization', 'Bearer '.$apiToken)->getJson('/api/wordpress/v1/ping');

        $response->assertStatus(401);
    }

    public function test_ping_without_a_token_is_unauthorized(): void
    {
        $this->getJson('/api/wordpress/v1/ping')->assertStatus(401);
    }
}
