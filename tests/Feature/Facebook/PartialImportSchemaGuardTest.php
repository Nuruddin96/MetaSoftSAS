<?php

namespace Tests\Feature\Facebook;

use App\Http\Controllers\Tenant\MessengerInboxController;
use App\Models\FacebookPage;
use App\Models\MessengerMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Simulates a PARTIAL database/sql/chunk23.sql import: the three new
 * Facebook tables exist, but the trailing
 * `ALTER TABLE messenger_messages ADD COLUMN facebook_page_id ...`
 * statement in that same file did not run (interrupted import, manually
 * pasted in pieces, etc.). This is a narrower, previously-undetected variant
 * of the "chunk23.sql not imported" case — FacebookPage::tablesReady() used
 * to only check the three tables, not this column, so this exact state
 * would have slipped through and thrown "unknown column facebook_page_id"
 * on every incoming Messenger webhook event, including ones resolved via
 * the untouched legacy messenger_settings path.
 */
class PartialImportSchemaGuardTest extends FacebookFeatureTestCase
{
    protected bool $includeFacebookOauthTables = true;

    protected bool $includeFacebookPageIdColumn = false;

    public function test_the_three_facebook_tables_exist_but_the_column_does_not(): void
    {
        $this->assertTrue(Schema::hasTable('facebook_oauth_states'));
        $this->assertTrue(Schema::hasTable('facebook_connections'));
        $this->assertTrue(Schema::hasTable('facebook_pages'));
        $this->assertTrue(Schema::hasTable('messenger_messages'));
        $this->assertFalse(Schema::hasColumn('messenger_messages', 'facebook_page_id'));
    }

    public function test_tables_ready_reports_false_when_only_the_column_is_missing(): void
    {
        $this->assertFalse(
            FacebookPage::tablesReady(),
            'tablesReady() must treat a missing facebook_page_id column the same as a missing table'
        );
    }

    public function test_webhook_does_not_throw_unknown_column_and_legacy_flow_still_works(): void
    {
        config(['messenger.app_secret' => 'test-secret']);

        $tenant = $this->makeTenant();
        DB::table('messenger_settings')->insert([
            'tenant_id' => $tenant->id,
            'page_id' => 'legacy-page-partial-import',
            'page_access_token' => encrypt('legacy-token'),
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $payload = [
            'object' => 'page',
            'entry' => [[
                'id' => 'legacy-page-partial-import',
                'messaging' => [[
                    'sender' => ['id' => 'psid-1'],
                    'message' => ['mid' => 'mid-partial-1', 'text' => 'hello'],
                ]],
            ]],
        ];
        $body = json_encode($payload);
        $signature = 'sha256='.hash_hmac('sha256', $body, 'test-secret');

        // The critical assertion: this must not throw a QueryException for
        // "unknown column facebook_page_id" — it must complete cleanly.
        $response = $this->call('POST', '/webhook/messenger', [], [], [], $this->transformHeadersToServerVars([
            'X-Hub-Signature-256' => $signature,
            'CONTENT_TYPE' => 'application/json',
        ]), $body);

        $response->assertOk();

        $message = MessengerMessage::withoutGlobalScopes()->where('mid', 'mid-partial-1')->first();
        $this->assertNotNull($message, 'legacy messenger_settings flow must keep working during a partial Facebook schema import');
        $this->assertSame($tenant->id, $message->tenant_id);
    }

    public function test_inbox_connected_flag_does_not_throw_and_reflects_legacy_connection_only(): void
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
}
