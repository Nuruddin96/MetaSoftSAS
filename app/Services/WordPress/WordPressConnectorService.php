<?php

namespace App\Services\WordPress;

use App\Exceptions\WordPressConnectException;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WordPressConnection;
use App\Models\WordPressConnectionToken;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * All "Connect WordPress" logic: Connection Key issuance/consumption,
 * completing the plugin handshake, and live-verifying a connection. A
 * WordPress site is arbitrary and self-hosted — there is no OAuth app
 * registry to redirect a browser to the way FacebookOAuthService/
 * WhatsAppOAuthService have — so the trust handshake instead runs in the
 * opposite direction: the tenant pastes a short-lived Connection Key
 * (this service mints it) into the MetaSoft Connector plugin's own admin
 * screen, and the plugin calls back to a central API route with it.
 */
class WordPressConnectorService
{
    /** Minutes a Connection Key stays valid before the tenant must generate a new one. */
    protected const TOKEN_TTL_MINUTES = 30;

    /** Single-use, expiring, tenant+user-bound Connection Key for the plugin handshake. */
    public function createConnectionToken(Tenant $tenant, User $user): WordPressConnectionToken
    {
        return WordPressConnectionToken::create([
            'token' => bin2hex(random_bytes(32)), // 64 hex chars, cryptographically random
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'expires_at' => now()->addMinutes(self::TOKEN_TTL_MINUTES),
            'created_at' => now(),
        ]);
    }

    /**
     * Same reason-coded-exception + atomic single-UPDATE-wins consume shape
     * as FacebookOAuthService::createState()'s sibling in WhatsAppOAuthService
     * (validateAndConsumeState()) — closes the same replay race.
     */
    public function validateAndConsumeToken(?string $token): WordPressConnectionToken
    {
        if (! $token) {
            throw new WordPressConnectException('missing_token', 'No connection token supplied.');
        }

        $state = WordPressConnectionToken::where('token', $token)->first();

        if (! $state) {
            throw new WordPressConnectException('unknown_token', 'Connection token not recognized.');
        }

        if ($state->used_at) {
            throw new WordPressConnectException('already_used', 'Connection token already used (replay).');
        }

        if ($state->expires_at->isPast()) {
            throw new WordPressConnectException('expired_token', 'Connection token expired.');
        }

        $consumed = DB::table('wordpress_connection_tokens')
            ->where('id', $state->id)
            ->whereNull('used_at')
            ->update(['used_at' => now()]);

        if ($consumed === 0) {
            throw new WordPressConnectException('already_used', 'Connection token already used (race).');
        }

        return $state;
    }

    /**
     * Called once the plugin's handshake request has a validated token.
     * Upserts the tenant's single wordpress_connections row, mints a fresh
     * Sanctum personal access token for the plugin (revoking any previous
     * one — a new handshake supersedes an old connection rather than
     * accumulating live tokens), and generates a fresh outbound secret for
     * MetaSoftSAS's own future calls into the plugin.
     *
     * Returns [WordPressConnection, plaintext plugin API token, plaintext
     * outbound secret] — both plaintext values exist only for this one
     * response; neither is ever retrievable again afterward (Sanctum hashes
     * its token at rest, and outbound_secret is stored via an `encrypted`
     * cast — recoverable by MetaSoftSAS itself, but never re-shown to the
     * plugin after this call).
     */
    public function completeHandshake(WordPressConnectionToken $state, array $siteData): array
    {
        /** @var WordPressConnection $connection */
        $connection = WordPressConnection::withoutGlobalScopes()->updateOrCreate(
            ['tenant_id' => $state->tenant_id],
            [
                'connected_by_user_id' => $state->user_id,
                'site_url' => $siteData['site_url'],
                'site_name' => $siteData['site_name'] ?? null,
                'wp_rest_url' => $siteData['wp_rest_url'] ?? null,
                'plugin_version' => $siteData['plugin_version'] ?? null,
                'wp_version' => $siteData['wp_version'] ?? null,
                'woocommerce_active' => $siteData['woocommerce_active'] ?? false,
                'woocommerce_version' => $siteData['woocommerce_version'] ?? null,
                'status' => 'connected',
                'connected_at' => now(),
                'last_verified_at' => now(),
                'disconnected_at' => null,
            ]
        );

        // A reconnect must invalidate whatever token the previous
        // handshake issued — otherwise a lost/compromised old token would
        // stay live forever alongside the new one.
        $connection->tokens()->delete();

        $outboundSecret = Str::random(48);
        $connection->update(['outbound_secret' => $outboundSecret]);

        $apiToken = $connection->createToken('metasoft-connector-plugin')->plainTextToken;

        return [$connection, $apiToken, $outboundSecret];
    }

    /**
     * Live-verify a connection by pinging the plugin's public health route.
     * Deliberately does not require authentication on the plugin side for
     * this specific check — it only proves the plugin is installed,
     * active, and reachable at the URL on file, not that credentials still
     * match (a lost Sanctum token would still show "reachable" here, but
     * the very next authenticated push call would fail and flip status to
     * needs_reconnect — same posture as Facebook's isInvalidToken() path).
     */
    public function verify(WordPressConnection $connection): bool
    {
        if (! $connection->wp_rest_url) {
            return false;
        }

        try {
            $response = Http::timeout(10)->get(rtrim($connection->wp_rest_url, '/').'/metasoft/v1/health');

            $ok = $response->successful() && ($response->json('connected') === true);
        } catch (ConnectionException) {
            $ok = false;
        }

        $connection->update([
            'last_verified_at' => now(),
            'status' => $ok ? 'connected' : 'needs_reconnect',
        ]);

        return $ok;
    }

    /**
     * Disconnect locally and best-effort notify the plugin so it forgets
     * its stored credentials too — same "unsubscribe then disconnect
     * locally anyway" posture as FacebookConnectController::disconnect().
     */
    public function disconnect(WordPressConnection $connection): void
    {
        if ($connection->wp_rest_url) {
            try {
                Http::timeout(10)->withToken($connection->outbound_secret)
                    ->post(rtrim($connection->wp_rest_url, '/').'/metasoft/v1/disconnect');
            } catch (ConnectionException) {
                // Disconnecting locally must proceed regardless — the
                // plugin will also self-detect via its next failed/rejected
                // authenticated call if this notification never arrives.
            }
        }

        $connection->tokens()->delete();

        $connection->update([
            'status' => 'disconnected',
            'outbound_secret' => null,
            'disconnected_at' => now(),
        ]);
    }
}
