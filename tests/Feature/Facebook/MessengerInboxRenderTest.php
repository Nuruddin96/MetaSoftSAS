<?php

namespace Tests\Feature\Facebook;

use App\Models\MessengerMessage;
use App\Models\Tenant;
use Illuminate\Support\Carbon;

/**
 * Regression test for a live-production 500 on /panel/messenger:
 * MessengerMessage sets $timestamps = false (this table has no
 * updated_at), and Eloquent's getDates() only auto-adds created_at to
 * the Carbon-cast list when $timestamps is true — so without an
 * explicit cast, created_at came back as a plain string. tenant/
 * messenger/index.blade.php's `$c->created_at?->diffForHumans()` then
 * threw "Call to a member function diffForHumans() on string" (the
 * nullsafe operator only guards null, not a non-object string) — on
 * every real message. Invisible to the prior test suite because no
 * existing test rendered this view against an actual row; this was
 * the first real customer message that ever passed through the live
 * webhook pipeline.
 */
class MessengerInboxRenderTest extends FacebookFeatureTestCase
{
    protected function panelUrl(Tenant $tenant, string $path): string
    {
        return '/shop/'.$tenant->subdomain.'/panel/'.$path;
    }

    public function test_inbox_index_renders_a_real_message_without_error(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        app()->instance('currentTenant', $tenant);

        MessengerMessage::create([
            'sender_psid' => '5611424498916241',
            'mid' => 'm_test_mid_1',
            'customer_name' => 'Test Customer',
            'message_text' => 'Hello test',
            'direction' => 'in',
            'status' => 'new',
        ]);

        $response = $this->actingAs($user, 'tenant')->get($this->panelUrl($tenant, 'messenger'));

        $response->assertOk();
        $this->assertStringContainsString('Test Customer', $response->getContent());
    }

    public function test_created_at_is_cast_to_a_carbon_instance_not_a_string(): void
    {
        $tenant = $this->makeTenant();
        app()->instance('currentTenant', $tenant);

        $message = MessengerMessage::create([
            'sender_psid' => 'psid-1',
            'message_text' => 'hi',
            'direction' => 'in',
            'status' => 'new',
        ]);

        $this->assertInstanceOf(Carbon::class, $message->fresh()->created_at);
    }

    /**
     * Regression test for a live-production 500 on GET /panel/messenger/
     * {psid}: a JS comment inside show.blade.php's <script> block
     * literally contained the text "@once" ("... _thread.blade.php's
     * @once script block ..."). Blade scans the entire raw template text
     * for @directive patterns — including inside what looks like a plain
     * JS comment to a human reader — so that literal text compiled into a
     * real @once directive (`<?php if (! $__env->hasRenderedOnce(...)):
     * ... ?>`) with no matching @endonce anywhere after it. The resulting
     * unclosed if-block ran to end-of-file, producing exactly:
     * "ParseError: syntax error, unexpected end of file, expecting
     * elseif or else or endif" — confirmed byte-for-byte against the
     * actual production log. No prior test rendered show() at all
     * (only index()), so this was invisible until a real customer
     * conversation was opened in production.
     */
    public function test_conversation_show_page_renders_without_a_blade_parse_error(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        app()->instance('currentTenant', $tenant);

        MessengerMessage::create([
            'sender_psid' => 'psid-show-parse-1',
            'mid' => 'mid-show-parse-1',
            'customer_name' => 'Test Customer',
            'message_text' => 'Hello',
            'direction' => 'in',
            'status' => 'new',
        ]);

        $response = $this->actingAs($user, 'tenant')->get($this->panelUrl($tenant, 'messenger/psid-show-parse-1'));

        $response->assertOk();
    }

