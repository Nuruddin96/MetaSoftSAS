<?php

namespace Tests\Feature\AiAgent;

use App\Services\AI\AiConversationStyleService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\InteractsWithWhatsAppSchema;
use Tests\TestCase;

/**
 * WhatsApp counterpart of AiConversationStyleServiceTest — covers
 * AiConversationStyleService::whatsappStyleExamples() (Phase 7). Same
 * single most important property under test: it NEVER learns from the
 * AI's own past replies (sent_by='ai') — only genuine human ones. See
 * that test class's docblock; this one intentionally does not repeat
 * every Messenger-side scenario (truncation, max-count, address-term
 * habit) since buildFromWhatsAppHistory() shares the same trimExample()/
 * buildProfileLine() helpers already proven there — only the
 * WhatsApp-specific data path (whatsapp_messages, wa_id pairing,
 * sentByColumnReady gating) needs its own coverage here.
 */
class AiConversationStyleServiceWhatsAppTest extends TestCase
{
    use InteractsWithWhatsAppSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpWhatsAppSchema();
    }

    protected function seedMessage(int $tenantId, string $waId, string $direction, string $text, string $sentBy = 'human', ?int $createdAtOffsetSeconds = null): int
    {
        return DB::table('whatsapp_messages')->insertGetId([
            'tenant_id' => $tenantId,
            'wa_id' => $waId,
            'message_type' => 'text',
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

        $examples = app(AiConversationStyleService::class)->whatsappStyleExamples($tenant->id);

        $this->assertSame('', $examples);
    }

    public function test_includes_a_genuine_human_reply_paired_with_the_preceding_customer_message(): void
    {
        $tenant = $this->makeTenant();
        $this->seedMessage($tenant->id, '8801700000000', 'in', 'দাম কত?', createdAtOffsetSeconds: 0);
        $this->seedMessage($tenant->id, '8801700000000', 'out', 'আপু এটা ১২৫০ টাকা 😊', 'human', createdAtOffsetSeconds: 5);

        $examples = app(AiConversationStyleService::class)->whatsappStyleExamples($tenant->id);

        $this->assertStringContainsString('দাম কত?', $examples);
        $this->assertStringContainsString('আপু এটা ১২৫০ টাকা 😊', $examples);
    }

    public function test_excludes_ai_generated_replies_from_the_examples(): void
    {
        $tenant = $this->makeTenant();
        $this->seedMessage($tenant->id, '8801700000000', 'in', 'দাম কত?', createdAtOffsetSeconds: 0);
        $this->seedMessage($tenant->id, '8801700000000', 'out', 'অবশ্যই! আমাদের এই প্রোডাক্টটির মূল্য হচ্ছে ১২৫০ টাকা।', 'ai', createdAtOffsetSeconds: 5);

        $examples = app(AiConversationStyleService::class)->whatsappStyleExamples($tenant->id);

        $this->assertSame('', $examples, 'an AI-generated reply must never appear as a style example');
    }

    public function test_a_mix_of_human_and_ai_replies_only_surfaces_the_human_ones(): void
    {
        $tenant = $this->makeTenant();
        $this->seedMessage($tenant->id, '8801700000000', 'in', 'ডেলিভারি কত?', createdAtOffsetSeconds: 0);
        $this->seedMessage($tenant->id, '8801700000000', 'out', 'ঢাকার ভিতরে ৬০ টাকা, বাইরে ১২০ টাকা।', 'human', createdAtOffsetSeconds: 5);
        $this->seedMessage($tenant->id, '8801700000000', 'in', 'আরেকটা প্রশ্ন', createdAtOffsetSeconds: 10);
        $this->seedMessage($tenant->id, '8801700000000', 'out', 'অবশ্যই! আপনার প্রশ্নের জন্য ধন্যবাদ।', 'ai', createdAtOffsetSeconds: 15);

        $examples = app(AiConversationStyleService::class)->whatsappStyleExamples($tenant->id);

        $this->assertStringContainsString('ঢাকার ভিতরে ৬০ টাকা', $examples);
        $this->assertStringNotContainsString('আপনার প্রশ্নের জন্য ধন্যবাদ', $examples);
    }

    public function test_style_examples_never_leak_across_tenants(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $this->seedMessage($tenantA->id, '8801700000001', 'in', 'দাম কত?', createdAtOffsetSeconds: 0);
        $this->seedMessage($tenantA->id, '8801700000001', 'out', 'তেন্যান্ট A এর গোপন রিপ্লাই স্টাইল', 'human', createdAtOffsetSeconds: 5);

        $examplesForB = app(AiConversationStyleService::class)->whatsappStyleExamples($tenantB->id);

        $this->assertSame('', $examplesForB);
        $this->assertStringNotContainsString('তেন্যান্ট A', $examplesForB);
    }

    public function test_a_human_reply_with_no_preceding_customer_message_is_skipped(): void
    {
        $tenant = $this->makeTenant();
        $this->seedMessage($tenant->id, '8801700000000', 'out', 'স্বাগতম!', 'human');

        $examples = app(AiConversationStyleService::class)->whatsappStyleExamples($tenant->id);

        $this->assertSame('', $examples);
    }

    public function test_building_the_style_profile_never_does_a_query_per_candidate_reply(): void
    {
        // Phase 17 — WhatsApp counterpart of the same N+1 regression guard
        // in AiConversationStyleServiceTest — see that test's docblock.
        config(['ai.style_examples_max' => 6]);
        $tenant = $this->makeTenant();

        for ($i = 1; $i <= 15; $i++) {
            $this->seedMessage($tenant->id, "880170000{$i}", 'in', "প্রশ্ন {$i}", createdAtOffsetSeconds: $i * 10);
            $this->seedMessage($tenant->id, "880170000{$i}", 'out', "উত্তর {$i}", 'human', createdAtOffsetSeconds: $i * 10 + 5);
        }

        \Illuminate\Support\Facades\DB::enableQueryLog();
        app(AiConversationStyleService::class)->whatsappStyleExamples($tenant->id);
        $queryCount = count(\Illuminate\Support\Facades\DB::getQueryLog());
        \Illuminate\Support\Facades\DB::disableQueryLog();

        $this->assertLessThanOrEqual(5, $queryCount, 'building the style profile must stay a small, constant number of queries regardless of how many distinct conversations are candidates');
    }

    public function test_result_is_cached_and_does_not_reflect_a_new_reply_within_the_cache_window(): void
    {
        $tenant = $this->makeTenant();
        $this->seedMessage($tenant->id, '8801700000001', 'in', 'প্রথম প্রশ্ন', createdAtOffsetSeconds: 0);
        $this->seedMessage($tenant->id, '8801700000001', 'out', 'প্রথম উত্তর', 'human', createdAtOffsetSeconds: 5);

        $service = app(AiConversationStyleService::class);
        $first = $service->whatsappStyleExamples($tenant->id);

        $this->seedMessage($tenant->id, '8801700000002', 'in', 'নতুন প্রশ্ন', createdAtOffsetSeconds: 10);
        $this->seedMessage($tenant->id, '8801700000002', 'out', 'নতুন উত্তর', 'human', createdAtOffsetSeconds: 15);

        $second = $service->whatsappStyleExamples($tenant->id);

        $this->assertSame($first, $second, 'must be served from cache within the configured window, not rebuilt on every call');
    }

    public function test_forgetting_the_cache_makes_a_new_human_reply_visible_immediately(): void
    {
        $tenant = $this->makeTenant();
        $this->seedMessage($tenant->id, '8801700000001', 'in', 'প্রথম প্রশ্ন', createdAtOffsetSeconds: 0);
        $this->seedMessage($tenant->id, '8801700000001', 'out', 'প্রথম উত্তর', 'human', createdAtOffsetSeconds: 5);

        $service = app(AiConversationStyleService::class);
        $first = $service->whatsappStyleExamples($tenant->id);

        $this->seedMessage($tenant->id, '8801700000002', 'in', 'দ্বিতীয় প্রশ্ন', createdAtOffsetSeconds: 10);
        $this->seedMessage($tenant->id, '8801700000002', 'out', 'সংশোধিত উত্তর এখানে', 'human', createdAtOffsetSeconds: 15);

        $service->forgetWhatsAppStyleCache($tenant->id);
        $second = $service->whatsappStyleExamples($tenant->id);

        $this->assertNotSame($first, $second);
        $this->assertStringContainsString('সংশোধিত উত্তর এখানে', $second);
    }

    public function test_forgetting_the_whatsapp_cache_never_affects_the_messenger_cache(): void
    {
        // The two channels must be cached independently — busting one
        // must never bust or affect the other.
        $tenant = $this->makeTenant();
        $this->seedMessage($tenant->id, '8801700000001', 'in', 'প্রশ্ন', createdAtOffsetSeconds: 0);
        $this->seedMessage($tenant->id, '8801700000001', 'out', 'হোয়াটসঅ্যাপ উত্তর', 'human', createdAtOffsetSeconds: 5);

        $service = app(AiConversationStyleService::class);
        $before = $service->whatsappStyleExamples($tenant->id);

        $service->forgetMessengerStyleCache($tenant->id);
        $after = $service->whatsappStyleExamples($tenant->id);

        $this->assertSame($before, $after);
    }

    public function test_profile_line_reports_the_typical_sentence_and_character_length(): void
    {
        $tenant = $this->makeTenant();
        $this->seedMessage($tenant->id, '8801700000000', 'in', 'দাম কত?', createdAtOffsetSeconds: 0);
        $this->seedMessage($tenant->id, '8801700000000', 'out', 'এটা ১২০০ টাকা।', 'human', createdAtOffsetSeconds: 5);

        $examples = app(AiConversationStyleService::class)->whatsappStyleExamples($tenant->id);

        $this->assertStringContainsString('typically reply in about', $examples);
    }

    public function test_a_memory_lookup_failure_degrades_to_no_style_examples_instead_of_throwing(): void
    {
        $tenant = $this->makeTenant();
        $this->seedMessage($tenant->id, '8801700000000', 'in', 'দাম কত?', createdAtOffsetSeconds: 0);
        $this->seedMessage($tenant->id, '8801700000000', 'out', 'এটা ১২০০ টাকা।', 'human', createdAtOffsetSeconds: 5);

        Cache::shouldReceive('remember')->andThrow(new \RuntimeException('simulated cache failure'));

        $examples = app(AiConversationStyleService::class)->whatsappStyleExamples($tenant->id);

        $this->assertSame('', $examples);
    }

    public function test_returns_empty_string_when_the_sent_by_column_does_not_exist_yet(): void
    {
        // Simulates a tenant database that hasn't imported chunk37.sql yet
        // — must degrade gracefully, never throw "Unknown column".
        Schema::table('whatsapp_messages', function ($table) {
            $table->dropColumn('sent_by');
        });

        $tenant = $this->makeTenant();

        $examples = app(AiConversationStyleService::class)->whatsappStyleExamples($tenant->id);

        $this->assertSame('', $examples);
    }
}
