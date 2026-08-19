<?php

namespace Tests\Feature\AiAgent;

use App\Jobs\ProcessWhatsAppAiAgentMessage;
use App\Models\WhatsAppBusinessAccount;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppPhoneNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithWhatsAppSchema;
use Tests\TestCase;

/**
 * WhatsApp counterpart of MessageCoalescingTest.php (Messenger) — mirrors
 * it one-for-one. Unlike Messenger, WhatsApp's send path has no separate
 * typing-indicator call (see ProcessWhatsAppAiAgentMessage::process()'s
 * docblock), so each turn hits the WhatsApp messages endpoint exactly
 * once, not twice.
 */
class WhatsAppMessageCoalescingTest extends TestCase
{
    use InteractsWithWhatsAppSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpWhatsAppSchema();
    }

    protected function connectPhoneNumber(int $tenantId, string $phoneNumberId = 'pnid-coalesce'): void
    {
        $user = $this->makeUser($tenantId);
        $account = WhatsAppBusinessAccount::withoutGlobalScopes()->create([
            'tenant_id' => $tenantId, 'connected_by_user_id' => $user->id,
            'waba_id' => 'waba-'.$phoneNumberId, 'user_access_token' => 'token',
        ]);
        WhatsAppPhoneNumber::withoutGlobalScopes()->create([
            'tenant_id' => $tenantId, 'whatsapp_business_account_id' => $account->id,
            'phone_number_id' => $phoneNumberId, 'is_active' => 1, 'status' => 'active',
        ]);
    }

    /** Inserts an inbound WhatsAppMessage + its 'pending' job row, with conversation_key stamped — mirrors what the real webhook controller now writes. */
    protected function seedPendingFragment(int $tenantId, string $waId, string $wamid, string $text): int
    {
        $messageId = DB::table('whatsapp_messages')->insertGetId([
            'tenant_id' => $tenantId,
            'wa_id' => $waId,
            'wamid' => $wamid,
            'message_type' => 'text',
            'message_text' => $text,
            'direction' => 'in',
            'status' => 'new',
            'created_at' => now(),
        ]);

        DB::table('ai_whatsapp_message_jobs')->insert([
            'tenant_id' => $tenantId,
            'whatsapp_message_id' => $messageId,
            'conversation_key' => $waId,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $messageId;
    }

    public function test_a_burst_of_fragments_produces_exactly_one_reply_combining_all_of_them(): void
    {
        config(['ai.openai_api_key' => 'test-key']);
        $tenant = $this->makeTenant();
        $this->connectPhoneNumber($tenant->id);
        $this->enableAiAgentAndWhatsAppAutoReply($tenant->id);
        $this->allocateAiCredit($tenant->id, 100);

        $id1 = $this->seedPendingFragment($tenant->id, '8801700000001', 'wamid.b1', 'আমার');
        $id2 = $this->seedPendingFragment($tenant->id, '8801700000001', 'wamid.b2', 'স্কিনে এখন');
        $id3 = $this->seedPendingFragment($tenant->id, '8801700000001', 'wamid.b3', 'লালচে');
        $id4 = $this->seedPendingFragment($tenant->id, '8801700000001', 'wamid.b4', 'দাগ');

        Http::fake([
            '*/chat/completions' => Http::response(['choices' => [['message' => ['content' => 'দুঃখিত শুনে।']]]]),
            '*/messages' => Http::response(['messages' => [['id' => 'wamid.burst-reply']]]),
        ]);

        ProcessWhatsAppAiAgentMessage::dispatch($tenant->id, $id1);
        ProcessWhatsAppAiAgentMessage::dispatch($tenant->id, $id2);
        ProcessWhatsAppAiAgentMessage::dispatch($tenant->id, $id3);
        ProcessWhatsAppAiAgentMessage::dispatch($tenant->id, $id4);

        Http::assertSentCount(2); // one chat/completions + one send, from the winning (last) job only

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'chat/completions')) {
                return true;
            }

            $userTurns = array_column(array_filter($request->data()['messages'] ?? [], fn ($m) => $m['role'] === 'user'), 'content');

            return $userTurns === ['আমার স্কিনে এখন লালচে দাগ'];
        });

        $this->assertSame(
            1,
            WhatsAppMessage::withoutGlobalScopes()->where('direction', 'out')->where('tenant_id', $tenant->id)->count(),
            'a coalesced burst must produce exactly one outgoing reply, not one per fragment'
        );

        foreach ([$id1, $id2, $id3, $id4] as $id) {
            $this->assertSame(
                'completed',
                DB::table('ai_whatsapp_message_jobs')->where('whatsapp_message_id', $id)->value('status')
            );
        }
    }

    public function test_a_deferred_fragment_leaves_no_reply_and_does_not_call_openai(): void
    {
        config(['ai.openai_api_key' => 'test-key']);
        $tenant = $this->makeTenant();
        $this->connectPhoneNumber($tenant->id);
        $this->enableAiAgentAndWhatsAppAutoReply($tenant->id);
        $this->allocateAiCredit($tenant->id, 100);

        $id1 = $this->seedPendingFragment($tenant->id, '8801700000002', 'wamid.c1', 'আমার');
        $this->seedPendingFragment($tenant->id, '8801700000002', 'wamid.c2', 'অর্ডার কই?');

        Http::fake();

        ProcessWhatsAppAiAgentMessage::dispatch($tenant->id, $id1);

        Http::assertNothingSent();
        $this->assertSame('pending', DB::table('ai_whatsapp_message_jobs')->where('whatsapp_message_id', $id1)->value('status'));
    }

    public function test_coalescing_never_crosses_tenants_even_with_the_same_conversation_key(): void
    {
        config(['ai.openai_api_key' => 'test-key']);
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $this->connectPhoneNumber($tenantA->id, 'pnid-a');
        $this->connectPhoneNumber($tenantB->id, 'pnid-b');
        $this->enableAiAgentAndWhatsAppAutoReply($tenantA->id);
        $this->enableAiAgentAndWhatsAppAutoReply($tenantB->id);
        $this->allocateAiCredit($tenantA->id, 100);
        $this->allocateAiCredit($tenantB->id, 100);

        $idA = $this->seedPendingFragment($tenantA->id, '8801700000003', 'wamid.cross-a', 'দাম কত?');
        $idB = $this->seedPendingFragment($tenantB->id, '8801700000003', 'wamid.cross-b', 'স্টকে আছে?');

        Http::fake([
            '*/chat/completions' => Http::response(['choices' => [['message' => ['content' => 'ok']]]]),
            '*/messages' => Http::response(['messages' => [['id' => 'wamid.cross-reply']]]),
        ]);

        ProcessWhatsAppAiAgentMessage::dispatch($tenantA->id, $idA);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'chat/completions')) {
                return true;
            }

            $userTurns = array_column(array_filter($request->data()['messages'] ?? [], fn ($m) => $m['role'] === 'user'), 'content');

            return $userTurns === ['দাম কত?'];
        });

        $this->assertSame('pending', DB::table('ai_whatsapp_message_jobs')->where('whatsapp_message_id', $idB)->value('status'));
    }
}
