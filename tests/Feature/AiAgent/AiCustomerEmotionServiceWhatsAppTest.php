<?php

namespace Tests\Feature\AiAgent;

use App\Services\AI\AiCustomerEmotionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\InteractsWithWhatsAppSchema;
use Tests\TestCase;

/**
 * WhatsApp counterpart of AiCustomerEmotionServiceTest — covers
 * AiCustomerEmotionService::forWhatsAppCustomer() (Phase 8).
 */
class AiCustomerEmotionServiceWhatsAppTest extends TestCase
{
    use InteractsWithWhatsAppSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpWhatsAppSchema();
    }

    protected function seedMessage(int $tenantId, string $waId, string $direction, ?int $createdAtMinutesAgo = 0): int
    {
        return DB::table('whatsapp_messages')->insertGetId([
            'tenant_id' => $tenantId,
            'wa_id' => $waId,
            'message_type' => 'text',
            'message_text' => 'x',
            'direction' => $direction,
            'status' => 'contacted',
            'created_at' => now()->subMinutes($createdAtMinutesAgo ?? 0),
        ]);
    }

    public function test_returns_empty_string_for_a_customers_very_first_message(): void
    {
        $tenant = $this->makeTenant();
        $currentMessageId = $this->seedMessage($tenant->id, '8801700000000', 'in');

        $signal = app(AiCustomerEmotionService::class)->forWhatsAppCustomer($tenant->id, '8801700000000', $currentMessageId);

        $this->assertSame('', $signal);
    }

    public function test_returns_empty_string_when_the_previous_message_was_already_answered(): void
    {
        $tenant = $this->makeTenant();
        $this->seedMessage($tenant->id, '8801700000000', 'in', 20);
        $this->seedMessage($tenant->id, '8801700000000', 'out', 15);
        $currentMessageId = $this->seedMessage($tenant->id, '8801700000000', 'in', 0);

        $signal = app(AiCustomerEmotionService::class)->forWhatsAppCustomer($tenant->id, '8801700000000', $currentMessageId);

        $this->assertSame('', $signal);
    }

    public function test_reports_the_count_and_duration_when_a_prior_message_went_unanswered(): void
    {
        $tenant = $this->makeTenant();
        $this->seedMessage($tenant->id, '8801700000000', 'in', 45);
        $currentMessageId = $this->seedMessage($tenant->id, '8801700000000', 'in', 0);

        $signal = app(AiCustomerEmotionService::class)->forWhatsAppCustomer($tenant->id, '8801700000000', $currentMessageId);

        $this->assertStringContainsString('2 messages in a row without a reply', $signal);
        $this->assertStringContainsString('45 minute(s)', $signal);
    }

    public function test_reports_days_when_the_wait_is_very_long(): void
    {
        $tenant = $this->makeTenant();
        $this->seedMessage($tenant->id, '8801700000000', 'in', 60 * 24 * 2);
        $currentMessageId = $this->seedMessage($tenant->id, '8801700000000', 'in', 0);

        $signal = app(AiCustomerEmotionService::class)->forWhatsAppCustomer($tenant->id, '8801700000000', $currentMessageId);

        $this->assertStringContainsString('2 day(s)', $signal);
    }

    public function test_never_leaks_tenant_as_wait_signal_to_tenant_b(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $this->seedMessage($tenantA->id, '8801700000000', 'in', 30);

        $currentMessageIdForB = DB::table('whatsapp_messages')->insertGetId([
            'tenant_id' => $tenantB->id, 'wa_id' => '8801700000000', 'message_type' => 'text', 'message_text' => 'x',
            'direction' => 'in', 'status' => 'new', 'created_at' => now(),
        ]);

        $signal = app(AiCustomerEmotionService::class)->forWhatsAppCustomer($tenantB->id, '8801700000000', $currentMessageIdForB);

        $this->assertSame('', $signal);
    }

    public function test_lookup_failure_degrades_to_empty_string_instead_of_throwing(): void
    {
        $tenant = $this->makeTenant();
        $currentMessageId = $this->seedMessage($tenant->id, '8801700000000', 'in');

        Schema::dropIfExists('whatsapp_messages');

        $signal = app(AiCustomerEmotionService::class)->forWhatsAppCustomer($tenant->id, '8801700000000', $currentMessageId);

        $this->assertSame('', $signal);
    }
}
