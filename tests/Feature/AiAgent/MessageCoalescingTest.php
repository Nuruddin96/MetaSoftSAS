<?php

namespace Tests\Feature\AiAgent;

use App\Jobs\ProcessAiAgentMessage;
use App\Models\MessengerMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithAiAgentSchema;
use Tests\TestCase;

/**
 * Covers Part 12/13 of the Customer Sales + Care Agent upgrade — message
 * coalescing/debounce. A customer who sends one sentence as several rapid
 * fragments ("আমার" / "স্কিনে এখন" / "লালচে" / "দাগ") must get exactly
 * ONE AI reply, combining all of them into one logical turn, not one
 * reply per fragment.
 *
 * QUEUE_CONNECTION=sync in phpunit.xml means ::dispatch() runs the job
 * body inline and ignores ->delay() entirely (the sync driver has no
 * concept of a delayed run) — so these tests simulate a "burst" the same
 * way the real webhook controller would have left things at the moment
 * each job actually executes: every fragment's own 'pending'
 * ai_agent_message_jobs row (with conversation_key already stamped) is
 * inserted BEFORE any of their jobs are dispatched, exactly as multiple
 * rapid webhook deliveries would have queued them before the first
 * (debounced) job fires for real. This tests the coalescing DECISION
 * logic precisely, independent of real wall-clock timing.
 */
