<?php

namespace Tests\Feature\Facebook;

use App\Models\FacebookConnection;
use App\Models\FacebookPage;
use Illuminate\Support\Facades\Http;

/**
 * Coverage for the facebook:check-page-identity diagnostic command — a
 * read-only tool for confirming a stored Page Access Token actually
 * belongs to the Page it's recorded against (the class most Messenger
 * delivery-failure reports trace back to). Never mutates any row.
 */
class CheckFacebookPageIdentityTest extends FacebookFeatureTestCase
{
    protected function makePage(array $pageAttrs = []): FacebookPage
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);

        $connection = FacebookConnection::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'connected_by_user_id' => $user->id,
            'facebook_user_id' => 'fbu-'.$tenant->id, 'user_access_token' => 'token',
            'token_expires_at' => now()->addDays(30),
        ]);

        return FacebookPage::withoutGlobalScopes()->create(array_merge([
            'tenant_id' => $tenant->id, 'facebook_connection_id' => $connection->id,
            'page_id' => 'page-'.$tenant->id, 'page_access_token' => 'page-token-'.$tenant->id,
            'status' => 'active', 'is_active' => 1,
        ], $pageAttrs));
    }

    public function test_reports_not_found_for_an_unknown_page_id(): void
    {
        $this->artisan('facebook:check-page-identity', ['page_id' => 'does-not-exist'])
            ->assertFailed();
    }

    public function test_ignores_an_inactive_page(): void
    {
        $page = $this->makePage(['is_active' => 0]);

        $this->artisan('facebook:check-page-identity', ['page_id' => $page->page_id])
            ->assertFailed();
    }

    public function test_reports_a_match_when_the_token_belongs_to_the_stored_page(): void
    {
        $page = $this->makePage();

        Http::fake([
            '*/me?*' => Http::response(['id' => $page->page_id, 'name' => 'Real Page']),
        ]);

        $this->artisan('facebook:check-page-identity', ['page_id' => $page->page_id])
            ->assertSuccessful();
    }

    public function test_reports_a_mismatch_when_the_token_belongs_to_a_different_page(): void
    {
        $page = $this->makePage();

        Http::fake([
            '*/me?*' => Http::response(['id' => 'some-other-page-id', 'name' => 'Wrong Page']),
        ]);

        $this->artisan('facebook:check-page-identity', ['page_id' => $page->page_id])
            ->assertFailed();
    }

    public function test_reports_failure_when_the_graph_call_itself_fails(): void
    {
        $page = $this->makePage();

        Http::fake([
            '*/me?*' => Http::response(['error' => ['message' => 'Invalid OAuth access token.']], 400),
        ]);

        $this->artisan('facebook:check-page-identity', ['page_id' => $page->page_id])
            ->assertFailed();
    }
}
