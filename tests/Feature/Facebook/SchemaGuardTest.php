<?php

namespace Tests\Feature\Facebook;

use App\Http\Controllers\MessengerWebhookController;
use App\Http\Controllers\Tenant\MessengerInboxController;
use App\Http\Controllers\Tenant\SettingController;
use App\Models\FacebookPage;
use App\Models\MessengerMessage;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * Simulates database/sql/chunk23.sql not having been imported yet — the
 * three Facebook OAuth tables are absent, but tenants/users/
 * messenger_settings/messenger_messages exist, exactly like a production
 * environment where this code was deployed before the schema migration.
 * Nothing here should throw a SQL error, and the legacy messenger_settings
 * flow must keep working unaffected.
 */
class SchemaGuardTest extends FacebookFeatureTestCase
{
    protected bool $includeFacebookOauthTables = false;

    public function test_facebook_tables_ready_reports_false_when_tables_are_missing(): void
    {
        $this->assertFalse(FacebookPage::tablesReady());
    }

    public function test_settings_index_does_not_error_and_returns_empty_facebook_data(): void
    {
        $tenant = $this->makeTenant();
        app()->instance('currentTenant', $tenant);

        $view = (new SettingController)->index();
        $data = $view->getData();

        $this->assertNull($data['facebookConnection']);
        $this->assertCount(0, $data['facebookPages']);
        // Unrelated settings data must still be gathered normally.
        $this->assertArrayHasKey('couriers', $data);
        $this->assertArrayHasKey('marketing', $data);
    }

    public function test_webhook_still_processes_legacy_messenger_settings_pages(): void
    {
        config(['messenger.app_secret' => 'test-secret']);

        $tenant = $this->makeTenant();
        DB::table('messenger_settings')->insert([
            'tenant_id' => $tenant->id,
            'page_id' => 'legacy-page-no-fb-tables',
            'page_access_token' => encrypt('legacy-token'),
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $payload = [
            'object' => 'page',
            'entry' => [[
                'id' => 'legacy-page-no-fb-tables',
                'messaging' => [[
                    'sender' => ['id' => 'psid-1'],
                    'message' => ['mid' => 'mid-legacy-1', 'text' => 'hi'],
                ]],
            ]],
        ];
        $body = json_encode($payload);
        $signature = 'sha256='.hash_hmac('sha256', $body, 'test-secret');

        $response = $this->call('POST', '/webhook/messenger', [], [], [], $this->transformHeadersToServerVars([
            'X-Hub-Signature-256' => $signature,
            'CONTENT_TYPE' => 'application/json',
        ]), $body);

        $response->assertOk();

        $message = MessengerMessage::withoutGlobalScopes()->where('mid', 'mid-legacy-1')->first();
        $this->assertNotNull($message, 'legacy messenger_settings flow must keep working with no Facebook OAuth tables present');
        $this->assertSame($tenant->id, $message->tenant_id);
    }

    public function test_inbox_index_connected_flag_still_reflects_legacy_connection(): void
    {
        $tenant = $this->makeTenant();
        app()->instance('currentTenant', $tenant);

        DB::table('messenger_settings')->insert([
            'tenant_id' => $tenant->id,
            'page_id' => 'legacy-page-2',
            'page_access_token' => encrypt('legacy-token'),
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $view = (new MessengerInboxController)->index();

        $this->assertTrue($view->getData()['connected']);
    }

    public function test_inbox_index_does_not_error_when_nothing_is_connected_at_all(): void
    {
        $tenant = $this->makeTenant();
        app()->instance('currentTenant', $tenant);

        $view = (new MessengerInboxController)->index();

        $this->assertFalse($view->getData()['connected']);
    }

    protected function panelUrl(Tenant $tenant, string $path): string
    {
        return '/shop/'.$tenant->subdomain.'/panel/'.$path;
    }

    /**
     * M1 (production-readiness audit): FacebookConnectController's own
     * routes previously had no tablesReady() guard at all — clicking
     * "Connect Facebook" before chunk23.sql was imported would 500 instead
     * of showing a friendly message. Doesn't touch the legacy flow (these
     * routes are unreachable by a tenant who never uses the new feature),
     * but must not produce a raw SQL error for tenants who do try it early.
     */
    public function test_connect_redirects_gracefully_when_facebook_tables_are_missing(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);

        $response = $this->actingAs($user, 'tenant')
            ->get($this->panelUrl($tenant, 'facebook/connect'));

        $response->assertRedirect(route('tenant.settings', ['tenant_slug' => $tenant->subdomain]));
        $response->assertSessionHas('error');
    }

    public function test_pages_picker_redirects_gracefully_when_facebook_tables_are_missing(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);

        $response = $this->actingAs($user, 'tenant')
            ->get($this->panelUrl($tenant, 'facebook/pages'));

        $response->assertRedirect(route('tenant.settings', ['tenant_slug' => $tenant->subdomain]));
        $response->assertSessionHas('error');
    }

    public function test_page_connect_action_redirects_gracefully_when_facebook_tables_are_missing(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);

        $response = $this->actingAs($user, 'tenant')
            ->post($this->panelUrl($tenant, 'facebook/pages/some-page-id/connect'));

        $response->assertRedirect(route('tenant.settings', ['tenant_slug' => $tenant->subdomain]));
        $response->assertSessionHas('error');
    }

    public function test_disconnect_action_redirects_gracefully_when_facebook_tables_are_missing(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);

        $response = $this->actingAs($user, 'tenant')
            ->post($this->panelUrl($tenant, 'facebook/pages/1/disconnect'));

        $response->assertRedirect(route('tenant.settings', ['tenant_slug' => $tenant->subdomain]));
        $response->assertSessionHas('error');
    }
}
