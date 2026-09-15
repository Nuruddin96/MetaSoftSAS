<?php

namespace Tests\Feature\Facebook;

use App\Exceptions\FacebookGraphException;
use App\Models\FacebookOauthState;
use App\Services\Facebook\FacebookOAuthService;
use Illuminate\Support\Facades\Http;

class FacebookOAuthServiceTest extends FacebookFeatureTestCase
{
    /**
     * Root cause of "the previous tenant's Page keeps coming back": once a
     * Facebook user has granted this app pages_* access for one tenant,
     * Meta's /dialog/oauth silently auto-approves every later visit for
     * that same user+app+scopes (Page picker included) and just replays
     * the originally-granted Page set — with no way to choose a different
     * Page for a second tenant — unless auth_type=rerequest is present to
     * force the full consent dialog to render again every time.
     */
    public function test_authorization_url_forces_rerequest_so_the_page_picker_always_reappears(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $state = FacebookOauthState::create([
            'state' => str_repeat('a', 64),
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'purpose' => 'messenger',
            'expires_at' => now()->addMinutes(10),
        ]);

        $url = (new FacebookOAuthService)->authorizationUrl($state);

        parse_str((string) parse_url($url, PHP_URL_QUERY), $params);
        $this->assertSame('rerequest', $params['auth_type'] ?? null);
    }

    public function test_unsubscribe_sends_access_token_as_a_query_parameter_not_a_json_body(): void
    {
        Http::fake(['*/subscribed_apps*' => Http::response(['success' => true])]);

        (new FacebookOAuthService)->unsubscribePageFromWebhook('page-123', 'secret-page-token');

        Http::assertSent(function ($request) {
            $this->assertSame('DELETE', $request->method());
            $this->assertStringContainsString('access_token=secret-page-token', (string) $request->url());
            // The whole point of the fix: it must NOT be in a JSON body.
            $this->assertSame('', (string) $request->body(), 'access_token must not be sent as a DELETE JSON body');

            return true;
        });
    }

    public function test_unsubscribe_returns_true_only_on_a_genuine_meta_success_response(): void
    {
        Http::fake(['*/subscribed_apps*' => Http::response(['success' => true])]);

        $this->assertTrue((new FacebookOAuthService)->unsubscribePageFromWebhook('page-123', 'tok'));
    }

    public function test_unsubscribe_returns_false_on_failure_without_throwing(): void
    {
        Http::fake(['*/subscribed_apps*' => Http::response(['error' => ['message' => 'nope', 'code' => 1]], 400)]);

        $this->assertFalse((new FacebookOAuthService)->unsubscribePageFromWebhook('page-123', 'tok'));
    }

    public function test_throwiffailed_detects_non_2xx_http_status(): void
    {
        Http::fake(['*/me*' => Http::response(['error' => ['message' => 'bad token', 'code' => 190]], 400)]);

        $this->expectException(FacebookGraphException::class);
        (new FacebookOAuthService)->getProfile('some-token');
    }

    public function test_throwiffailed_detects_an_embedded_error_inside_a_200_response(): void
    {
        // Simulates the documented Meta failure mode: HTTP 200 with an
        // `error` object embedded in the body instead of a non-2xx status.
        Http::fake(['*/me*' => Http::response(['error' => ['message' => 'embedded failure', 'code' => 100]], 200)]);

        try {
            (new FacebookOAuthService)->getProfile('some-token');
            $this->fail('Expected a FacebookGraphException for an embedded 200-status Meta error.');
        } catch (FacebookGraphException $e) {
            $this->assertSame('embedded failure', $e->errorPayload()['message']);
        }
    }

    public function test_getmanagedpages_follows_pagination_cursor(): void
    {
        Http::fake([
            '*/me/accounts*' => Http::sequence()
                ->push([
                    'data' => [['id' => 'p1', 'name' => 'Page One', 'access_token' => 't1']],
                    'paging' => ['next' => 'https://graph.facebook.com/v19.0/me/accounts?after=CURSOR1'],
                ])
                ->push([
                    'data' => [['id' => 'p2', 'name' => 'Page Two', 'access_token' => 't2']],
                    // no paging.next — this is the last page
                ]),
        ]);

        $pages = (new FacebookOAuthService)->getManagedPages('user-token');

        $this->assertCount(2, $pages);
        $this->assertSame('p1', $pages[0]['id']);
        $this->assertSame('p2', $pages[1]['id']);
        Http::assertSentCount(2);
    }

    public function test_getmanagedpages_stops_at_the_hard_cap_on_a_looping_cursor(): void
    {
        // A pathological/looping paging.next that always points back to
        // itself — must never turn into an unbounded request loop.
        Http::fake([
            '*/me/accounts*' => Http::response([
                'data' => [['id' => 'loop-page', 'name' => 'Loop', 'access_token' => 't']],
                'paging' => ['next' => 'https://graph.facebook.com/v19.0/me/accounts?after=SAME_CURSOR_FOREVER'],
            ]),
        ]);

        $pages = (new FacebookOAuthService)->getManagedPages('user-token');

        Http::assertSentCount(10); // MAX_PAGE_LISTING_PAGES
        $this->assertCount(10, $pages);
    }
}
