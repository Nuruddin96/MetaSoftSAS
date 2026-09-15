<?php

namespace Tests\Feature\AiAgent;

use App\Models\MessengerMessage;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithAiAgentSchema;
use Tests\TestCase;

/**
 * Covers MessengerMessage::isHumanPaused() — the customer-specific,
 * lazily-expiring 15-minute AI pause that starts the moment a genuine
 * staff reply (sent_by='human') is sent, keyed by tenant_id + sender_psid
 * exactly like every other per-conversation state in this app (see
 * AiHandoffService's tenant+channel+external_id scoping).
 */
class MessengerHumanPauseTest extends TestCase
{
    use InteractsWithAiAgentSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAiAgentSchema();
    }

    protected function insertOutbound(int $tenantId, string $psid, string $sentBy, \DateTimeInterface $createdAt): void
    {
        DB::table('messenger_messages')->insert([
            'tenant_id' => $tenantId, 'sender_psid' => $psid, 'direction' => 'out',
            'sent_by' => $sentBy, 'message_text' => 'reply', 'created_at' => $createdAt,
        ]);
    }

    public function test_a_human_reply_five_minutes_ago_pauses_the_ai(): void
    {
        $tenant = $this->makeTenant();
        $this->insertOutbound($tenant->id, 'cust-1', 'human', now()->subMinutes(5));

        $this->assertTrue(MessengerMessage::isHumanPaused($tenant->id, 'cust-1'));
    }

    public function test_a_human_reply_sixteen_minutes_ago_no_longer_pauses_the_ai(): void
    {
        $tenant = $this->makeTenant();
        $this->insertOutbound($tenant->id, 'cust-1', 'human', now()->subMinutes(16));

        $this->assertFalse(MessengerMessage::isHumanPaused($tenant->id, 'cust-1'));
    }

    public function test_an_ai_generated_reply_never_pauses_the_ai(): void
    {
        $tenant = $this->makeTenant();
        $this->insertOutbound($tenant->id, 'cust-1', 'ai', now());

        $this->assertFalse(MessengerMessage::isHumanPaused($tenant->id, 'cust-1'));
    }

    public function test_pausing_one_customer_does_not_affect_another_customer(): void
    {
        $tenant = $this->makeTenant();
        $this->insertOutbound($tenant->id, 'cust-a', 'human', now());

        $this->assertTrue(MessengerMessage::isHumanPaused($tenant->id, 'cust-a'));
        $this->assertFalse(MessengerMessage::isHumanPaused($tenant->id, 'cust-b'));
    }

    public function test_pausing_a_customer_in_one_tenant_does_not_affect_the_same_psid_in_another_tenant(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $this->insertOutbound($tenantA->id, 'shared-psid', 'human', now());

        $this->assertTrue(MessengerMessage::isHumanPaused($tenantA->id, 'shared-psid'));
        $this->assertFalse(MessengerMessage::isHumanPaused($tenantB->id, 'shared-psid'));
    }
}
