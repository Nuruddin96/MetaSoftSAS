<?php

namespace Tests\Feature\Inbox;

use App\Models\MessengerMessage;
use App\Models\Tenant;
use App\Models\WhatsAppMessage;
use App\Services\Inbox\UnifiedInboxService;
use Illuminate\Support\Facades\URL;

class UnifiedInboxServiceTest extends InboxFeatureTestCase
{
    /**
     * UnifiedInboxService::fetchMessengerCandidates()/fetchWhatsAppCandidates()
     * call route('tenant.messenger.show', ...)/route('tenant.whatsapp.show', ...)
     * to build each conversation's showUrl — in a real request this works
     * because ResolveTenant middleware has already pushed tenant_slug into
     * URL::defaults() before this service ever runs (see bootstrap/app.php's
     * own comment on that mechanism). These tests call the service directly,
     * bypassing that middleware entirely, so the same default must be primed
     * by hand — this is a test-harness gap, not a service bug.
     */
    protected function bindTenant(Tenant $tenant): void
    {
        app()->instance('currentTenant', $tenant);
        URL::defaults(['tenant_slug' => $tenant->subdomain]);
    }

    protected function msg(int $tenantId, string $psid, array $attrs = []): MessengerMessage
    {
        return MessengerMessage::withoutGlobalScopes()->create(array_merge([
            'tenant_id' => $tenantId, 'sender_psid' => $psid, 'direction' => 'in', 'status' => 'new',
        ], $attrs));
    }

    protected function wa(int $tenantId, string $waId, array $attrs = []): WhatsAppMessage
    {
        return WhatsAppMessage::withoutGlobalScopes()->create(array_merge([
            'tenant_id' => $tenantId, 'wa_id' => $waId, 'direction' => 'in', 'status' => 'new',
        ], $attrs));
    }

    // --- conversation merge -----------------------------------------------------------------------

    public function test_messenger_only_tenant_shows_only_messenger_conversations(): void
    {
        $tenant = $this->makeTenant();
        $this->bindTenant($tenant);

        $this->msg($tenant->id, 'psid-1', ['message_text' => 'hi', 'created_at' => now()]);
        $this->msg($tenant->id, 'psid-2', ['message_text' => 'hello', 'created_at' => now()->subMinute()]);

        $result = (new UnifiedInboxService)->paginate(null, 20);

        $this->assertCount(2, $result['conversations']);
        $this->assertTrue($result['conversations']->every(fn ($c) => $c->channel === 'messenger'));
    }

    public function test_whatsapp_only_tenant_shows_only_whatsapp_conversations(): void
    {
        $tenant = $this->makeTenant();
        $this->bindTenant($tenant);

        $this->wa($tenant->id, '8801700000001', ['message_text' => 'hi', 'created_at' => now()]);
        $this->wa($tenant->id, '8801700000002', ['message_text' => 'hello', 'created_at' => now()->subMinute()]);

        $result = (new UnifiedInboxService)->paginate(null, 20);

        $this->assertCount(2, $result['conversations']);
        $this->assertTrue($result['conversations']->every(fn ($c) => $c->channel === 'whatsapp'));
    }

    public function test_both_channels_connected_merge_and_sort_by_latest_message_desc(): void
    {
        $tenant = $this->makeTenant();
        $this->bindTenant($tenant);

        // 10:05 WhatsApp A, 10:02 Messenger B, 09:58 WhatsApp C, 09:51 Messenger D — same shape as the spec example.
        $this->wa($tenant->id, 'wa-A', ['customer_name' => 'Customer A', 'created_at' => '2026-01-01 10:05:00']);
        $this->msg($tenant->id, 'psid-B', ['customer_name' => 'Customer B', 'created_at' => '2026-01-01 10:02:00']);
        $this->wa($tenant->id, 'wa-C', ['customer_name' => 'Customer C', 'created_at' => '2026-01-01 09:58:00']);
        $this->msg($tenant->id, 'psid-D', ['customer_name' => 'Customer D', 'created_at' => '2026-01-01 09:51:00']);

        $result = (new UnifiedInboxService)->paginate(null, 20);
        $names = $result['conversations']->map(fn ($c) => $c->customerName)->values()->all();

        $this->assertSame(['Customer A', 'Customer B', 'Customer C', 'Customer D'], $names);
        $this->assertSame(['whatsapp', 'messenger', 'whatsapp', 'messenger'], $result['conversations']->map->channel->values()->all());
    }

    // --- conversation identity -----------------------------------------------------------------------

    public function test_same_customer_name_on_both_channels_remains_two_conversations(): void
    {
        $tenant = $this->makeTenant();
        $this->bindTenant($tenant);

        $this->msg($tenant->id, 'psid-shared-name', ['customer_name' => 'Karim Uddin', 'created_at' => now()]);
        $this->wa($tenant->id, '8801700000099', ['customer_name' => 'Karim Uddin', 'created_at' => now()]);

        $result = (new UnifiedInboxService)->paginate(null, 20);

        $this->assertCount(2, $result['conversations'], 'identical customer_name on two channels must not be merged into one conversation');
        $this->assertNotSame(
            $result['conversations'][0]->conversationKey(),
            $result['conversations'][1]->conversationKey()
        );
    }

