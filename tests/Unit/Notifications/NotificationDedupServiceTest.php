<?php

namespace Tests\Unit\Notifications;

use App\Services\Notifications\NotificationDedupService;
use Tests\TestCase;

class NotificationDedupServiceTest extends TestCase
{
    public function test_first_call_returns_the_single_message_body(): void
    {
        $service = new NotificationDedupService;

        $body = $service->groupedMessageBody(
            'msg:messenger:psid-1',
            'Rahim: নতুন মেসেজ',
            fn (int $count) => "Rahim {$count}টি নতুন মেসেজ পাঠিয়েছেন",
        );

        $this->assertSame('Rahim: নতুন মেসেজ', $body);
    }

    public function test_subsequent_calls_within_the_window_return_the_grouped_body_with_a_running_count(): void
    {
        $service = new NotificationDedupService;
        $tag = 'msg:messenger:psid-2';
        $single = 'Rahim: নতুন মেসেজ';
        $grouped = fn (int $count) => "Rahim {$count}টি নতুন মেসেজ পাঠিয়েছেন";

        $service->groupedMessageBody($tag, $single, $grouped); // 1st
        $second = $service->groupedMessageBody($tag, $single, $grouped);
        $third = $service->groupedMessageBody($tag, $single, $grouped);

        $this->assertSame('Rahim 2টি নতুন মেসেজ পাঠিয়েছেন', $second);
        $this->assertSame('Rahim 3টি নতুন মেসেজ পাঠিয়েছেন', $third);
    }

    public function test_different_tags_are_independent(): void
    {
        $service = new NotificationDedupService;
        $grouped = fn (int $count) => "grouped {$count}";

        $service->groupedMessageBody('tag-a', 'single-a', $grouped);
        $bodyForB = $service->groupedMessageBody('tag-b', 'single-b', $grouped);

        $this->assertSame('single-b', $bodyForB, 'a different tag must not inherit tag-a\'s count');
    }

    public function test_within_cooldown_blocks_a_second_call_for_the_same_key_then_allows_it_again_after_expiry(): void
    {
        $service = new NotificationDedupService;

        $this->assertFalse($service->withinCooldown('order:1:status', 60), 'first call should not be within cooldown');
        $this->assertTrue($service->withinCooldown('order:1:status', 60), 'second call within the window should be blocked');

        // A different key is never affected by another key's cooldown.
        $this->assertFalse($service->withinCooldown('order:2:status', 60));
    }
}
