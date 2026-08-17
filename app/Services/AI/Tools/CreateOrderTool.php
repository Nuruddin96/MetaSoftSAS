<?php

namespace App\Services\AI\Tools;

use App\Models\Customer;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates a new confirmed order. HIGH RISK — see AiMutatingTool: the
 * AI-mediated path only ever reaches preview() (via AiToolRegistry::propose()),
 * never handle() directly. handle() only runs after a real user
 * confirms, via Tenant\AiChatController::confirm(), and only with the
 * exact resolved_args preview() produced (never anything supplied by the
 * confirm request itself).
 *
 * Mirrors Tenant\OrderController::store()'s core logic (customer
 * firstOrCreate, item/stock/StockMovement handling, order totals) rather
 * than reinventing it — the differences are deliberate: delivery charge
 * is not collected (an AI-described order has no division/address
 * selection UI), and every tenant-owned query is explicit
 * withoutGlobalScopes()->where('tenant_id', ...) since, unlike the
 * controller, this may run outside an ambient-tenant-bound context.
 */
class CreateOrderTool implements AiMutatingTool
{
    protected const ALLOWED_PAYMENT_METHODS = ['cod', 'cash', 'bkash', 'nagad', 'bank'];

    public function name(): string
    {
        return 'create_order';
    }

    public function description(): string
    {
        return 'Creates a new order for a customer given their phone number and a list of products/quantities. HIGH RISK — this only proposes the order for the store owner to explicitly confirm; it is never executed immediately. Always use lookup_products first to find real product/variant names and check stock rather than guessing them.';
    }

