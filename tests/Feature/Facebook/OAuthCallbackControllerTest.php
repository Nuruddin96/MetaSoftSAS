<?php

namespace Tests\Feature\Facebook;

use App\Models\FacebookConnection;
use App\Services\Facebook\FacebookOAuthService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * HTTP-level tests for FacebookOAuthCallbackController::handle() itself —
 * the production-readiness review found only its FacebookOAuthService
 * dependency was covered before, not the controller's own wiring
 * (logging, redirect targets, tenant lookup, connection upsert).
 */
class OAuthCallbackControllerTest extends FacebookFeatureTestCase
{
    public function test_user_denied_consent_redirects_back_without_consuming_state(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $state = (new FacebookOAuthService)->createState($tenant, $user);

        $response = $this->actingAs($user, 'tenant')
            ->get('/panel/facebook/callback?error=access_denied&error_reason=user_denied&state='.$state->state);

        $response->assertRedirect($tenant->url().'/panel/facebook/pages');
        $response->assertSessionHas('error');
        $this->assertNull($state->fresh()->used_at, 'a declined consent must not burn the state token');
    }

    public function test_missing_code_redirects_with_error(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $state = (new FacebookOAuthService)->createState($tenant, $user);

        $response = $this->actingAs($user, 'tenant')
            ->get('/panel/facebook/callback?state='.$state->state);

        $response->assertRedirect($tenant->url().'/panel/facebook/pages');
        $response->assertSessionHas('error');
        $this->assertNotNull($state->fresh()->used_at);
        $this->assertSame(0, FacebookConnection::withoutGlobalScopes()->count());
    }

    public function test_invalid_state_is_rejected_with_403(): void
    {
        $response = $this->get('/panel/facebook/callback?state=not-a-real-state-token&code=abc');

        $response->assertStatus(403);
    }

    public function test_expired_state_is_rejected_with_403(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $state = (new FacebookOAuthService)->createState($tenant, $user);
        $state->forceFill(['expires_at' => now()->subMinute()])->save();

        $response = $this->actingAs($user, 'tenant')
            ->get('/panel/facebook/callback?state='.$state->state.'&code=abc');

        $response->assertStatus(403);
    }

    public function test_a_benign_duplicate_callback_after_a_successful_connection_redirects_instead_of_403(): void
    {
        // Mobile WebView/app flows can deliver the exact same callback
        // twice. The first delivery consumes the state and completes the
        // connection; the state's single-use protection still rejects the
        // second delivery's own validateAndConsumeState() call — but
        // because independent evidence (a FacebookConnection row updated
        // at/after the state's used_at) proves the original request
        // already succeeded, the controller now redirects to the Page
        // picker instead of a hard 403 that would look like a failure.
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $state = (new FacebookOAuthService)->createState($tenant, $user);

        Http::fake([
            '*/oauth/access_token*' => Http::response(['access_token' => 'short-lived', 'expires_in' => 3600]),
            '*/me?*' => Http::response(['id' => 'fb-user-1', 'name' => 'Test']),
        ]);

        $this->actingAs($user, 'tenant')
            ->get('/panel/facebook/callback?state='.$state->state.'&code=real-code')
            ->assertRedirect($tenant->url().'/panel/facebook/pages');

        $sentAfterFirstCall = count(Http::recorded());

        $second = $this->actingAs($user, 'tenant')
            ->get('/panel/facebook/callback?state='.$state->state.'&code=real-code');

        $second->assertRedirect($tenant->url().'/panel/facebook/pages');
        $second->assertSessionHas('success');

        // Single-use is still enforced underneath — the second request
        // never re-runs the token exchange, and only one connection row
        // exists.
        $this->assertCount($sentAfterFirstCall, Http::recorded(), 'the duplicate callback must never repeat the token exchange');
        $this->assertSame(1, FacebookConnection::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count());
    }

    public function test_a_replayed_state_without_a_completed_connection_is_still_rejected_with_403(): void
    {
        // The state was consumed (used_at set) but no FacebookConnection
        // was ever created for it — e.g. the first callback hit the
        // "missing code" branch and returned before reaching the token
        // exchange. A replay of that same state must NOT be treated as a
        // benign duplicate — there is no completed connection to redirect
        // to, so this must remain a hard 403.
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $state = (new FacebookOAuthService)->createState($tenant, $user);

        $this->actingAs($user, 'tenant')
            ->get('/panel/facebook/callback?state='.$state->state)
            ->assertRedirect($tenant->url().'/panel/facebook/pages'); // consumes the state, no code -> no connection

        $this->assertSame(0, FacebookConnection::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count());

        $second = $this->actingAs($user, 'tenant')
            ->get('/panel/facebook/callback?state='.$state->state.'&code=real-code');

        $second->assertStatus(403);
    }

    public function test_meta_error_during_token_exchange_redirects_with_friendly_error(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $state = (new FacebookOAuthService)->createState($tenant, $user);

        Http::fake([
            '*/oauth/access_token*' => Http::response(['error' => ['message' => 'invalid code', 'code' => 100]], 400),
        ]);

        $response = $this->actingAs($user, 'tenant')
            ->get('/panel/facebook/callback?state='.$state->state.'&code=bad-code');

        $response->assertRedirect($tenant->url().'/panel/facebook/pages');
        $response->assertSessionHas('error');
        $this->assertSame(0, FacebookConnection::withoutGlobalScopes()->count());
    }

    public function test_connection_failure_during_token_exchange_fails_gracefully(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $state = (new FacebookOAuthService)->createState($tenant, $user);

        Http::fake(function () {
            throw new ConnectionException('Could not resolve host: graph.facebook.com');
        });

        $response = $this->actingAs($user, 'tenant')
            ->get('/panel/facebook/callback?state='.$state->state.'&code=some-code');

        // Must degrade to a friendly redirect, never an uncaught 500.
        $response->assertRedirect($tenant->url().'/panel/facebook/pages');
        $response->assertSessionHas('error');
        $this->assertSame(0, FacebookConnection::withoutGlobalScopes()->count());
    }

    public function test_successful_callback_creates_connection_and_redirects_to_page_picker(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $state = (new FacebookOAuthService)->createState($tenant, $user);

        Http::fake([
            '*/oauth/access_token*' => Http::response(['access_token' => 'long-lived-token', 'expires_in' => 5184000]),
            '*/me?*' => Http::response(['id' => 'fb-user-42', 'name' => 'Real User']),
        ]);

        $response = $this->actingAs($user, 'tenant')
            ->get('/panel/facebook/callback?state='.$state->state.'&code=good-code');

        $response->assertRedirect($tenant->url().'/panel/facebook/pages');
        $response->assertSessionHas('success');

        $connection = FacebookConnection::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();
        $this->assertNotNull($connection);
        $this->assertSame('fb-user-42', $connection->facebook_user_id);
        $this->assertSame('long-lived-token', $connection->user_access_token);
    }
}
