<?php

namespace Tests\Feature\WhatsApp;

use App\Models\WhatsAppBusinessAccount;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppPhoneNumber;
use Illuminate\Database\QueryException;

class WhatsAppSchemaTest extends WhatsAppFeatureTestCase
{
    public function test_tables_ready_reports_true_when_chunk26_is_fully_imported(): void
    {
        $this->assertTrue(WhatsAppPhoneNumber::tablesReady());
    }

    public function test_business_account_is_tenant_scoped(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $userA = $this->makeUser($tenantA->id);

        WhatsAppBusinessAccount::withoutGlobalScopes()->create([
            'tenant_id' => $tenantA->id,
            'connected_by_user_id' => $userA->id,
            'waba_id' => 'waba-a',
            'user_access_token' => 'token-a',
        ]);

        app()->instance('currentTenant', $tenantA);
        $this->assertSame(1, WhatsAppBusinessAccount::count());

        app()->instance('currentTenant', $tenantB);
        $this->assertSame(0, WhatsAppBusinessAccount::count());
    }

    public function test_phone_number_is_tenant_scoped(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $userA = $this->makeUser($tenantA->id);

        $account = WhatsAppBusinessAccount::withoutGlobalScopes()->create([
            'tenant_id' => $tenantA->id,
            'connected_by_user_id' => $userA->id,
            'waba_id' => 'waba-a',
            'user_access_token' => 'token-a',
        ]);

        WhatsAppPhoneNumber::withoutGlobalScopes()->create([
            'tenant_id' => $tenantA->id,
            'whatsapp_business_account_id' => $account->id,
            'phone_number_id' => 'pnid-a',
            'display_phone_number' => '+8801000000000',
        ]);

        app()->instance('currentTenant', $tenantA);
        $this->assertSame(1, WhatsAppPhoneNumber::count());

        app()->instance('currentTenant', $tenantB);
        $this->assertSame(0, WhatsAppPhoneNumber::count());
    }

    public function test_message_is_tenant_scoped(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();

        WhatsAppMessage::withoutGlobalScopes()->create([
            'tenant_id' => $tenantA->id,
            'wa_id' => '8801700000000',
            'message_text' => 'hi',
        ]);

        app()->instance('currentTenant', $tenantA);
        $this->assertSame(1, WhatsAppMessage::count());

        app()->instance('currentTenant', $tenantB);
        $this->assertSame(0, WhatsAppMessage::count());
    }

    public function test_tenant_id_auto_fills_on_create_when_current_tenant_is_bound(): void
    {
        $tenant = $this->makeTenant();
        app()->instance('currentTenant', $tenant);

        $message = WhatsAppMessage::create([
            'wa_id' => '8801700000000',
            'message_text' => 'hello',
        ]);

        $this->assertSame($tenant->id, $message->tenant_id);
    }

