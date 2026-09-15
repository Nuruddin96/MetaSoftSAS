<?php

namespace Tests\Feature\AiAgent;

use App\Models\Order;
use App\Models\OrderItem;
use App\Services\AI\AiPostPurchaseContextService;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\InteractsWithAiAgentSchema;
use Tests\TestCase;

/**
 * Covers App\Services\AI\AiPostPurchaseContextService — the generic,
 * category-agnostic "is this a complaint about something previously
 * bought, and can we verify what was actually bought" layer (Parts 7-9
 * of the Customer Sales + Care Agent upgrade). Deliberately no skincare/
 * cosmetics-specific fixtures anywhere in this file — clothing and
 * electronics phrases are used interchangeably with everything else to
 * prove the architecture itself is category-agnostic, not just its
 * comments.
 */
class AiPostPurchaseContextServiceTest extends TestCase
{
    use InteractsWithAiAgentSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpAiAgentSchema();
    }

    protected function makeOrderWithItem(int $tenantId, string $productName, array $orderAttrs = []): Order
    {
        $order = Order::withoutGlobalScopes()->create(array_merge([
            'tenant_id' => $tenantId,
            'order_number' => 'ORD-000001',
            'customer_name' => 'Test Customer',
            'customer_phone' => '01700000000',
            'status' => 'delivered',
        ], $orderAttrs));

        OrderItem::withoutGlobalScopes()->create([
            'tenant_id' => $tenantId,
            'order_id' => $order->id,
            'product_name' => $productName,
        ]);

        return $order;
    }

    // --- isPostPurchaseConcern() -----------------------------------------------------------

    public function test_detects_a_generic_bengali_complaint_phrase(): void
    {
        $service = app(AiPostPurchaseContextService::class);

        $this->assertTrue($service->isPostPurchaseConcern('আপু এটা ব্যবহার করার পর সমস্যা হচ্ছে'));
    }

    public function test_detects_a_clothing_specific_complaint_with_no_skincare_wording_anywhere(): void
    {
        $service = app(AiPostPurchaseContextService::class);

        $this->assertTrue($service->isPostPurchaseConcern('আপু কাপড়টা ধোয়ার পর রং উঠে গেছে'));
    }

    public function test_detects_an_electronics_complaint_in_english(): void
    {
        $service = app(AiPostPurchaseContextService::class);

        $this->assertTrue($service->isPostPurchaseConcern('the phone stopped working after one week'));
    }

    public function test_an_ordinary_product_question_is_not_a_concern(): void
    {
        $service = app(AiPostPurchaseContextService::class);

        $this->assertFalse($service->isPostPurchaseConcern('দাম কত?'));
        $this->assertFalse($service->isPostPurchaseConcern('স্টকে আছে?'));
    }

    public function test_empty_or_null_message_is_not_a_concern(): void
    {
        $service = app(AiPostPurchaseContextService::class);

        $this->assertFalse($service->isPostPurchaseConcern(null));
        $this->assertFalse($service->isPostPurchaseConcern(''));
        $this->assertFalse($service->isPostPurchaseConcern('   '));
    }

    // --- forMessengerCustomer() / verifiedPurchase() ---------------------------------------

    public function test_messenger_verifies_a_real_purchase_of_the_product_mentioned(): void
    {
        $tenant = $this->makeTenant();
        $this->makeOrderWithItem($tenant->id, 'Winter Jacket', ['messenger_psid' => 'psid-1', 'order_number' => 'ORD-000042']);

        $context = app(AiPostPurchaseContextService::class)->forMessengerCustomer(
            $tenant->id,
            'psid-1',
            ['আপু Winter Jacket টা ধোয়ার পর রং উঠে গেছে']
        );

        $this->assertNotNull($context);
        $this->assertStringContainsString('Verified', $context);
        $this->assertStringContainsString('ORD-000042', $context);
        $this->assertStringContainsString('Winter Jacket', $context);
    }

    public function test_messenger_returns_null_when_the_product_was_never_actually_purchased(): void
    {
        $tenant = $this->makeTenant();
        $this->makeOrderWithItem($tenant->id, 'Table Lamp', ['messenger_psid' => 'psid-2']);

        // Complains about a completely different product than what this
        // customer's real order actually contains — must never be
        // treated as verified.
        $context = app(AiPostPurchaseContextService::class)->forMessengerCustomer(
            $tenant->id,
            'psid-2',
            ['এই ফোনটা চার্জ নিচ্ছে না']
        );

        $this->assertNull($context);
    }

    public function test_messenger_returns_null_when_this_psid_has_no_orders_at_all(): void
    {
        $tenant = $this->makeTenant();

        $context = app(AiPostPurchaseContextService::class)->forMessengerCustomer(
            $tenant->id,
            'psid-no-orders',
            ['আপু এটা ব্যবহার করার পর সমস্যা হচ্ছে']
        );

        $this->assertNull($context);
    }

    public function test_messenger_never_verifies_against_another_tenants_order(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $this->makeOrderWithItem($tenantA->id, 'Shared Product Name', ['messenger_psid' => 'shared-psid']);

        // Same psid string, but tenant B — the real order belongs to
        // tenant A and must never leak across.
        $context = app(AiPostPurchaseContextService::class)->forMessengerCustomer(
            $tenantB->id,
            'shared-psid',
            ['Shared Product Name এ সমস্যা হচ্ছে']
        );

        $this->assertNull($context);
    }

    public function test_messenger_finds_a_product_named_earlier_in_the_conversation_not_just_the_current_message(): void
    {
        $tenant = $this->makeTenant();
        $this->makeOrderWithItem($tenant->id, 'Bluetooth Speaker', ['messenger_psid' => 'psid-3']);

        // The product name was given as an earlier turn; the current
        // message only carries the complaint itself.
        $context = app(AiPostPurchaseContextService::class)->forMessengerCustomer(
            $tenant->id,
            'psid-3',
            ['Bluetooth Speaker কিনেছিলাম', 'এটা এখন কাজ করছে না']
        );

        $this->assertNotNull($context);
        $this->assertStringContainsString('Bluetooth Speaker', $context);
    }

    public function test_messenger_lookup_failure_degrades_to_null_instead_of_throwing(): void
    {
        $tenant = $this->makeTenant();
        $this->makeOrderWithItem($tenant->id, 'Any Product', ['messenger_psid' => 'psid-4']);

        Schema::dropIfExists('orders');

        $context = app(AiPostPurchaseContextService::class)->forMessengerCustomer(
            $tenant->id,
            'psid-4',
            ['Any Product এ সমস্যা হচ্ছে']
        );

        $this->assertNull($context);
    }

    // --- forWhatsAppCustomer() ---------------------------------------------------------------

    public function test_whatsapp_verifies_against_the_stored_local_phone_format(): void
    {
        $tenant = $this->makeTenant();
        $this->makeOrderWithItem($tenant->id, 'Ceramic Vase', ['customer_phone' => '01700000000', 'order_number' => 'ORD-000099']);

        // wa_id arrives from Meta in full international format.
        $context = app(AiPostPurchaseContextService::class)->forWhatsAppCustomer(
            $tenant->id,
            '8801700000000',
            ['Ceramic Vase টা ভেঙে গেছে']
        );

        $this->assertNotNull($context);
        $this->assertStringContainsString('ORD-000099', $context);
    }

    public function test_whatsapp_never_leaks_another_tenants_order(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $this->makeOrderWithItem($tenantA->id, 'Leather Wallet', ['customer_phone' => '01700000000']);

        $context = app(AiPostPurchaseContextService::class)->forWhatsAppCustomer(
            $tenantB->id,
            '8801700000000',
            ['Leather Wallet এ সমস্যা হচ্ছে']
        );

        $this->assertNull($context);
    }
}