    public function parametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'customer_phone' => ['type' => 'string', 'description' => 'Customer phone number, e.g. 01712345678'],
                'customer_name' => ['type' => 'string', 'description' => 'Customer name — used only if this phone number is a new customer'],
                'customer_address' => ['type' => 'string'],
                'items' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'product_name' => ['type' => 'string'],
                            'variant_name' => ['type' => 'string', 'description' => 'Omit for a single-variant product'],
                            'quantity' => ['type' => 'integer'],
                        ],
                        'required' => ['product_name', 'quantity'],
                    ],
                ],
                'payment_method' => ['type' => 'string', 'enum' => self::ALLOWED_PAYMENT_METHODS],
                'note' => ['type' => 'string'],
            ],
            'required' => ['customer_phone', 'items'],
        ];
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function preview(int $tenantId, array $args): array
    {
        $phone = trim((string) ($args['customer_phone'] ?? ''));

        if (! preg_match('/^01[3-9][0-9]{8}$/', $phone)) {
            return ['error' => 'সঠিক মোবাইল নাম্বার প্রয়োজন (01XXXXXXXXX)।'];
        }

        $items = is_array($args['items'] ?? null) ? $args['items'] : [];

        if (! $items) {
            return ['error' => 'অন্তত একটি প্রোডাক্ট আইটেম দিতে হবে।'];
        }

        $resolvedItems = [];
        $lines = [];
        $subtotal = 0.0;

        foreach ($items as $item) {
            $productName = trim((string) ($item['product_name'] ?? ''));
            $quantity = max(1, (int) ($item['quantity'] ?? 1));

            if ($productName === '') {
                return ['error' => 'প্রতিটি আইটেমে প্রোডাক্টের নাম দিতে হবে।'];
            }

            $product = Product::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('is_active', 1)
                ->where('name', 'like', '%'.$productName.'%')
                ->with('variants')
                ->first();

            if (! $product) {
                return ['error' => "\"{$productName}\" নামে কোনো সক্রিয় প্রোডাক্ট পাওয়া যায়নি।"];
            }

            $variant = $this->resolveVariant($product, $item['variant_name'] ?? null);

            if (! $variant) {
                return ['error' => "\"{$product->name}\"-এর একাধিক ভ্যারিয়েন্ট আছে — কোনটা তা নির্দিষ্ট করে আবার বলুন।"];
            }

            $stock = $variant->totalStock();

            if ($stock < $quantity) {
                return ['error' => "\"{$product->name} ({$variant->variant_name})\"-এ পর্যাপ্ত স্টক নেই — আছে মাত্র {$stock}টি, চাওয়া হয়েছে {$quantity}টি।"];
            }

            $lineTotal = (float) $variant->selling_price * $quantity;
            $subtotal += $lineTotal;

            $resolvedItems[] = [
                'variant_id' => $variant->id,
                'product_name' => $product->name,
                'variant_name' => $variant->variant_name,
                'sku' => $variant->sku,
                'unit_price' => (float) $variant->selling_price,
                'purchase_price' => (float) $variant->purchase_price,
                'quantity' => $quantity,
                'line_total' => $lineTotal,
            ];

            $lines[] = "- {$product->name} ({$variant->variant_name}) x{$quantity} @ ৳".number_format((float) $variant->selling_price, 2).' = ৳'.number_format($lineTotal, 2);
        }

        $paymentMethod = in_array($args['payment_method'] ?? null, self::ALLOWED_PAYMENT_METHODS, true)
            ? $args['payment_method']
            : 'cod';

        $existingCustomer = Customer::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('phone', $phone)->first();
        $customerName = $existingCustomer->name ?? (trim((string) ($args['customer_name'] ?? '')) ?: 'অজানা কাস্টমার');

        $resolvedArgs = [
            'customer_phone' => $phone,
            'customer_name' => $customerName,
            'customer_address' => $args['customer_address'] ?? $existingCustomer?->address,
            'items' => $resolvedItems,
            'subtotal' => $subtotal,
            'total' => $subtotal,
            'payment_method' => $paymentMethod,
            'note' => $args['note'] ?? null,
        ];

        $summary = "নতুন অর্ডার তৈরি হবে:\n"
            ."কাস্টমার: {$customerName} ({$phone})\n"
            .implode("\n", $lines)."\n"
            .'সাবটোটাল: ৳'.number_format($subtotal, 2)."\n"
            .'মোট: ৳'.number_format($subtotal, 2)." (ডেলিভারি চার্জ অন্তর্ভুক্ত নয় — প্রয়োজনে অর্ডার পেজ থেকে যোগ করুন)\n"
            ."পেমেন্ট: {$paymentMethod}";

        return ['summary' => $summary, 'resolved_args' => $resolvedArgs];
    }

    public function handle(int $tenantId, array $args): array
    {
        $items = $args['items'] ?? [];

        if (! $items) {
            return ['success' => false, 'message' => 'কোনো আইটেম পাওয়া যায়নি — অর্ডার তৈরি করা যায়নি।'];
        }

        // Re-verify stock at execution time — it may have changed since
        // preview() ran (e.g. sold via another channel while this
        // confirmation was pending).
        $variants = ProductVariant::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('id', array_column($items, 'variant_id'))
            ->get()->keyBy('id');

        foreach ($items as $item) {
            $variant = $variants->get($item['variant_id']);

            if (! $variant || $variant->totalStock() < $item['quantity']) {
                return ['success' => false, 'message' => "\"{$item['product_name']}\"-এর স্টক এখন আর পর্যাপ্ত নেই — অর্ডার তৈরি করা যায়নি।"];
            }
        }

        $order = DB::transaction(function () use ($tenantId, $args, $items) {
            $customer = Customer::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('phone', $args['customer_phone'])->first()
                ?? Customer::withoutGlobalScopes()->create([
                    'tenant_id' => $tenantId,
                    'name' => $args['customer_name'],
                    'phone' => $args['customer_phone'],
                    'address' => $args['customer_address'] ?? null,
                ]);

            $order = Order::withoutGlobalScopes()->create([
                'tenant_id' => $tenantId,
                'source' => 'manual',
                'channel' => 'others',
                'customer_id' => $customer->id,
                'customer_name' => $args['customer_name'],
                'customer_phone' => $args['customer_phone'],
                'customer_address' => $args['customer_address'] ?? null,
                'subtotal' => $args['subtotal'],
                'discount' => 0,
                'delivery_charge' => 0,
                'total' => $args['total'],
                'payment_method' => $args['payment_method'],
                'status' => 'confirmed',
                'confirmed_at' => now(),
                'note' => $args['note'] ?? null,
                'fb_event_id' => (string) Str::uuid(),
            ]);

            $warehouse = Warehouse::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('is_default', 1)->first()
                ?? Warehouse::withoutGlobalScopes()->where('tenant_id', $tenantId)->first();

            foreach ($items as $item) {
                $order->items()->create([
                    'tenant_id' => $tenantId,
                    'variant_id' => $item['variant_id'],
                    'product_name' => $item['product_name'],
                    'variant_name' => $item['variant_name'],
                    'sku' => $item['sku'],
                    'unit_price' => $item['unit_price'],
                    'purchase_price' => $item['purchase_price'],
                    'quantity' => $item['quantity'],
                    'line_total' => $item['line_total'],
                ]);

                if ($warehouse) {
                    Inventory::withoutGlobalScopes()
                        ->where('tenant_id', $tenantId)
                        ->where('variant_id', $item['variant_id'])
                        ->where('warehouse_id', $warehouse->id)
                        ->decrement('quantity', $item['quantity']);

                    StockMovement::withoutGlobalScopes()->create([
                        'tenant_id' => $tenantId,
                        'variant_id' => $item['variant_id'],
                        'warehouse_id' => $warehouse->id,
                        'type' => 'sale',
                        'quantity' => -$item['quantity'],
                        'reference_type' => 'order',
                        'reference_id' => $order->id,
                        'user_id' => auth('tenant')->id(),
                    ]);
                }
            }

            $customer->increment('total_orders');
            $customer->increment('total_spent', $order->total);

            return $order;
        });

        return [
            'success' => true,
            'message' => "অর্ডার তৈরি হয়েছে — {$order->order_number}",
            'order_number' => $order->order_number,
        ];
    }

    protected function resolveVariant(Product $product, ?string $variantName): ?ProductVariant
    {
        $variants = $product->variants;

        if ($variants->count() === 1) {
            return $variants->first();
        }

        $needle = trim((string) $variantName);

        if ($needle === '') {
            return null;
        }

        return $variants->first(fn ($v) => strcasecmp($v->variant_name, $needle) === 0)
            ?? $variants->first(fn ($v) => str_contains(strtolower($v->variant_name), strtolower($needle)));
    }
}