    public function test_wamid_is_unique_for_webhook_deduplication(): void
    {
        $tenant = $this->makeTenant();

        WhatsAppMessage::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'wa_id' => '8801700000000',
            'wamid' => 'wamid.duplicate-test',
            'message_text' => 'first delivery',
        ]);

        $this->expectException(QueryException::class);

        WhatsAppMessage::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'wa_id' => '8801700000000',
            'wamid' => 'wamid.duplicate-test',
            'message_text' => 'retried delivery',
        ]);
    }

    public function test_multiple_null_wamid_rows_are_allowed(): void
    {
        // Outgoing/staff-composed rows may not have a wamid yet (assigned
        // only once Meta's Send API responds) — NULL must not collide with
        // NULL under the unique index, same guarantee messenger_messages.mid
        // already relies on.
        $tenant = $this->makeTenant();

        WhatsAppMessage::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'wa_id' => '8801700000000', 'wamid' => null,
        ]);

        WhatsAppMessage::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'wa_id' => '8801700000000', 'wamid' => null,
        ]);

        $this->assertSame(2, WhatsAppMessage::withoutGlobalScopes()->count());
    }

    public function test_waba_id_is_unique_across_tenants(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $userA = $this->makeUser($tenantA->id);
        $userB = $this->makeUser($tenantB->id);

        WhatsAppBusinessAccount::withoutGlobalScopes()->create([
            'tenant_id' => $tenantA->id,
            'connected_by_user_id' => $userA->id,
            'waba_id' => 'shared-waba',
            'user_access_token' => 'token-a',
        ]);

        $this->expectException(QueryException::class);

        WhatsAppBusinessAccount::withoutGlobalScopes()->create([
            'tenant_id' => $tenantB->id,
            'connected_by_user_id' => $userB->id,
            'waba_id' => 'shared-waba',
            'user_access_token' => 'token-b',
        ]);
    }

    public function test_phone_number_id_is_unique_across_tenants(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $userA = $this->makeUser($tenantA->id);
        $userB = $this->makeUser($tenantB->id);

        $accountA = WhatsAppBusinessAccount::withoutGlobalScopes()->create([
            'tenant_id' => $tenantA->id, 'connected_by_user_id' => $userA->id,
            'waba_id' => 'waba-a', 'user_access_token' => 'token-a',
        ]);
        $accountB = WhatsAppBusinessAccount::withoutGlobalScopes()->create([
            'tenant_id' => $tenantB->id, 'connected_by_user_id' => $userB->id,
            'waba_id' => 'waba-b', 'user_access_token' => 'token-b',
        ]);

        WhatsAppPhoneNumber::withoutGlobalScopes()->create([
            'tenant_id' => $tenantA->id,
            'whatsapp_business_account_id' => $accountA->id,
            'phone_number_id' => 'shared-phone-number-id',
        ]);

        $this->expectException(QueryException::class);

        WhatsAppPhoneNumber::withoutGlobalScopes()->create([
            'tenant_id' => $tenantB->id,
            'whatsapp_business_account_id' => $accountB->id,
            'phone_number_id' => 'shared-phone-number-id',
        ]);
    }

    public function test_user_access_token_is_encrypted_at_rest(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);

        $account = WhatsAppBusinessAccount::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'connected_by_user_id' => $user->id,
            'waba_id' => 'waba-encrypted-test',
            'user_access_token' => 'plaintext-secret-token',
        ]);

        $raw = \Illuminate\Support\Facades\DB::table('whatsapp_business_accounts')
            ->where('id', $account->id)->value('user_access_token');

        $this->assertNotSame('plaintext-secret-token', $raw);
        $this->assertSame('plaintext-secret-token', $account->fresh()->user_access_token);
    }

    public function test_resolved_name_for_finds_name_from_earlier_inbound_message_even_when_latest_is_outbound(): void
    {
        $tenant = $this->makeTenant();

        WhatsAppMessage::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'wa_id' => '8801700000000',
            'direction' => 'in',
            'customer_name' => 'Apo',
            'message_text' => 'হ্যালো',
        ]);

        // Latest row is outbound and never carries a customer_name — same
        // shape as MessengerMessage's equivalent scenario.
        WhatsAppMessage::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'wa_id' => '8801700000000',
            'direction' => 'out',
            'customer_name' => null,
            'message_text' => 'ধন্যবাদ',
        ]);

        $this->assertSame('Apo', WhatsAppMessage::resolvedNameFor($tenant->id, '8801700000000'));
    }

    public function test_phone_number_belongs_to_business_account(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);

        $account = WhatsAppBusinessAccount::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'connected_by_user_id' => $user->id,
            'waba_id' => 'waba-rel-test',
            'user_access_token' => 'token',
        ]);

        $phone = WhatsAppPhoneNumber::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'whatsapp_business_account_id' => $account->id,
            'phone_number_id' => 'pnid-rel-test',
        ]);

        $this->assertTrue($account->phoneNumbers->contains($phone));
        $this->assertSame($account->id, $phone->businessAccount->id);
    }

    public function test_raw_payload_round_trips_as_array(): void
    {
        $tenant = $this->makeTenant();

        $message = WhatsAppMessage::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'wa_id' => '8801700000000',
            'message_type' => 'location',
            'raw_payload' => ['latitude' => 23.7, 'longitude' => 90.4],
        ]);

        $this->assertSame(23.7, $message->fresh()->raw_payload['latitude']);
    }
}
