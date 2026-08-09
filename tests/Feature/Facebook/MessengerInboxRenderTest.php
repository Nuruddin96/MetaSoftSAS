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
}