    public function test_messenger_psid_is_not_treated_as_whatsapp_identity_even_with_the_same_string_value(): void
    {
        $tenant = $this->makeTenant();
        $this->bindTenant($tenant);

        // Same literal string used as a Messenger PSID and a WhatsApp wa_id
        // — must still resolve to two distinct conversations, keyed by channel.
        $this->msg($tenant->id, '123456789', ['created_at' => now()]);
        $this->wa($tenant->id, '123456789', ['created_at' => now()]);

        $result = (new UnifiedInboxService)->paginate(null, 20);

        $keys = $result['conversations']->map->conversationKey()->values()->all();
        $this->assertCount(2, $result['conversations']);
        $this->assertContains('messenger:123456789', $keys);
        $this->assertContains('whatsapp:123456789', $keys);
    }

    // --- channel filter --------------------------------------------------------------------------------

    public function test_channel_filter_messenger_excludes_whatsapp(): void
    {
        $tenant = $this->makeTenant();
        $this->bindTenant($tenant);

        $this->msg($tenant->id, 'psid-1', ['created_at' => now()]);
        $this->wa($tenant->id, 'wa-1', ['created_at' => now()]);

        $result = (new UnifiedInboxService)->paginate(null, 20, 'messenger');

        $this->assertCount(1, $result['conversations']);
        $this->assertSame('messenger', $result['conversations']->first()->channel);
    }

    public function test_channel_filter_whatsapp_excludes_messenger(): void
    {
        $tenant = $this->makeTenant();
        $this->bindTenant($tenant);

        $this->msg($tenant->id, 'psid-1', ['created_at' => now()]);
        $this->wa($tenant->id, 'wa-1', ['created_at' => now()]);

        $result = (new UnifiedInboxService)->paginate(null, 20, 'whatsapp');

        $this->assertCount(1, $result['conversations']);
        $this->assertSame('whatsapp', $result['conversations']->first()->channel);
    }

    // --- unread count ------------------------------------------------------------------------------

    public function test_unread_count_sums_both_channels(): void
    {
        $tenant = $this->makeTenant();
        $this->bindTenant($tenant);

        $this->msg($tenant->id, 'psid-1', ['status' => 'new', 'direction' => 'in']);
        $this->msg($tenant->id, 'psid-2', ['status' => 'contacted', 'direction' => 'in']); // not unread
        $this->wa($tenant->id, 'wa-1', ['status' => 'new', 'direction' => 'in']);
        $this->wa($tenant->id, 'wa-2', ['status' => 'new', 'direction' => 'out']); // outbound, not unread

        $this->assertSame(2, (new UnifiedInboxService)->unreadCount());
    }

    // --- pagination ------------------------------------------------------------------------------------

    public function test_cursor_pagination_returns_every_conversation_exactly_once_across_pages(): void
    {
        $tenant = $this->makeTenant();
        $this->bindTenant($tenant);

        // 5 Messenger + 5 WhatsApp conversations, staggered timestamps, well above a small page size.
        for ($i = 0; $i < 5; $i++) {
            $this->msg($tenant->id, "psid-$i", ['created_at' => now()->subMinutes($i * 2)]);
            $this->wa($tenant->id, "wa-$i", ['created_at' => now()->subMinutes($i * 2 + 1)]);
        }

        $service = new UnifiedInboxService;
        $seen = collect();
        $cursor = null;

        do {
            $result = $service->paginate($cursor, 3);
            $seen = $seen->concat($result['conversations']->map->conversationKey());
            $cursor = $result['nextCursor'];
        } while ($result['hasMore']);

        $this->assertCount(10, $seen);
        $this->assertCount(10, $seen->unique(), 'no conversation should be shown twice across pages');
    }

    public function test_pagination_is_bounded_never_loading_more_than_two_pages_worth_per_request(): void
    {
        $tenant = $this->makeTenant();
        $this->bindTenant($tenant);

        for ($i = 0; $i < 30; $i++) {
            $this->msg($tenant->id, "psid-$i", ['created_at' => now()->subMinutes($i)]);
        }

        $result = (new UnifiedInboxService)->paginate(null, 5);

        $this->assertCount(5, $result['conversations'], 'a bounded read model must only return one page worth of rows, not the whole table');
        $this->assertTrue($result['hasMore']);
    }

    // --- tenant isolation --------------------------------------------------------------------------

    public function test_unified_list_never_leaks_another_tenants_messenger_or_whatsapp_conversations(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();

        $this->msg($tenantA->id, 'psid-a', ['created_at' => now()]);
        $this->wa($tenantA->id, 'wa-a', ['created_at' => now()]);
        $this->msg($tenantB->id, 'psid-b', ['created_at' => now()]);
        $this->wa($tenantB->id, 'wa-b', ['created_at' => now()]);

        $this->bindTenant($tenantA);
        $resultA = (new UnifiedInboxService)->paginate(null, 20);
        $this->assertCount(2, $resultA['conversations']);
        $this->assertTrue($resultA['conversations']->every(fn ($c) => ! str_contains($c->externalCustomerId, '-b')));

        $this->bindTenant($tenantB);
        $resultB = (new UnifiedInboxService)->paginate(null, 20);
        $this->assertCount(2, $resultB['conversations']);
        $this->assertTrue($resultB['conversations']->every(fn ($c) => ! str_contains($c->externalCustomerId, '-a')));
    }
}