    /**
     * Regression test for the "অজানা কাস্টমার" (unknown customer) bug:
     * index()/show()/updates() all built their conversation list around
     * "the latest message row per psid," then read customer_name straight
     * off that row. customer_name is only ever set on inbound messages —
     * the moment staff replies (an ordinary, frequent event), the latest
     * row becomes an outbound message with a null customer_name, hiding a
     * name that was already correctly resolved earlier in the same
     * conversation. Each test below creates exactly that shape: an inbound
     * message with a resolved name, followed by an outbound reply with
     * none — and asserts the real name still surfaces, not the fallback.
     */
    public function test_inbox_index_shows_resolved_name_even_when_latest_message_is_outbound(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        app()->instance('currentTenant', $tenant);

        MessengerMessage::create([
            'sender_psid' => 'psid-latest-out-1',
            'mid' => 'mid-in-1',
            'customer_name' => 'Apo',
            'message_text' => 'দাম কত?',
            'direction' => 'in',
            'status' => 'new',
        ]);

        // Staff reply — becomes the newest row for this psid, carries no
        // customer_name, same as MessengerInboxController::reply() writes.
        MessengerMessage::create([
            'sender_psid' => 'psid-latest-out-1',
            'mid' => 'mid-out-1',
            'message_text' => '৫০০ টাকা',
            'direction' => 'out',
            'status' => 'contacted',
        ]);

        $response = $this->actingAs($user, 'tenant')->get($this->panelUrl($tenant, 'messenger'));
        $response->assertOk();

        // Scoped to this specific conversation row (id="conv-{psid}") —
        // the page's embedded polling-fallback JS legitimately contains
        // the literal string "অজানা কাস্টমার" everywhere on this page
        // (c.customer_name || 'অজানা কাস্টমার'), so a whole-page
        // assertDontSee() would always "fail" regardless of the fix.
        $html = $response->getContent();
        $rowStart = strpos($html, 'id="conv-psid-latest-out-1"');
        $this->assertNotFalse($rowStart, 'conversation row not found');
        // Window widened from 600: Phase 1.1 added an <x-ui.avatar> before
        // the name paragraph in this row's markup.
        $row = substr($html, $rowStart, 900);

        $this->assertStringContainsString('Apo', $row);
        $this->assertStringNotContainsString('অজানা কাস্টমার', $row);
    }

    public function test_conversation_thread_shows_resolved_name_even_when_latest_message_is_outbound(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        app()->instance('currentTenant', $tenant);

        MessengerMessage::create([
            'sender_psid' => 'psid-latest-out-2',
            'mid' => 'mid-in-2',
            'customer_name' => 'Karim',
            'message_text' => 'হ্যালো',
            'direction' => 'in',
            'status' => 'new',
        ]);

        MessengerMessage::create([
            'sender_psid' => 'psid-latest-out-2',
            'mid' => 'mid-out-2',
            'message_text' => 'জি বলুন',
            'direction' => 'out',
            'status' => 'contacted',
        ]);

        $response = $this->actingAs($user, 'tenant')->get($this->panelUrl($tenant, 'messenger/psid-latest-out-2'));

        $response->assertOk();
        $response->assertSee('Karim');
        $response->assertDontSee('অজানা কাস্টমার');
    }

    public function test_updates_endpoint_returns_resolved_name_even_when_latest_message_is_outbound(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        app()->instance('currentTenant', $tenant);

        MessengerMessage::create([
            'sender_psid' => 'psid-latest-out-3',
            'mid' => 'mid-in-3',
            'customer_name' => 'Rahim',
            'message_text' => 'আছেন?',
            'direction' => 'in',
            'status' => 'new',
        ]);

        MessengerMessage::create([
            'sender_psid' => 'psid-latest-out-3',
            'mid' => 'mid-out-3',
            'message_text' => 'জি আছি',
            'direction' => 'out',
            'status' => 'contacted',
        ]);

        $response = $this->actingAs($user, 'tenant')->getJson($this->panelUrl($tenant, 'messenger/updates').'?after_id=0');

        $response->assertOk();
        $conversation = collect($response->json('conversations'))->firstWhere('psid', 'psid-latest-out-3');

        $this->assertNotNull($conversation);
        $this->assertSame('Rahim', $conversation['customer_name']);
    }
}
