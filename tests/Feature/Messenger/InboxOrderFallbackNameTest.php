<?php

namespace Tests\Feature\Messenger;

use App\Models\Customer;
use App\Models\MessengerCustomer;
use App\Models\MessengerMessage;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithCommerceSchema;
use Tests\TestCase;

/**
 * Covers the fourth display-name fallback rung added to
 * MessengerInboxController::applyResolvedIdentities()/resolveOrderNames()
 * and UnifiedInboxService::fetchMessengerCandidates(): when Graph identity
 * AND a genuinely resolved messenger_messages.customer_name are both
 * unavailable, fall back to the business Customer name recorded on this
 * psid's most recent Order that has a genuine, non-placeholder
 * customer_name — see MessengerWebhookController::maybeCreatePendingOrder(),
 * the only place orders.messenger_psid/customer_name are ever written
 * together. Filters on customer_name (NOT customer_id — see
 * resolveOrderNames()'s docblock for why that changed) and excludes the
 * literal DEFAULT_CUSTOMER_NAME placeholder, mirroring the same exclusion
 * already applied to messenger_messages.customer_name.
 *
 * Purely a display-layer change: never touches Messenger conversation
 * grouping (still tenant_id + sender_psid, unchanged), never merges psids,
 * never writes to messenger_customers/orders, never modifies Graph identity
 * resolution or its failure handling. Each test below is numbered to match
 * the corresponding requirement in the approved implementation plan.
 */