class MessageCoalescingTest extends TestCase
{
    use InteractsWithAiAgentSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpAiAgentSchema();
    }

    /** Inserts an inbound MessengerMessage + its 'pending' job row, with conversation_key stamped — mirrors what the real webhook controller now writes. */
    protected function seedPendingFragment(int $tenantId, string $psid, string $mid, string $text): int
    {
        $messageId = DB::table('messenger_messages')->insertGetId([
            'tenant_id' => $tenantId,
            'sender_psid' => $psid,
            'mid' => $mid,
            'message_text' => $text,
            'direction' => 'in',
            'status' => 'new',
            'created_at' => now(),
        ]);

        DB::table('ai_agent_message_jobs')->insert([
            'tenant_id' => $tenantId,
            'messenger_message_id' => $messageId,
            'conversation_key' => $psid,
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
        $this->makeMessengerPage($tenant->id, 'page-coalesce-1', ['is_active' => 1]);
        $this->enableAiAgentAndMessengerAutoReply($tenant->id);
        $this->allocateAiCredit($tenant->id, 100);

        // All four fragments already sitting 'pending' before any job runs
        // — exactly the state a rapid burst leaves things in once every
        // webhook delivery has finished (each one cheap and synchronous)
        // but before the first debounced job fires.
        $id1 = $this->seedPendingFragment($tenant->id, 'cust-burst-1', 'mid-b1', 'আমার');
        $id2 = $this->seedPendingFragment($tenant->id, 'cust-burst-1', 'mid-b2', 'স্কিনে এখন');
        $id3 = $this->seedPendingFragment($tenant->id, 'cust-burst-1', 'mid-b3', 'লালচে');
        $id4 = $this->seedPendingFragment($tenant->id, 'cust-burst-1', 'mid-b4', 'দাগ');

        Http::fake([
            '*/chat/completions' => Http::response(['choices' => [['message' => ['content' => 'দুঃখিত শুনে, এটা একটু দেখতে হবে।']]]]),
            '*/me/messages*' => Http::response(['message_id' => 'mid-burst-reply']),
        ]);

        // Fragment jobs "fire" in arrival order — the first three each
        // find a newer 'pending' fragment for the same conversation and
        // defer without generating anything; only the last one, finding
        // nothing newer, actually processes the whole batch.
        ProcessAiAgentMessage::dispatch($tenant->id, $id1);
        ProcessAiAgentMessage::dispatch($tenant->id, $id2);
        ProcessAiAgentMessage::dispatch($tenant->id, $id3);
        ProcessAiAgentMessage::dispatch($tenant->id, $id4);

        // Exactly one chat/completions call, one typing indicator, one send.
        Http::assertSentCount(3);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'chat/completions')) {
                return true; // not the call we're checking here
            }

            $userTurns = array_column(array_filter($request->data()['messages'] ?? [], fn ($m) => $m['role'] === 'user'), 'content');

            return $userTurns === ['আমার স্কিনে এখন লালচে দাগ'];
        });

        $this->assertSame(
            1,
            MessengerMessage::withoutGlobalScopes()->where('direction', 'out')->where('tenant_id', $tenant->id)->count(),
            'a coalesced burst must produce exactly one outgoing reply, not one per fragment'
        );

        foreach ([$id1, $id2, $id3, $id4] as $id) {
            $this->assertSame(
                'completed',
                DB::table('ai_agent_message_jobs')->where('messenger_message_id', $id)->value('status'),
                "fragment {$id}'s job row must be marked completed as part of the batch"
            );
        }
    }

    public function test_a_deferred_fragment_leaves_no_reply_and_does_not_call_openai(): void
    {
        config(['ai.openai_api_key' => 'test-key']);
        $tenant = $this->makeTenant();
        $this->makeMessengerPage($tenant->id, 'page-coalesce-2', ['is_active' => 1]);
        $this->enableAiAgentAndMessengerAutoReply($tenant->id);
        $this->allocateAiCredit($tenant->id, 100);

        $id1 = $this->seedPendingFragment($tenant->id, 'cust-burst-2', 'mid-c1', 'আমার');
        $id2 = $this->seedPendingFragment($tenant->id, 'cust-burst-2', 'mid-c2', 'অর্ডার কই?');

        Http::fake(); // the deferred job must call NOTHING at all

        ProcessAiAgentMessage::dispatch($tenant->id, $id1);

        Http::assertNothingSent();
        $this->assertSame('pending', DB::table('ai_agent_message_jobs')->where('messenger_message_id', $id1)->value('status'));
        $this->assertSame(0, MessengerMessage::withoutGlobalScopes()->where('direction', 'out')->count());
    }

    public function test_two_genuinely_separate_turns_outside_a_burst_each_get_their_own_reply(): void
    {
        // The spec's explicit counter-example: "দাম কত?" then, as its own
        // separate later turn (not part of the same burst — no other
        // fragment pending when it arrives), "আর delivery charge?" — two
        // distinct questions, two replies, never force-merged.
        config(['ai.openai_api_key' => 'test-key']);
        $tenant = $this->makeTenant();
        $this->makeMessengerPage($tenant->id, 'page-coalesce-3', ['is_active' => 1]);
        $this->enableAiAgentAndMessengerAutoReply($tenant->id);
        $this->allocateAiCredit($tenant->id, 100);

        $id1 = $this->seedPendingFragment($tenant->id, 'cust-separate-1', 'mid-sep-1', 'দাম কত?');

        // A single sequence covering BOTH turns — Http::fake() stubs are
        // matched in registration order (first match wins), so calling
        // Http::fake() a second time later would never actually override
        // a still-matching earlier pattern. Each turn hits */me/messages*
        // TWICE (sendTypingOn(), then the actual sendMessage()), so the
        // sequence needs two entries per turn, not one.
        Http::fake([
            '*/chat/completions' => Http::sequence()
                ->push(['choices' => [['message' => ['content' => '৫০০ টাকা।']]]])
                ->push(['choices' => [['message' => ['content' => 'ঢাকার ভিতরে ৮০ টাকা।']]]]),
            '*/me/messages*' => Http::sequence()
                ->push([]) // turn 1's sendTypingOn() — response content unused
                ->push(['message_id' => 'mid-sep-reply-1']) // turn 1's actual send
                ->push([]) // turn 2's sendTypingOn()
                ->push(['message_id' => 'mid-sep-reply-2']), // turn 2's actual send
        ]);

        ProcessAiAgentMessage::dispatch($tenant->id, $id1);

        // Only seeded (and dispatched) AFTER the first turn's job already
        // ran to completion — nothing was pending for this conversation
        // when it arrived, so it starts a fresh turn of its own.
        $id2 = $this->seedPendingFragment($tenant->id, 'cust-separate-1', 'mid-sep-2', 'আর delivery charge?');

        ProcessAiAgentMessage::dispatch($tenant->id, $id2);

        $this->assertSame(
            2,
            MessengerMessage::withoutGlobalScopes()->where('direction', 'out')->where('tenant_id', $tenant->id)->count(),
            'two separate turns must produce two separate replies'
        );
    }

    public function test_coalescing_never_crosses_tenants_even_with_the_same_conversation_key(): void
    {
        config(['ai.openai_api_key' => 'test-key']);
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $this->makeMessengerPage($tenantA->id, 'page-coalesce-a', ['is_active' => 1]);
        $this->makeMessengerPage($tenantB->id, 'page-coalesce-b', ['is_active' => 1]);
        $this->enableAiAgentAndMessengerAutoReply($tenantA->id);
        $this->enableAiAgentAndMessengerAutoReply($tenantB->id);
        $this->allocateAiCredit($tenantA->id, 100);
        $this->allocateAiCredit($tenantB->id, 100);

        // Same raw psid string reused across two different tenants (two
        // different Facebook Pages can technically see the same visitor).
        $idA = $this->seedPendingFragment($tenantA->id, 'shared-psid', 'mid-cross-a', 'দাম কত?');
        $idB = $this->seedPendingFragment($tenantB->id, 'shared-psid', 'mid-cross-b', 'স্টকে আছে?');

        Http::fake([
            '*/chat/completions' => Http::response(['choices' => [['message' => ['content' => 'ok']]]]),
            '*/me/messages*' => Http::response(['message_id' => 'mid-cross-reply']),
        ]);

        // Tenant A's job must not defer to tenant B's pending row, and
        // must not combine tenant B's text into its own reply.
        ProcessAiAgentMessage::dispatch($tenantA->id, $idA);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'chat/completions')) {
                return true;
            }

            $userTurns = array_column(array_filter($request->data()['messages'] ?? [], fn ($m) => $m['role'] === 'user'), 'content');

            return $userTurns === ['দাম কত?'];
        });

        $this->assertSame('pending', DB::table('ai_agent_message_jobs')->where('messenger_message_id', $idB)->value('status'), "tenant B's row must be untouched by tenant A's job");
    }

    public function test_a_retried_execution_of_the_winning_job_does_not_send_a_second_reply(): void
    {
        config(['ai.openai_api_key' => 'test-key']);
        $tenant = $this->makeTenant();
        $this->makeMessengerPage($tenant->id, 'page-coalesce-4', ['is_active' => 1]);
        $this->enableAiAgentAndMessengerAutoReply($tenant->id);
        $this->allocateAiCredit($tenant->id, 100);

        $id1 = $this->seedPendingFragment($tenant->id, 'cust-burst-4', 'mid-d1', 'আছে');
        $id2 = $this->seedPendingFragment($tenant->id, 'cust-burst-4', 'mid-d2', 'কি?');

        Http::fake([
            '*/chat/completions' => Http::response(['choices' => [['message' => ['content' => 'জি আছে।']]]]),
            '*/me/messages*' => Http::response(['message_id' => 'mid-d-reply']),
        ]);

        ProcessAiAgentMessage::dispatch($tenant->id, $id1); // defers
        ProcessAiAgentMessage::dispatch($tenant->id, $id2); // wins, processes the batch
        ProcessAiAgentMessage::dispatch($tenant->id, $id2); // simulated retry of the SAME (winning) message

        Http::assertSentCount(3); // one chat/completions + typing + send, from the winning run only

        $this->assertSame(
            1,
            MessengerMessage::withoutGlobalScopes()->where('direction', 'out')->where('tenant_id', $tenant->id)->count()
        );
    }
}
