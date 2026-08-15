<?php

namespace App\Http\Controllers;

use App\Exceptions\FacebookGraphException;
use App\Exceptions\FacebookOAuthException;
use App\Models\FacebookConnection;
use App\Models\FacebookOauthState;
use App\Models\Tenant;
use App\Services\Facebook\FacebookOAuthService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Single, fixed callback URL registered in the Meta App dashboard — it
 * cannot be tenant-prefixed (Meta does not support templated/wildcard
 * redirect URIs), so this deliberately lives outside resolve.tenant and
 * $tenantRoutes. Tenant identity comes only from the verified
 * facebook_oauth_states row (see FacebookOAuthService::
 * validateAndConsumeState) — never from the URL, the session, or any
 * request input. A plain GET route: Laravel's CSRF middleware only guards
 * POST/PUT/PATCH/DELETE, so this intentionally carries no CSRF exemption.
 */
class FacebookOAuthCallbackController extends Controller
{
    public function handle(Request $request, FacebookOAuthService $fb)
    {
        if ($request->filled('error')) {
            return $this->bounceOnUserDenied($request);
        }

        try {
            $state = $fb->validateAndConsumeState(
                $request->query('state'),
                null
            );
        } catch (FacebookOAuthException $e) {
            Log::warning('Facebook OAuth: state validation failed.', [
                'reason' => $e->reason,
                'ip' => $request->ip(),
            ]);

            /*
             * Mobile WebView/app flows can deliver the same OAuth callback
             * more than once. The first callback atomically consumes the
             * state and completes the Facebook connection; a duplicate
             * callback then correctly reports "already_used".
             *
             * Do NOT disable single-use state protection. Instead, when the
             * state was already consumed and the corresponding connection
             * was successfully updated after that consumption, treat the
             * callback as a harmless duplicate and continue to Page picker.
             */
            if ($e->reason === 'already_used') {
                $stateRow = FacebookOauthState::where(
                    'state',
                    $request->query('state')
                )->first();

                if ($stateRow && $stateRow->used_at) {
                    $connectionCompleted = FacebookConnection::withoutGlobalScopes()
                        ->where('tenant_id', $stateRow->tenant_id)
                        ->where('connected_by_user_id', $stateRow->user_id)
                        ->where('updated_at', '>=', $stateRow->used_at)
                        ->exists();

                    if ($connectionCompleted) {
                        $tenant = Tenant::find($stateRow->tenant_id);

                        if ($tenant) {
                            return $this->redirectToTenant(
                                $tenant,
                                'success',
                                'Facebook কানেকশন সফল হয়েছে — এখন একটি Page বাছাই করুন।'
                            );
                        }
                    }
                }
            }

            abort(403, 'This Facebook connection request could not be verified. Please start again from your panel.');
        }

        $tenant = Tenant::find($state->tenant_id);

        if (! $tenant) {
            Log::error('Facebook OAuth: state referenced a tenant that no longer exists.', ['tenant_id' => $state->tenant_id]);
            abort(404);
        }

        $code = $request->query('code');

        if (! $code) {
            return $this->redirectToTenant($tenant, 'error', 'Facebook থেকে অনুমতি পাওয়া যায়নি, আবার চেষ্টা করুন।');
        }

        try {
            $shortLived = $fb->exchangeCodeForShortLivedToken($code);
            $longLived = $fb->exchangeForLongLivedToken($shortLived); // short-lived token is discarded here, never stored
            $profile = $fb->getProfile($longLived['access_token']);
        } catch (FacebookGraphException $e) {
            Log::error('Facebook OAuth: token exchange failed.', [
                'tenant_id' => $tenant->id,
                'error' => $e->errorPayload(),
            ]);

            return $this->redirectToTenant($tenant, 'error', 'Facebook টোকেন যাচাই ব্যর্থ হয়েছে, আবার চেষ্টা করুন।');
        } catch (ConnectionException $e) {
            // Deliberately not logging $e->getMessage() — Guzzle connection
            // exception messages can embed the full failed request URI,
            // which for these calls includes client_secret/access_token as
            // query parameters.
            Log::error('Facebook OAuth: connection failure during token exchange.', [
                'tenant_id' => $tenant->id,
            ]);

            return $this->redirectToTenant($tenant, 'error', 'Facebook-এর সাথে সংযোগ করা যায়নি, একটু পর আবার চেষ্টা করুন।');
        }

        FacebookConnection::withoutGlobalScopes()->updateOrCreate(
            ['tenant_id' => $tenant->id],
            [
                'connected_by_user_id' => $state->user_id,
                'facebook_user_id' => $profile['id'] ?? '',
                'user_access_token' => $longLived['access_token'],
                'token_expires_at' => now()->addSeconds($longLived['expires_in']),
                'granted_scopes' => implode(',', config('facebook.scopes')),
            ]
        );

        return $this->redirectToTenant($tenant, 'success', 'Facebook কানেক্ট হয়েছে — এখন একটি Page বাছাই করুন।');
    }

    protected function bounceOnUserDenied(Request $request)
    {
        Log::info('Facebook OAuth: user declined or Facebook-side error on callback.', [
            'error' => $request->get('error'),
            'error_reason' => $request->get('error_reason'),
        ]);

        // Read-only lookup purely to pick a nicer redirect target — does NOT
        // consume the state, since no OAuth action actually completed.
        $tenant = null;
        if ($stateToken = $request->query('state')) {
            $stateRow = FacebookOauthState::where('state', $stateToken)->first();
            $tenant = $stateRow ? Tenant::find($stateRow->tenant_id) : null;
        }

        return $tenant
            ? $this->redirectToTenant($tenant, 'error', 'Facebook সংযোগ বাতিল করা হয়েছে।')
            : redirect()->route('landing')->with('error', 'Facebook সংযোগ বাতিল করা হয়েছে।');
    }

    protected function redirectToTenant(Tenant $tenant, string $flashKey, string $message)
    {
        return redirect()->to($tenant->url().'/panel/facebook/pages')->with($flashKey, $message);
    }
}
