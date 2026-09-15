<?php

namespace Tests\Feature\Facebook;

use App\Models\Customer;
use App\Models\FacebookConnection;
use App\Models\FacebookPage;
use App\Models\MessengerMessage;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithCommerceSchema;
use Tests\TestCase;

/**
 * Covers the Customers → All Customers "bulk Messenger message" feature
 * (BulkMessengerSendService, shared by Tenant\CustomerController::
 * bulkMessenger() and Api\Mobile\CustomerController::bulkMessenger()).
 *
 * Reuses the exact same Send API MessengerController/MessengerInboxController
 * already call (MessengerApi::sendMessage()/sendAttachment()) — these tests
 * fake that HTTP call the same way MessengerReplyRoutingTest does, never a
 * real network call. The central behavior under test: a customer only
 * receives a message when a real PSID can be resolved for them (via
 * orders.messenger_psid), and a failed/unresolvable recipient is always
 * reported honestly, never silently dropped or reported as sent.
 *
 * Uses InteractsWithCommerceSchema (full orders/customers/messenger_messages
 * tables) rather than FacebookFeatureTestCase's InteractsWithFacebookSchema
 * (whose orders/customer tables are Facebook-webhook-test-only stubs missing
 * customer_id/messenger_psid/customers entirely) — facebook_connections/
 * facebook_pages are added inline here instead, same "add extra
 * Schema::create tables inline as needed" convention this test suite
 * already uses elsewhere.
 */
class BulkMessengerSendTest extends TestCase
{
    use InteractsWithCommerceSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCommerceSchema();
        $this->withoutMiddleware(ValidateCsrfToken::class);

        // FacebookPage::tablesReady() (used by resolveReplyToken()) also
        // checks this table's existence, even though nothing here reads
        // from it directly.
        if (! Schema::hasTable('facebook_oauth_states')) {
            Schema::create('facebook_oauth_states', function (Blueprint $table) {
                $table->id();
                $table->string('state', 64)->unique();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('user_id');
                $table->timestamp('expires_at');
                $table->timestamp('used_at')->nullable();
                $table->timestamp('created_at')->nullable();
            });
        }

