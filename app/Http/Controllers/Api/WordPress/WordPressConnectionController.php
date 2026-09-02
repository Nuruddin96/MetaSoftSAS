<?php

namespace App\Http\Controllers\Api\WordPress;

use App\Exceptions\WordPressConnectException;
use App\Http\Controllers\Controller;
use App\Models\WordPressConnection;
use App\Services\WordPress\WordPressConnectorService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Central, non-tenant-prefixed API surface the MetaSoft Connector WordPress
 * plugin talks to. Mirrors FacebookOAuthCallbackController's role (a fixed
 * callback URL Meta redirects browsers to) except here the caller is the
 * plugin itself making a server-to-server request, not a browser redirect —
 * so this is a plain JSON API controller, not one that returns redirects.
 */
class WordPressConnectionController extends Controller
{
    /**
     * The plugin calls this once the tenant has pasted the Connection Key
     * (minted by Tenant\WordPressConnectController::store()) into its
     * settings screen and clicked "Connect to MetaSoftSAS". No auth guard
     * in front of this route — the connection_token itself, single-use and
     * short-lived, IS the authentication, same role facebook_oauth_states'
     * `state` plays for FacebookOAuthCallbackController.
     */
    public function handshake(Request $request, WordPressConnectorService $wp)
    {
        if (! WordPressConnection::tablesReady()) {
            return response()->json(['message' => 'WordPress ইন্টিগ্রেশন এখনো প্রস্তুত হয়নি।'], 503);
        }

        $data = $request->validate([
            'connection_token' => 'required|string',
            'site_url' => 'required|url|max:255',
            'site_name' => 'nullable|string|max:150',
            'wp_rest_url' => 'nullable|url|max:255',
            'plugin_version' => 'nullable|string|max:20',
            'wp_version' => 'nullable|string|max:20',
            'woocommerce_active' => 'nullable|boolean',
            'woocommerce_version' => 'nullable|string|max:20',
        ]);

        try {
            $state = $wp->validateAndConsumeToken($data['connection_token']);
        } catch (WordPressConnectException $e) {
            Log::warning('WordPress connect: handshake token validation failed.', [
                'reason' => $e->reason,
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'message' => 'এই কানেকশন কী (key) সঠিক নয় অথবা মেয়াদ শেষ হয়ে গেছে। প্যানেল থেকে নতুন কী নিন।',
                'reason' => $e->reason,
            ], 422);
        }

        [$connection, $apiToken, $outboundSecret] = $wp->completeHandshake($state, $data);

        return response()->json([
            'connected' => true,
            'tenant_name' => $connection->tenant->store_name,
            'api_token' => $apiToken,
            'outbound_secret' => $outboundSecret,
        ]);
    }

    /**
     * Authenticated (bind.tenant.wp) — a lightweight liveness/credential
     * check the plugin can call any time to confirm its stored token is
     * still valid, independent of WordPressConnectorService::verify()'s
     * MetaSoftSAS-initiated direction.
     */
    public function ping(Request $request)
    {
        $connection = $request->user();
        $connection->update(['last_verified_at' => now()]);

        return response()->json([
            'connected' => true,
            'tenant_name' => app('currentTenant')->store_name,
        ]);
    }
}