class InboxOrderFallbackNameTest extends TestCase
{
    use InteractsWithCommerceSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCommerceSchema();
        config(['messenger.app_secret' => 'test-secret']);
    }

    /** @return array{0: \App\Models\Tenant, 1: \App\Models\User} */
    protected function setUpTenant(string $pageId = 'page-1'): array
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $this->makeMessengerPage($tenant->id, $pageId);

        return [$tenant, $user];
    }

    protected function makeInboundMessage(int $tenantId, string $psid, ?string $customerName, array $attrs = []): MessengerMessage
    {
        return MessengerMessage::withoutGlobalScopes()->create(array_merge([
            'tenant_id' => $tenantId,
            'sender_psid' => $psid,
            'mid' => 'mid-'.$psid.'-'.uniqid(),
            'customer_name' => $customerName,
            'message_text' => 'হ্যালো',
            'direction' => 'in',
            'status' => 'new',
            'created_at' => now(),
        ], $attrs));
    }

    protected function makeOrderWithCustomer(int $tenantId, string $psid, string $customerName, string $phone, array $orderAttrs = []): Order
    {
        $customer = Customer::withoutGlobalScopes()->create([
            'tenant_id' => $tenantId,
            'name' => $customerName,
            'phone' => $phone,
        ]);

        return Order::withoutGlobalScopes()->create(array_merge([
            'tenant_id' => $tenantId,
            'source' => 'messenger',
            'channel' => 'facebook',
            'messenger_psid' => $psid,
            'customer_id' => $customer->id,
            'customer_name' => $customerName,
            'customer_phone' => $phone,
            'status' => 'pending',
        ], $orderAttrs));
    }

    protected function getInbox(\App\Models\Tenant $tenant, \App\Models\User $user): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($user, 'tenant')->get('/shop/'.$tenant->subdomain.'/panel/messenger');
    }

    /** TEST 1 — Graph identity absent + genuine resolved message name absent -> Order customer name is displayed. */
    public function test_1_order_customer_name_displayed_when_graph_and_message_name_absent(): void
    {
        [$tenant, $user] = $this->setUpTenant();

        // Placeholder written by the webhook when nothing else resolved —
        // NOT a genuine resolved name (see TEST 4).
        $this->makeInboundMessage($tenant->id, 'psid-1', 'Messenger Customer');
        $this->makeOrderWithCustomer($tenant->id, 'psid-1', 'Rahim From Order', '01711111111');

        $response = $this->getInbox($tenant, $user);

        $response->assertOk();
        $response->assertSee('Rahim From Order');
    }

    /** TEST 2 — a real Graph identity exists -> Graph identity wins over Order customer. */
    public function test_2_graph_identity_wins_over_order_customer(): void
    {
        [$tenant, $user] = $this->setUpTenant();

        $this->makeInboundMessage($tenant->id, 'psid-2', 'Messenger Customer');
        $this->makeOrderWithCustomer($tenant->id, 'psid-2', 'Order Name Should Lose', '01722222222');

        MessengerCustomer::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'psid' => 'psid-2',
            'first_name' => 'Graph',
            'last_name' => 'Wins',
            'name' => 'Graph Wins',
            'identity_fetched_at' => now(),
        ]);

        $response = $this->getInbox($tenant, $user);

        $response->assertOk();
        $response->assertSee('Graph Wins');
        $response->assertDontSee('Order Name Should Lose');
    }

    /** TEST 3 — a genuine resolved message name exists -> it wins over Order customer. */
    public function test_3_genuine_resolved_message_name_wins_over_order_customer(): void
    {
        [$tenant, $user] = $this->setUpTenant();

        $this->makeInboundMessage($tenant->id, 'psid-3', 'Genuinely Resolved Name');
        $this->makeOrderWithCustomer($tenant->id, 'psid-3', 'Order Name Should Lose', '01733333333');

        $response = $this->getInbox($tenant, $user);

        $response->assertOk();
        $response->assertSee('Genuinely Resolved Name');
        $response->assertDontSee('Order Name Should Lose');
    }

    /** TEST 4 — messenger_messages.customer_name = "Messenger Customer" must be treated as a placeholder, never as identity. */
    public function test_4_placeholder_name_is_never_treated_as_identity(): void
    {
        [$tenant, $user] = $this->setUpTenant();

        // Two messages: the newest row (what $resolvedNames' orderByDesc
        // would naively pick) carries only the placeholder; an OLDER row
        // never carried a real name either — so the only genuine signal
        // available at all is the linked Order.
        $this->makeInboundMessage($tenant->id, 'psid-4', null, ['created_at' => now()->subMinutes(5)]);
        $this->makeInboundMessage($tenant->id, 'psid-4', 'Messenger Customer', ['created_at' => now()]);
        $this->makeOrderWithCustomer($tenant->id, 'psid-4', 'Real Order Customer', '01744444444');

        $response = $this->getInbox($tenant, $user);

        $response->assertOk();
        $response->assertSee('Real Order Customer');

        // Direct proof at the unit level: the literal placeholder must
        // never survive excludePlaceholder(), independent of the HTTP round trip.
        $controller = new \App\Http\Controllers\Tenant\MessengerInboxController();
        $method = new \ReflectionMethod($controller, 'excludePlaceholder');
        $method->setAccessible(true);
        $this->assertNull($method->invoke($controller, 'Messenger Customer'));
        $this->assertSame('Real Name', $method->invoke($controller, 'Real Name'));
    }

    /** TEST 5 — cross-tenant collision: Tenant B must NEVER receive Tenant A's Order-linked customer name, even for the identical PSID string. */
    public function test_5_cross_tenant_collision_never_leaks_customer_name(): void
    {
        [$tenantA] = $this->setUpTenant('page-a');
        [$tenantB, $userB] = $this->setUpTenant('page-b');

        // Tenant A: this exact psid has a resolvable Order->Customer link.
        $this->makeInboundMessage($tenantA->id, 'shared-psid', 'Messenger Customer');
        $this->makeOrderWithCustomer($tenantA->id, 'shared-psid', 'Tenant A Secret Customer', '01755555555');

        // Tenant B: SAME literal psid string (Facebook PSIDs are Page-scoped,
        // not global — collisions across tenants/pages are expected), but
        // Tenant B has no Order for it at all.
        $this->makeInboundMessage($tenantB->id, 'shared-psid', 'Messenger Customer');

        $response = $this->getInbox($tenantB, $userB);

        $response->assertOk();
        $response->assertDontSee('Tenant A Secret Customer');
        $response->assertSee('Messenger Customer'); // Tenant B correctly falls through to the placeholder
    }

    /** TEST 6 — multiple orders for one PSID with the same customer_id -> deterministic, stable name (no ambiguity/duplication). */
    public function test_6_multiple_orders_same_customer_id_gives_stable_name(): void
    {
        [$tenant, $user] = $this->setUpTenant();

        $this->makeInboundMessage($tenant->id, 'psid-6', 'Messenger Customer');

        $customer = Customer::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'name' => 'Stable Customer', 'phone' => '01766666666',
        ]);

        foreach ([1, 2, 3] as $i) {
            Order::withoutGlobalScopes()->create([
                'tenant_id' => $tenant->id, 'source' => 'messenger', 'channel' => 'facebook',
                'messenger_psid' => 'psid-6', 'customer_id' => $customer->id,
                'customer_name' => 'Stable Customer', 'customer_phone' => '01766666666', 'status' => 'pending',
            ]);
        }

        $response = $this->getInbox($tenant, $user);

        $response->assertOk();
        $html = $response->getContent();
        $this->assertSame(1, substr_count($html, 'Stable Customer'), 'exactly one conversation row must show the name, never a duplicated/ambiguous render');
    }

    /**
     * TEST 7 — an order whose customer_id is NULL (e.g. the linked Customer
     * row was later deleted, ON DELETE SET NULL) but whose customer_name
     * snapshot is still a genuine, non-placeholder value must still be
     * usable — customer_id is no longer the filter column (see
     * resolveOrderNames()'s docblock: customer_name is a NOT NULL snapshot
     * that survives independently of the FK).
     */
    public function test_7_order_with_null_customer_id_but_valid_customer_name_is_usable(): void
    {
        [$tenant, $user] = $this->setUpTenant();

        $this->makeInboundMessage($tenant->id, 'psid-7', 'Messenger Customer');

        // customer_id is NULL (linked Customer row gone), but customer_name
        // is still a real, non-placeholder snapshot — must be displayed.
        Order::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'source' => 'messenger', 'channel' => 'facebook',
            'messenger_psid' => 'psid-7', 'customer_id' => null,
            'customer_name' => 'Valid Name Despite Deleted Customer', 'customer_phone' => '01777777777',
            'status' => 'pending', 'created_at' => now(),
        ]);

        $response = $this->getInbox($tenant, $user);

        $response->assertOk();
        $response->assertSee('Valid Name Despite Deleted Customer');
    }

    /**
     * TEST 12 — an order whose OWN customer_name equals the literal
     * DEFAULT_CUSTOMER_NAME placeholder (maybeCreatePendingOrder() writes
     * this when a phone number arrived with no resolvable name at all) must
     * be ignored as a fallback source, exactly like the equivalent
     * messenger_messages.customer_name case (TEST 4) — never treated as a
     * genuine identity signal.
     */
    public function test_12_order_with_placeholder_customer_name_is_ignored(): void
    {
        [$tenant, $user] = $this->setUpTenant();

        $this->makeInboundMessage($tenant->id, 'psid-12', 'Messenger Customer');
        $this->makeOrderWithCustomer($tenant->id, 'psid-12', 'Messenger Customer', '01712120000');

        $response = $this->getInbox($tenant, $user);

        $response->assertOk();
        // Falls through to the existing final placeholder — no valid order
        // name, no genuine resolved message name, no Graph identity.
        $response->assertSee('Messenger Customer');

        // Unit-level proof this is not a coincidental match: the order's
        // own name must never have been selected by resolveOrderNames() in
        // the first place.
        $controller = new \App\Http\Controllers\Tenant\MessengerInboxController();
        $method = new \ReflectionMethod($controller, 'resolveOrderNames');
        $method->setAccessible(true);

        $this->actingAs($user, 'tenant');
        app()->instance('currentTenant', $tenant);
        $orderNames = $method->invoke($controller, collect(['psid-12']));

        $this->assertNull($orderNames->get('psid-12'), 'a placeholder-named order must never be returned as a resolved order name');
    }

    /** TEST 8 — no orders at all for the PSID -> existing "Messenger Customer" placeholder still shows (unchanged behavior). */
    public function test_8_no_orders_falls_back_to_existing_placeholder(): void
    {
        [$tenant, $user] = $this->setUpTenant();

        $this->makeInboundMessage($tenant->id, 'psid-8', 'Messenger Customer');

        $response = $this->getInbox($tenant, $user);

        $response->assertOk();
        $response->assertSee('Messenger Customer');
    }

    /** TEST 9 — a cancelled/returned order remains a valid identity signal; no status filter unless business logic already required one (it doesn't — show()'s existing $linkedOrder lookup has none either). */
    public function test_9_cancelled_order_remains_a_valid_identity_signal(): void
    {
        [$tenant, $user] = $this->setUpTenant();

        $this->makeInboundMessage($tenant->id, 'psid-9', 'Messenger Customer');
        $this->makeOrderWithCustomer($tenant->id, 'psid-9', 'Cancelled Order Customer', '01799999999', [
            'status' => 'cancelled',
        ]);

        $response = $this->getInbox($tenant, $user);

        $response->assertOk();
        $response->assertSee('Cancelled Order Customer');
    }

    /** TEST 10 — conflicting customer_name values across historical orders for one PSID -> safest deterministic behavior: the single latest resolvable order wins, never a merge/guess. */
    public function test_10_conflicting_customer_ids_deterministically_uses_the_latest(): void
    {
        [$tenant, $user] = $this->setUpTenant();

        $this->makeInboundMessage($tenant->id, 'psid-10', 'Messenger Customer');

        $this->makeOrderWithCustomer($tenant->id, 'psid-10', 'Old Conflicting Customer', '01700000001', [
            'created_at' => now()->subDay(),
        ]);
        $this->makeOrderWithCustomer($tenant->id, 'psid-10', 'New Conflicting Customer', '01700000002', [
            'created_at' => now(),
        ]);

        $response = $this->getInbox($tenant, $user);

        $response->assertOk();
        $response->assertSee('New Conflicting Customer');
        $response->assertDontSee('Old Conflicting Customer');
    }

    /**
     * TEST 11 — query-count regression: the Order->Customer lookup is
     * batched, so the total query count for a page of conversations must
     * NOT scale with the number of conversations (would indicate a
     * regression back into an N+1 per-conversation query).
     */
    public function test_11_order_name_lookup_does_not_scale_with_conversation_count(): void
    {
        // Both tenants' full data set is built BEFORE either HTTP call —
        // getInbox() runs through resolve.tenant middleware, which binds
        // app()->instance('currentTenant', ...) for the rest of the test
        // process; interleaving setup with getInbox() calls would leak that
        // binding into the next tenant's makeUser()/User::find() (both
        // BelongsToTenant-scoped) and corrupt this test, unrelated to the
        // production code under test.
        [$tenant, $user] = $this->setUpTenant();

        for ($i = 1; $i <= 2; $i++) {
            $this->makeInboundMessage($tenant->id, "psid-few-{$i}", 'Messenger Customer');
            $this->makeOrderWithCustomer($tenant->id, "psid-few-{$i}", "Few Customer {$i}", "0170000000{$i}");
        }

        [$tenant2, $user2] = $this->setUpTenant('page-many');
        for ($i = 1; $i <= 8; $i++) {
            $this->makeInboundMessage($tenant2->id, "psid-many-{$i}", 'Messenger Customer');
            $this->makeOrderWithCustomer($tenant2->id, "psid-many-{$i}", "Many Customer {$i}", "0171000000{$i}");
        }

        DB::enableQueryLog();
        $this->getInbox($tenant, $user)->assertOk();
        $fewCount = count(DB::getQueryLog());
        DB::flushQueryLog();

        $this->getInbox($tenant2, $user2)->assertOk();
        $manyCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(
            $fewCount,
            $manyCount,
            "query count must be identical for 2 vs 8 conversations (both under the 20/page limit) — got {$fewCount} vs {$manyCount}, a difference would indicate a per-conversation query"
        );
    }
}
