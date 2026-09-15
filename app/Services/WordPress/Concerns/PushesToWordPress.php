<?php

namespace App\Services\WordPress\Concerns;

use App\Models\WordPressConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Shared by every service that pushes data FROM MetaSoftSAS TO a connected
 * WordPress site (WordPressProductSyncService since Phase 4,
 * WordPressOrderSyncService since Phase 5) — the connection lookup,
 * bearer-secret auth, timeout, and 401/403 -> needs_reconnect handling are
 * identical regardless of what's being pushed, so this is the one place
 * that logic lives rather than drifting across two copies.
 */
trait PushesToWordPress
{
    /**
     * Null when the tenant has no live, connected WordPress site to push
     * to — see WordPressProductSyncService::connectionFor()'s original
     * docblock (unchanged behavior, only moved) for the full reasoning,
     * including the fail-closed plan-feature check.
     */
    public function connectionFor(int $tenantId): ?WordPressConnection
    {
        $connection = WordPressConnection::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('status', 'connected')
            ->first();

        if (! $connection || ! $connection->wp_rest_url) {
            return null;
        }

        if (! $connection->tenant?->plan?->hasFeature('wordpress_connect')) {
            return null;
        }

        return $connection;
    }

    /**
     * Every outbound call funnels through here so the bearer-secret auth,
     * timeout, and failure handling (log + flip needs_reconnect on an auth
     * rejection) live in exactly one place.
     */
    protected function send(WordPressConnection $connection, string $method, string $path, array $payload = []): ?Response
    {
        $url = rtrim($connection->wp_rest_url, '/').'/metasoft/v1/'.$path;

        try {
            /** @var PendingRequest $request */
            $request = Http::timeout(15)->withToken($connection->outbound_secret);
            $response = $payload ? $request->{$method}($url, $payload) : $request->{$method}($url);
        } catch (ConnectionException $e) {
            Log::warning('WordPress push: connection failure.', [
                'tenant_id' => $connection->tenant_id,
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if ($response->unauthorized() || $response->forbidden()) {
            // Same posture as FacebookOAuthService::handleGraphFailure()'s
            // isInvalidToken() branch — the outbound_secret the plugin
            // holds no longer matches (site reinstalled, credentials
            // cleared locally, etc.), so mark the connection for a
            // required reconnect rather than silently retrying forever.
            $connection->update(['status' => 'needs_reconnect']);

            Log::warning('WordPress push: outbound secret rejected by plugin — marked needs_reconnect.', [
                'tenant_id' => $connection->tenant_id,
                'path' => $path,
            ]);

            return $response;
        }

        if ($response->failed()) {
            Log::warning('WordPress push: request failed.', [
                'tenant_id' => $connection->tenant_id,
                'path' => $path,
                'status' => $response->status(),
            ]);
        }

        return $response;
    }
}
