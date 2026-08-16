<?php

namespace Tests\Feature\AiAgent;

use App\Services\AI\AiCustomerEmotionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\InteractsWithAiAgentSchema;
use Tests\TestCase;

/**
 * Covers App\Services\AI\AiCustomerEmotionService — the Phase 8 "verified
 * elapsed-wait fact" layer. See that service's class docblock for why it
 * deliberately never guesses a mood from message text (keyword/sentiment
 * matching) — every scenario here is only about the objective count and
 * duration of unanswered messages.
 */
class AiCustomerEmotionServiceTest extends TestCase
{
    use InteractsWithAiAgentSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpAiAgentSchema();
    }

    protected function seedMessage(int $tenantId, string $psid, string $direction, ?int $createdAtMinutesAgo = 0): int
    {
        return DB::table('messenger_messages')->insertGetId([
            'tenant_id' => $tenantId,
            'sender_psid' => $psid,
            'message_text' => 'x',
            'direction' => $direction,
            'status' => 'contacted',
            'created_at' => now()->subMinutes($createdAtMinutesAgo ?? 0),
        ]);
    }

    public function test_returns_empty_string_for_a_customers_very_first_message(): void
    {
        $tenant = $this->makeTenant();
        $currentMessageId = $this->seedMessage($tenant->id, 'psid-1', 'in');

        $signal = app(AiCustomerEmotionService::class)->forMessengerCustomer($tenant->id, 'psid-1', $currentMessageId);

        $this->assertSame('', $signal);
    }

    public function test_returns_empty_string_when_the_previous_message_was_already_answered(): void
    {
        $tenant = $this->makeTenant();
        $this->seedMessage($tenant->id, 'psid-1', 'in', 20);
        $this->seedMessage($tenant->id, 'psid-1', 'out', 15);
        $currentMessageId = $this->seedMessage($tenant->id, 'psid-1', 'in', 0);

        $signal = app(AiCustomerEmotionService::class)->forMessengerCustomer($tenant->id, 'psid-1', $currentMessageId);

        $this->assertSame('', $signal);
    }

    public function test_reports_the_count_and_duration_when_two_prior_messages_went_unanswered(): void
    {
        $tenant = $this->makeTenant();
        $this->seedMessage($tenant->id, 'psid-1', 'in', 30);
        $this->seedMessage($tenant->id, 'psid-1', 'in', 20);
        $currentMessageId = $this->seedMessage($tenant->id, 'psid-1', 'in', 0);

        $signal = app(AiCustomerEmotionService::class)->forMessengerCustomer($tenant->id, 'psid-1', $currentMessageId);

        // 2 prior unanswered + the current message itself = 3.
        $this->assertStringContainsString('3 messages in a row without a reply', $signal);
        $this->assertStringContainsString('30 minute(s)', $signal);
    }

    public function test_reports_hours_when_the_wait_is_longer(): void
    {
        $tenant = $this->makeTenant();
        $this->seedMessage($tenant->id, 'psid-1', 'in', 180);
        $currentMessageId = $this->seedMessage($tenant->id, 'psid-1', 'in', 0);

        $signal = app(AiCustomerEmotionService::class)->forMessengerCustomer($tenant->id, 'psid-1', $currentMessageId);

        $this->assertStringContainsString('3 hour(s)', $signal);
    }

    public function test_never_matches_a_different_customer(): void
    {
        $tenant = $this->makeTenant();
        $this->seedMessage($tenant->id, 'psid-real', 'in', 30);
        $currentMessageId = $this->seedMessage($tenant->id, 'psid-other', 'in', 0);

        $signal = app(AiCustomerEmotionService::class)->forMessengerCustomer($tenant->id, 'psid-other', $currentMessageId);

        $this->assertSame('', $signal);
    }

    public function test_never_leaks_tenant_as_wait_signal_to_tenant_b(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $this->seedMessage($tenantA->id, 'shared-psid', 'in', 30);

        $currentMessageIdForB = DB::table('messenger_messages')->insertGetId([
            'tenant_id' => $tenantB->id, 'sender_psid' => 'shared-psid', 'message_text' => 'x',
            'direction' => 'in', 'status' => 'contacted', 'created_at' => now(),
        ]);

        $signal = app(AiCustomerEmotionService::class)->forMessengerCustomer($tenantB->id, 'shared-psid', $currentMessageIdForB);

        $this->assertSame('', $signal);
    }

    public function test_lookup_failure_degrades_to_empty_string_instead_of_throwing(): void
    {
        $tenant = $this->makeTenant();
        $currentMessageId = $this->seedMessage($tenant->id, 'psid-1', 'in');

        Schema::dropIfExists('messenger_messages');

        $signal = app(AiCustomerEmotionService::class)->forMessengerCustomer($tenant->id, 'psid-1', $currentMessageId);

        $this->assertSame('', $signal);
    }
}
