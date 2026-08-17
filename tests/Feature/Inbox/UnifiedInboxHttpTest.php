<?php

namespace Tests\Feature\Inbox;

use App\Models\MessengerMessage;
use App\Models\WhatsAppMessage;

class UnifiedInboxHttpTest extends InboxFeatureTestCase
{
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

    public function test_index_renders_mixed_conversations_with_channel_badges(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $this->msg($tenant->id, 'psid-1', ['customer_name' => 'Messenger Kastomer', 'created_at' => now()]);
        $this->wa($tenant->id, 'wa-1', ['customer_name' => 'WhatsApp Kastomer', 'created_at' => now()->subMinute()]);

        $response = $this->actingAs($user, 'tenant')->get($this->panelUrl($tenant, 'inbox'));

        $response->assertOk();
        $response->assertSee('Messenger Kastomer');
        $response->assertSee('WhatsApp Kastomer');
        $response->assertSee('Messenger');
        $response->assertSee('WhatsApp');
    }

    public function test_channel_query_param_filters_the_rendered_list(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $this->msg($tenant->id, 'psid-1', ['customer_name' => 'Only Messenger Here', 'created_at' => now()]);
        $this->wa($tenant->id, 'wa-1', ['customer_name' => 'Only WhatsApp Here', 'created_at' => now()]);

        $response = $this->actingAs($user, 'tenant')->get($this->panelUrl($tenant, 'inbox?channel=messenger'));

        $response->assertOk();
        $response->assertSee('Only Messenger Here');
        $response->assertDontSee('Only WhatsApp Here');
    }

    public function test_load_more_endpoint_returns_json_with_a_valid_cursor(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        for ($i = 0; $i < 5; $i++) {
            $this->msg($tenant->id, "psid-$i", ['created_at' => now()->subMinutes($i)]);
        }

        $first = $this->actingAs($user, 'tenant')->get($this->panelUrl($tenant, 'inbox'));
        $first->assertOk();

        // Force a tiny page by requesting "more" with a cursor from the newest
        // item BY TIMESTAMP — not orderByDesc('id'), since the loop above
        // inserts the OLDEST created_at last (i=4), giving it the highest id.
        $newest = MessengerMessage::withoutGlobalScopes()->where('tenant_id', $tenant->id)->orderByDesc('created_at')->first();
        $cursor = $newest->created_at->timestamp.':'.$newest->id;

        $more = $this->actingAs($user, 'tenant')
            ->get($this->panelUrl($tenant, 'inbox/more?cursor='.$cursor));

        $more->assertOk();
        $more->assertJsonStructure(['conversations', 'next_cursor', 'has_more']);
        $this->assertCount(4, $more->json('conversations'), 'the cursor must exclude the already-shown newest item');
    }

    public function test_unified_inbox_never_leaks_another_tenants_conversations_over_http(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $userB = $this->makeUser($tenantB->id);

        $this->msg($tenantA->id, 'psid-secret-a', ['customer_name' => 'Secret Tenant A Customer', 'created_at' => now()]);
        $this->wa($tenantA->id, 'wa-secret-a', ['customer_name' => 'Secret Tenant A WA Customer', 'created_at' => now()]);

        $response = $this->actingAs($userB, 'tenant')->get($this->panelUrl($tenantB, 'inbox'));

        $response->assertOk();
        $response->assertDontSee('Secret Tenant A Customer');
        $response->assertDontSee('Secret Tenant A WA Customer');
    }

    public function test_empty_inbox_renders_without_error(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);

        $response = $this->actingAs($user, 'tenant')->get($this->panelUrl($tenant, 'inbox'));

        $response->assertOk();
    }
}
