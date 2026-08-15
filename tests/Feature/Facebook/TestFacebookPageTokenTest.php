<?php

namespace Tests\Feature\Facebook;

use App\Models\FacebookConnection;
use App\Models\FacebookPage;
use Illuminate\Support\Facades\Http;

/**
 * Coverage for the facebook:test-psid diagnostic command — a read-only
 * tool for confirming a stored Page Access Token can actually resolve a
 * given Messenger PSID via the Graph API. Never mutates any row.
 */
class TestFacebookPageTokenTest extends FacebookFeatureTestCase
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

    public function test_reports_failure_for_an_unknown_page_id(): void
    {
        $this->artisan('facebook:test-psid', ['page_id' => 'does-not-exist', 'psid' => 'psid-1'])
            ->assertFailed();
    }

    public function test_reports_failure_for_an_inactive_page(): void
    {
        $page = $this->makePage(['is_active' => 0]);

        $this->artisan('facebook:test-psid', ['page_id' => $page->page_id, 'psid' => 'psid-1'])
            ->assertFailed();
    }

    public function test_reports_failure_when_the_page_has_no_stored_token(): void
    {
        $page = $this->makePage(['page_access_token' => null]);

        $this->artisan('facebook:test-psid', ['page_id' => $page->page_id, 'psid' => 'psid-1'])
            ->assertFailed();
    }

    public function test_reports_success_when_the_psid_lookup_succeeds(): void
    {
        $page = $this->makePage();

        Http::fake([
            'graph.facebook.com/*' => Http::response(['first_name' => 'Test', 'last_name' => 'User']),
        ]);

        $this->artisan('facebook:test-psid', ['page_id' => $page->page_id, 'psid' => 'psid-1'])
            ->assertSuccessful();
    }

    public function test_reports_failure_when_the_graph_call_itself_fails(): void
    {
        $page = $this->makePage();

        Http::fake([
            'graph.facebook.com/*' => Http::response(['error' => ['message' => 'Invalid OAuth access token.']], 400),
        ]);

        $this->artisan('facebook:test-psid', ['page_id' => $page->page_id, 'psid' => 'psid-1'])
            ->assertFailed();
    }
}
