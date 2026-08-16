<?php

namespace Tests\Feature\AiAgent;

use App\Services\AI\AiConversationStyleService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithAiAgentSchema;
use Tests\TestCase;

/**
 * Covers App\Services\AI\AiConversationStyleService — the tenant-specific
 * "how does this business's own staff actually talk" example builder
 * introduced to fix over-formal, robotic AI replies. The single most
 * important property under test here is that it NEVER learns from the
 * AI's own past replies (sent_by='ai') — only genuine human ones.
 */
class AiConversationStyleServiceTest extends TestCase
{
    use InteractsWithAiAgentSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpAiAgentSchema();
    }

    protected function seedMessage(int $tenantId, string $psid, string $direction, string $text, string $sentBy = 'human', ?int $createdAtOffsetSeconds = null): int
    {
        return DB::table('messenger_messages')->insertGetId([
            'tenant_id' => $tenantId,
            'sender_psid' => $psid,
            'message_text' => $text,
            'direction' => $direction,
            'sent_by' => $sentBy,
            'status' => 'contacted',
            'created_at' => now()->addSeconds($createdAtOffsetSeconds ?? 0),
        ]);
    }

    public function test_returns_empty_string_when_there_is_no_history_yet(): void
    {
        $tenant = $this->makeTenant();

        $examples = app(AiConversationStyleService::class)->messengerStyleExamples($tenant->id);

        $this->assertSame('', $examples);
    }

    public function test_includes_a_genuine_human_reply_paired_with_the_preceding_customer_message(): void
    {
        $tenant = $this->makeTenant();
        $this->seedMessage($tenant->id, 'psid-1', 'in', 'দাম কত?', createdAtOffsetSeconds: 0);
        $this->seedMessage($tenant->id, 'psid-1', 'out', 'আপু এটা ১২৫০ টাকা 😊', 'human', createdAtOffsetSeconds: 5);

        $examples = app(AiConversationStyleService::class)->messengerStyleExamples($tenant->id);

        $this->assertStringContainsString('দাম কত?', $examples);
        $this->assertStringContainsString('আপু এটা ১২৫০ টাকা 😊', $examples);
    }

    public function test_excludes_ai_generated_replies_from_the_examples(): void
    {
        // This is the single most important behavior this class exists
        // for: an AI-generated reply must never be usable as a style
        // example, or the AI would learn from (and reinforce) its own
        // robotic phrasing.
        $tenant = $this->makeTenant();
        $this->seedMessage($tenant->id, 'psid-1', 'in', 'দাম কত?', createdAtOffsetSeconds: 0);
        $this->seedMessage($tenant->id, 'psid-1', 'out', 'অবশ্যই! আমাদের এই প্রোডাক্টটির মূল্য হচ্ছে ১২৫০ টাকা।', 'ai', createdAtOffsetSeconds: 5);

        $examples = app(AiConversationStyleService::class)->messengerStyleExamples($tenant->id);

        $this->assertSame('', $examples, 'an AI-generated reply must never appear as a style example');
    }

    public function test_a_mix_of_human_and_ai_replies_only_surfaces_the_human_ones(): void
    {
        $tenant = $this->makeTenant();
        $this->seedMessage($tenant->id, 'psid-1', 'in', 'ডেলিভারি কত?', createdAtOffsetSeconds: 0);
        $this->seedMessage($tenant->id, 'psid-1', 'out', 'ঢাকার ভিতরে ৬০ টাকা, বাইরে ১২০ টাকা।', 'human', createdAtOffsetSeconds: 5);
        $this->seedMessage($tenant->id, 'psid-1', 'in', 'আরেকটা প্রশ্ন', createdAtOffsetSeconds: 10);
        $this->seedMessage($tenant->id, 'psid-1', 'out', 'অবশ্যই! আপনার প্রশ্নের জন্য ধন্যবাদ।', 'ai', createdAtOffsetSeconds: 15);

        $examples = app(AiConversationStyleService::class)->messengerStyleExamples($tenant->id);

        $this->assertStringContainsString('ঢাকার ভিতরে ৬০ টাকা', $examples);
        $this->assertStringNotContainsString('আপনার প্রশ্নের জন্য ধন্যবাদ', $examples);
    }

    public function test_style_examples_never_leak_across_tenants(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $this->seedMessage($tenantA->id, 'psid-a', 'in', 'দাম কত?', createdAtOffsetSeconds: 0);
        $this->seedMessage($tenantA->id, 'psid-a', 'out', 'তেন্যান্ট A এর গোপন রিপ্লাই স্টাইল', 'human', createdAtOffsetSeconds: 5);

        $examplesForB = app(AiConversationStyleService::class)->messengerStyleExamples($tenantB->id);

        $this->assertSame('', $examplesForB);
        $this->assertStringNotContainsString('তেন্যান্ট A', $examplesForB);
    }

    public function test_respects_the_configured_max_example_count(): void
    {
        config(['ai.style_examples_max' => 2]);
        $tenant = $this->makeTenant();

        for ($i = 1; $i <= 5; $i++) {
            $this->seedMessage($tenant->id, "psid-{$i}", 'in', "প্রশ্ন {$i}", createdAtOffsetSeconds: $i * 10);
            $this->seedMessage($tenant->id, "psid-{$i}", 'out', "উত্তর {$i}", 'human', createdAtOffsetSeconds: $i * 10 + 5);
        }

        $examples = app(AiConversationStyleService::class)->messengerStyleExamples($tenant->id);

        $this->assertSame(2, substr_count($examples, 'Customer:'));
    }

    public function test_building_the_style_profile_never_does_a_query_per_candidate_reply(): void
    {
        // Phase 17 — regression guard for the N+1 this class used to have:
        // one extra query PER candidate human reply to find its preceding
        // customer message. With style_examples_max=6 (default), the old
        // code made up to 1 + 18 = 19 queries; the fix batches the
        // "preceding message" lookup into a single query regardless of
        // how many distinct conversations are involved.
        config(['ai.style_examples_max' => 6]);
        $tenant = $this->makeTenant();

        for ($i = 1; $i <= 15; $i++) {
            $this->seedMessage($tenant->id, "psid-query-{$i}", 'in', "প্রশ্ন {$i}", createdAtOffsetSeconds: $i * 10);
            $this->seedMessage($tenant->id, "psid-query-{$i}", 'out', "উত্তর {$i}", 'human', createdAtOffsetSeconds: $i * 10 + 5);
        }

        \Illuminate\Support\Facades\DB::enableQueryLog();
        app(AiConversationStyleService::class)->messengerStyleExamples($tenant->id);
        $queryCount = count(\Illuminate\Support\Facades\DB::getQueryLog());
        \Illuminate\Support\Facades\DB::disableQueryLog();

        $this->assertLessThanOrEqual(5, $queryCount, 'building the style profile must stay a small, constant number of queries regardless of how many distinct conversations are candidates');
    }

    public function test_truncates_an_unusually_long_historical_reply(): void
    {
        config(['ai.style_example_max_chars' => 30]);
        $tenant = $this->makeTenant();
        $this->seedMessage($tenant->id, 'psid-1', 'in', 'বিস্তারিত বলুন', createdAtOffsetSeconds: 0);
        $longReply = str_repeat('অনেক লম্বা একটা রিপ্লাই যেটা কেটে ফেলা উচিত ', 5);
        $this->seedMessage($tenant->id, 'psid-1', 'out', $longReply, 'human', createdAtOffsetSeconds: 5);

        $examples = app(AiConversationStyleService::class)->messengerStyleExamples($tenant->id);

        // The full untrimmed reply must never appear verbatim — only its
        // truncated form (with the ellipsis marker) does.
        $this->assertStringNotContainsString($longReply, $examples);
        $this->assertStringContainsString('…', $examples);
    }

    public function test_a_human_reply_with_no_preceding_customer_message_is_skipped(): void
    {
        $tenant = $this->makeTenant();
        // A staff-initiated outbound message — no inbound message exists
        // for this psid at all, so there is nothing to pair it with.
        $this->seedMessage($tenant->id, 'psid-1', 'out', 'স্বাগতম!', 'human');

        $examples = app(AiConversationStyleService::class)->messengerStyleExamples($tenant->id);

        $this->assertSame('', $examples);
    }

    public function test_result_is_cached_and_does_not_reflect_a_new_reply_within_the_cache_window(): void
    {
        $tenant = $this->makeTenant();
        $this->seedMessage($tenant->id, 'psid-1', 'in', 'প্রথম প্রশ্ন', createdAtOffsetSeconds: 0);
        $this->seedMessage($tenant->id, 'psid-1', 'out', 'প্রথম উত্তর', 'human', createdAtOffsetSeconds: 5);

        $service = app(AiConversationStyleService::class);
        $first = $service->messengerStyleExamples($tenant->id);

        $this->seedMessage($tenant->id, 'psid-2', 'in', 'নতুন প্রশ্ন', createdAtOffsetSeconds: 10);
        $this->seedMessage($tenant->id, 'psid-2', 'out', 'নতুন উত্তর', 'human', createdAtOffsetSeconds: 15);

        $second = $service->messengerStyleExamples($tenant->id);

        $this->assertSame($first, $second, 'must be served from cache within the configured window, not rebuilt on every call');
    }

    public function test_forgetting_the_cache_makes_a_new_human_reply_visible_immediately(): void
    {
        // The mechanism behind "human corrections are a high-value signal"
        // — a correction should influence the very next reply, not wait
        // out the full cache window.
        $tenant = $this->makeTenant();
        $this->seedMessage($tenant->id, 'psid-1', 'in', 'প্রথম প্রশ্ন', createdAtOffsetSeconds: 0);
        $this->seedMessage($tenant->id, 'psid-1', 'out', 'প্রথম উত্তর', 'human', createdAtOffsetSeconds: 5);

        $service = app(AiConversationStyleService::class);
        $first = $service->messengerStyleExamples($tenant->id);

        $this->seedMessage($tenant->id, 'psid-2', 'in', 'দ্বিতীয় প্রশ্ন', createdAtOffsetSeconds: 10);
        $this->seedMessage($tenant->id, 'psid-2', 'out', 'সংশোধিত উত্তর এখানে', 'human', createdAtOffsetSeconds: 15);

        $service->forgetMessengerStyleCache($tenant->id);
        $second = $service->messengerStyleExamples($tenant->id);

        $this->assertNotSame($first, $second);
        $this->assertStringContainsString('সংশোধিত উত্তর এখানে', $second);
    }

    public function test_forgetting_one_tenants_cache_never_affects_another_tenants(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $this->seedMessage($tenantA->id, 'psid-a', 'in', 'A এর প্রশ্ন', createdAtOffsetSeconds: 0);
        $this->seedMessage($tenantA->id, 'psid-a', 'out', 'A এর উত্তর', 'human', createdAtOffsetSeconds: 5);
        $this->seedMessage($tenantB->id, 'psid-b', 'in', 'B এর প্রশ্ন', createdAtOffsetSeconds: 0);
        $this->seedMessage($tenantB->id, 'psid-b', 'out', 'B এর উত্তর', 'human', createdAtOffsetSeconds: 5);

        $service = app(AiConversationStyleService::class);
        $service->messengerStyleExamples($tenantA->id);
        $bBeforeForget = $service->messengerStyleExamples($tenantB->id);

        $service->forgetMessengerStyleCache($tenantA->id);

        $bAfterForget = $service->messengerStyleExamples($tenantB->id);

        $this->assertSame($bBeforeForget, $bAfterForget, "forgetting tenant A's cache must never affect tenant B's");
    }

    public function test_profile_line_reports_the_typical_sentence_and_character_length(): void
    {
        $tenant = $this->makeTenant();
        $this->seedMessage($tenant->id, 'psid-1', 'in', 'দাম কত?', createdAtOffsetSeconds: 0);
        $this->seedMessage($tenant->id, 'psid-1', 'out', 'এটা ১২০০ টাকা।', 'human', createdAtOffsetSeconds: 5);

        $examples = app(AiConversationStyleService::class)->messengerStyleExamples($tenant->id);

        $this->assertStringContainsString('typically reply in about', $examples);
        $this->assertStringContainsString('sentence', $examples);
        $this->assertStringContainsString('characters', $examples);
    }

    public function test_profile_line_reports_a_low_address_term_habit_when_rarely_used(): void
    {
        $tenant = $this->makeTenant();
        for ($i = 1; $i <= 4; $i++) {
            $this->seedMessage($tenant->id, "psid-{$i}", 'in', "প্রশ্ন {$i}", createdAtOffsetSeconds: $i * 10);
            $this->seedMessage($tenant->id, "psid-{$i}", 'out', "এটা {$i}00 টাকা।", 'human', createdAtOffsetSeconds: $i * 10 + 5);
        }

        $examples = app(AiConversationStyleService::class)->messengerStyleExamples($tenant->id);

        $this->assertStringContainsString('rarely uses আপু/ভাইয়া', $examples);
    }

    public function test_profile_line_reports_a_frequent_address_term_habit_when_commonly_used(): void
    {
        $tenant = $this->makeTenant();
        for ($i = 1; $i <= 4; $i++) {
            $this->seedMessage($tenant->id, "psid-{$i}", 'in', "প্রশ্ন {$i}", createdAtOffsetSeconds: $i * 10);
            $this->seedMessage($tenant->id, "psid-{$i}", 'out', "আপু এটা {$i}00 টাকা।", 'human', createdAtOffsetSeconds: $i * 10 + 5);
        }

        $examples = app(AiConversationStyleService::class)->messengerStyleExamples($tenant->id);

        $this->assertStringContainsString('often uses আপু/ভাইয়া', $examples);
    }

    public function test_a_memory_lookup_failure_degrades_to_no_style_examples_instead_of_throwing(): void
    {
        $tenant = $this->makeTenant();
        $this->seedMessage($tenant->id, 'psid-1', 'in', 'দাম কত?', createdAtOffsetSeconds: 0);
        $this->seedMessage($tenant->id, 'psid-1', 'out', 'এটা ১২০০ টাকা।', 'human', createdAtOffsetSeconds: 5);

        // Simulate the underlying cache store genuinely failing mid-lookup
        // — the AI reply pipeline must never crash because of this.
        Cache::shouldReceive('remember')->andThrow(new \RuntimeException('simulated cache failure'));

        $examples = app(AiConversationStyleService::class)->messengerStyleExamples($tenant->id);

        $this->assertSame('', $examples);
    }
}