        if (! Schema::hasTable('facebook_connections')) {
            Schema::create('facebook_connections', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->unique();
                $table->unsignedBigInteger('connected_by_user_id');
                $table->string('facebook_user_id', 64);
                $table->text('user_access_token');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('facebook_pages')) {
            Schema::create('facebook_pages', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('facebook_connection_id');
                $table->string('page_id', 50)->unique();
                $table->string('page_name', 150)->nullable();
                $table->text('page_access_token')->nullable();
                $table->string('status', 30)->default('active');
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }
    }

    /** @return array{0: Tenant, 1: FacebookPage, 2: User} */
    protected function setUpTenantWithPage(string $pageId): array
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $conn = FacebookConnection::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'connected_by_user_id' => $user->id,
            'facebook_user_id' => 'fbu-'.$pageId, 'user_access_token' => 'tok',
        ]);
        $page = FacebookPage::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'facebook_connection_id' => $conn->id,
            'page_id' => $pageId,
            'page_name' => $pageId.' name',
            'page_access_token' => 'token-for-'.$pageId,
            'status' => 'active',
            'is_active' => true,
        ]);

        return [$tenant, $page, $user];
    }

    /**
     * A real "reachable via bulk Messenger send" customer needs BOTH an
     * order.messenger_psid (how BulkMessengerSendService finds their psid
     * at all) AND a messenger_messages row carrying that same psid's
     * facebook_page_id (how resolveReplyToken() finds which Page/token to
     * send from — same resolution path MessengerController::reply() uses
     * for a single conversation). In production both always exist together
     * for a Messenger-originated customer (the inbound webhook creates the
     * messenger_messages row before/alongside the pending order), so this
     * mirrors real data shape, not a test-only shortcut.
     */
    private function messengerCustomer(Tenant $tenant, FacebookPage $page, string $psid, string $phone): Customer
    {
        app()->instance('currentTenant', $tenant);
        $customer = Customer::create(['tenant_id' => $tenant->id, 'name' => 'Karim', 'phone' => $phone]);
        Order::create([
            'tenant_id' => $tenant->id, 'source' => 'messenger', 'channel' => 'facebook',
            'customer_id' => $customer->id, 'customer_name' => 'Karim', 'customer_phone' => $phone,
            'messenger_psid' => $psid, 'subtotal' => 0, 'discount' => 0, 'delivery_charge' => 0, 'total' => 0,
            'payment_method' => 'cod', 'status' => 'confirmed',
        ]);
        MessengerMessage::create([
            'tenant_id' => $tenant->id, 'facebook_page_id' => $page->id, 'sender_psid' => $psid,
            'customer_name' => 'Karim', 'message_text' => 'Hi', 'direction' => 'in', 'status' => 'contacted',
        ]);
        app()->forgetInstance('currentTenant');

        return $customer;
    }

    public function test_mobile_bulk_send_reaches_a_customer_with_a_real_psid(): void
    {
        [$tenant, $page, $user] = $this->setUpTenantWithPage('page-1');
        $customer = $this->messengerCustomer($tenant, $page, 'psid-1', '01711111111');
        Http::fake(['*/me/messages*' => Http::response(['message_id' => 'mid-1'])]);

        Sanctum::actingAs($user);
        $response = $this->postJson('/api/mobile/v1/customers/bulk-messenger', [
            'customer_ids' => [$customer->id],
            'message' => 'হ্যালো!',
        ]);

        $response->assertOk();
        $this->assertSame(1, $response->json('sent'));
        $this->assertSame(0, $response->json('failed'));
        $this->assertSame('sent', $response->json('data.0.status'));

        Http::assertSent(fn ($request) => str_contains($request->url(), '/me/messages')
            && $request['recipient']['id'] === 'psid-1'
            && $request['message']['text'] === 'হ্যালো!');
    }

    public function test_mobile_bulk_send_reports_a_customer_with_no_messenger_connection_as_failed_not_skipped(): void
    {
        [$tenant, , $user] = $this->setUpTenantWithPage('page-1');
        app()->instance('currentTenant', $tenant);
        $customer = Customer::create(['tenant_id' => $tenant->id, 'name' => 'No Messenger', 'phone' => '01722222222']);
        app()->forgetInstance('currentTenant');

        Sanctum::actingAs($user);
        $response = $this->postJson('/api/mobile/v1/customers/bulk-messenger', [
            'customer_ids' => [$customer->id],
            'message' => 'হ্যালো!',
        ]);

        $response->assertOk();
        $this->assertSame(0, $response->json('sent'));
        $this->assertSame(1, $response->json('failed'));
        $this->assertSame('failed', $response->json('data.0.status'));
        $this->assertNotNull($response->json('data.0.reason'));
    }

    /** Never fabricate success — a Graph API error payload must be reported as failed even though the HTTP call itself succeeded. */
    public function test_mobile_bulk_send_reports_a_meta_api_error_as_failed_never_as_sent(): void
    {
        [$tenant, $page, $user] = $this->setUpTenantWithPage('page-1');
        $customer = $this->messengerCustomer($tenant, $page, 'psid-2', '01733333333');
        Http::fake(['*/me/messages*' => Http::response([
            'error' => ['message' => 'This message is sent outside of allowed window.'],
        ])]);

        Sanctum::actingAs($user);
        $response = $this->postJson('/api/mobile/v1/customers/bulk-messenger', [
            'customer_ids' => [$customer->id],
            'message' => 'হ্যালো!',
        ]);

        $response->assertOk();
        $this->assertSame(0, $response->json('sent'));
        $this->assertSame(1, $response->json('failed'));
        $this->assertStringContainsString('outside of allowed window', $response->json('data.0.reason'));
    }

    public function test_mobile_bulk_send_handles_a_mix_of_reachable_and_unreachable_customers_in_one_call(): void
    {
        [$tenant, $page, $user] = $this->setUpTenantWithPage('page-1');
        $reachable = $this->messengerCustomer($tenant, $page, 'psid-3', '01744444444');
        app()->instance('currentTenant', $tenant);
        $unreachable = Customer::create(['tenant_id' => $tenant->id, 'name' => 'No Messenger', 'phone' => '01755555555']);
        app()->forgetInstance('currentTenant');
        Http::fake(['*/me/messages*' => Http::response(['message_id' => 'mid-3'])]);

        Sanctum::actingAs($user);
        $response = $this->postJson('/api/mobile/v1/customers/bulk-messenger', [
            'customer_ids' => [$reachable->id, $unreachable->id],
            'message' => 'হ্যালো সবাইকে!',
        ]);

        $response->assertOk();
        $this->assertSame(1, $response->json('sent'));
        $this->assertSame(1, $response->json('failed'));
    }

    public function test_bulk_send_requires_either_a_message_or_an_image(): void
    {
        [$tenant, $page, $user] = $this->setUpTenantWithPage('page-1');
        $customer = $this->messengerCustomer($tenant, $page, 'psid-4', '01766666666');

        Sanctum::actingAs($user);
        $response = $this->postJson('/api/mobile/v1/customers/bulk-messenger', [
            'customer_ids' => [$customer->id],
        ]);

        $response->assertStatus(422);
    }

    /** Tenant isolation: bulk send must never resolve or message another tenant's customer, even if its id happens to be passed. */
    public function test_bulk_send_never_reaches_another_tenants_customer(): void
    {
        [$tenantA, $pageA, ] = $this->setUpTenantWithPage('page-a');
        [$tenantB, , $userB] = $this->setUpTenantWithPage('page-b');
        $customerA = $this->messengerCustomer($tenantA, $pageA, 'psid-a', '01777777777');
        Http::fake(['*/me/messages*' => Http::response(['message_id' => 'mid-a'])]);

        Sanctum::actingAs($userB);
        $response = $this->postJson('/api/mobile/v1/customers/bulk-messenger', [
            'customer_ids' => [$customerA->id],
            'message' => 'হ্যালো!',
        ]);

        $response->assertOk();
        $this->assertSame(0, $response->json('sent'));
        $this->assertSame(1, $response->json('failed'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/me/messages'));
    }

    /** Web endpoint (customers/bulk-messenger) exercises the same shared service. */
    public function test_web_bulk_send_reaches_a_customer_with_a_real_psid(): void
    {
        [$tenant, $page, $user] = $this->setUpTenantWithPage('page-1');
        $customer = $this->messengerCustomer($tenant, $page, 'psid-web-1', '01788888888');
        Http::fake(['*/me/messages*' => Http::response(['message_id' => 'mid-web-1'])]);

        $response = $this->actingAs($user, 'tenant')->post('/shop/'.$tenant->subdomain.'/panel/customers/bulk-messenger', [
            'customer_ids' => [$customer->id],
            'message' => 'হ্যালো!',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');
        Http::assertSent(fn ($request) => str_contains($request->url(), '/me/messages'));
    }
}
