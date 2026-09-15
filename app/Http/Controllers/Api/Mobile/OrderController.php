<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\OrderResource;
use App\Models\Order;
use App\Services\Api\CourierDispatchService;
use App\Services\Api\OrderCreationService;
use App\Services\Marketing\MetaCapiService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Every single-record lookup below explicitly filters by tenant_id even
 * though BelongsToTenant's global scope (active once BindTenantFromSanctumUser
 * has bound currentTenant) would already do this — same defense-in-depth
 * convention Tenant\CourierController::send()/OrderController::complete()
 * already use, applied consistently across this new API surface. Never
 * relies on implicit route-model binding.
 */
class OrderController extends Controller
{
    public function index(Request $request)
    {
        $tenant = app('currentTenant');

        $orders = Order::where('tenant_id', $tenant->id)
            ->with('items')
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->when($request->channel, fn ($q) => $q->where('channel', $request->channel))
            ->when($request->courier === 'pending', fn ($q) => $q->whereNotNull('courier_consignment_id')
                ->whereNotIn('status', ['delivered', 'cancelled', 'returned']))
            ->when($request->q, function ($q) use ($request) {
                $q->where(fn ($qq) => $qq
                    ->where('order_number', 'like', '%'.$request->q.'%')
                    ->orWhere('customer_phone', 'like', '%'.$request->q.'%')
                    ->orWhere('customer_name', 'like', '%'.$request->q.'%'));
            })
            ->latest()->paginate(20);

        return response()->json([
            'data' => OrderResource::collection($orders->items()),
            'meta' => [
                'current_page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
                'per_page' => $orders->perPage(),
                'total' => $orders->total(),
            ],
        ]);
    }

    public function show(Request $request, int $order)
    {
        $tenant = app('currentTenant');
        $order = Order::where('tenant_id', $tenant->id)->with('items')->findOrFail($order);

        return response()->json((new OrderResource($order))->toArray($request));
    }

    public function store(Request $request, OrderCreationService $service)
    {
        $data = $request->validate([
            'customer_name' => 'required|string|max:150',
            'customer_phone' => 'required|regex:/^01[3-9][0-9]{8}$/',
            'customer_address' => 'nullable|string|max:1000',
            'division_id' => 'nullable|integer|exists:bd_divisions,id',
            'district_id' => 'nullable|integer|exists:bd_districts,id',
            'upazila_id' => 'nullable|integer|exists:bd_upazilas,id',
            'channel' => 'required|in:website,facebook,instagram,whatsapp,call,others',
            'payment_method' => 'required|in:cod,cash,bkash,nagad,bank',
            'order_date' => 'nullable|date|before_or_equal:today',
            'discount' => 'nullable|numeric|min:0',
            'additional_amount' => 'nullable|numeric|min:0',
            'note' => 'nullable|string|max:500',
            'variant_ids' => 'required|array|min:1',
            'variant_ids.*' => 'required|exists:product_variants,id',
            'quantities' => 'required|array|min:1',
            'quantities.*' => 'required|integer|min:1',
        ], [
            'customer_phone.regex' => 'সঠিক মোবাইল নাম্বার দিন (01XXXXXXXXX)।',
        ]);

        try {
            $order = $service->createFromManualEntry(
                $data,
                $request->user()->id,
                $request->ip(),
                $request->userAgent(),
            );
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['variant_ids' => [$e->getMessage()]]);
        }

        return response()->json((new OrderResource($order->load('items')))->toArray($request), 201);
    }

    /**
     * Mirrors Tenant\OrderController::complete() — confirms a Messenger-
     * originated pending order (status=pending, zero items) by attaching
     * the staff-picked product/variant/qty/price. Priority 3 parity pass:
     * previously only the web panel could finish these orders.
     */
    public function complete(Request $request, int $order, OrderCreationService $service)
    {
        $tenant = app('currentTenant');
        $order = Order::where('tenant_id', $tenant->id)->findOrFail($order);

        abort_if($order->status !== 'pending' || $order->items()->exists(), 409);

        $data = $request->validate([
            'customer_name' => 'nullable|string|max:150',
            'customer_phone' => 'nullable|regex:/^01[3-9][0-9]{8}$/',
            'customer_address' => 'nullable|string|max:1000',
            'division_id' => 'nullable|integer|exists:bd_divisions,id',
            'district_id' => 'nullable|integer|exists:bd_districts,id',
            'upazila_id' => 'nullable|integer|exists:bd_upazilas,id',
            'payment_method' => 'required|in:cod,cash,bkash,nagad,bank',
            'order_date' => 'nullable|date|before_or_equal:today',
            'discount' => 'nullable|numeric|min:0',
            'additional_amount' => 'nullable|numeric|min:0',
            'note' => 'nullable|string|max:500',
            'variant_ids' => 'required|array|min:1',
            'variant_ids.*' => 'required|exists:product_variants,id',
            'quantities' => 'required|array|min:1',
            'quantities.*' => 'required|integer|min:1',
        ], [
            'customer_phone.regex' => 'সঠিক মোবাইল নাম্বার দিন (01XXXXXXXXX)।',
        ]);

        try {
            $order = $service->completeFromMessenger(
                $order,
                $data,
                $request->user()->id,
                $request->ip(),
                $request->userAgent(),
            );
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['variant_ids' => [$e->getMessage()]]);
        }

        return response()->json((new OrderResource($order->load('items')))->toArray($request));
    }

    public function updateStatus(Request $request, int $order)
    {
        $tenant = app('currentTenant');
        $data = $request->validate([
            'status' => 'required|in:pending,confirmed,processing,shipped,delivered,cancelled,returned',
        ]);

        $order = Order::where('tenant_id', $tenant->id)->findOrFail($order);

        $wasConfirmed = $order->status === 'confirmed';

        $order->update([
            'status' => $data['status'],
            'confirmed_at' => $data['status'] === 'confirmed' ? now() : $order->confirmed_at,
            'delivered_at' => $data['status'] === 'delivered' ? now() : $order->delivered_at,
        ]);

        if ($data['status'] === 'confirmed' && ! $wasConfirmed) {
            MetaCapiService::sendPurchaseForOrder($order, $request->ip(), $request->userAgent());
        }

        return response()->json((new OrderResource($order->load('items')))->toArray($request));
    }

    /** Mirrors Tenant\OrderController::updateChannel() — order-source correction, missing from this mobile surface until now. */
    public function updateChannel(Request $request, int $order)
    {
        $tenant = app('currentTenant');
        $data = $request->validate([
            'channel' => 'required|in:website,facebook,instagram,whatsapp,call,others',
        ]);

        $order = Order::where('tenant_id', $tenant->id)->findOrFail($order);
        $order->update(['channel' => $data['channel']]);

        return response()->json((new OrderResource($order->load('items')))->toArray($request));
    }

    public function courier(Request $request, int $order, CourierDispatchService $service)
    {
        $tenant = app('currentTenant');
        $data = $request->validate(['provider' => 'required|in:steadfast,pathao']);

        $order = Order::where('tenant_id', $tenant->id)->findOrFail($order);

        try {
            $order = $service->dispatch($order, $data['provider']);
        } catch (\RuntimeException $e) {
            // 422 (not 409) deliberately — Flutter's error_interceptor only
            // extracts a message for 401/403/404/422/5xx; this keeps the
            // friendly Bengali message intact on the client instead of
            // falling back to a generic "কিছু একটা ভুল হয়েছে".
            throw ValidationException::withMessages(['provider' => [$e->getMessage()]]);
        }

        return response()->json((new OrderResource($order->load('items')))->toArray($request));
    }

    public function refreshCourierStatus(Request $request, int $order, CourierDispatchService $service)
    {
        $tenant = app('currentTenant');
        $order = Order::where('tenant_id', $tenant->id)->findOrFail($order);

        try {
            $order = $service->refreshStatus($order);
        } catch (\RuntimeException $e) {
            // Same 422-not-409 reasoning as courier() above.
            throw ValidationException::withMessages(['courier' => [$e->getMessage()]]);
        }

        return response()->json((new OrderResource($order->load('items')))->toArray($request));
    }

    /**
     * Mirrors Tenant\OrderController::bulkStatus() — mobile's multi-select
     * equivalent of the web checkbox table's bulk-action bar. Tenant
     * scoping happens via the update query itself (not a raw `exists:`
     * validation rule, which would be unscoped — see this file's class
     * docblock) so a foreign-tenant id in the array is silently excluded
     * rather than 404ing the whole request.
     */
    public function bulkStatus(Request $request)
    {
        $tenant = app('currentTenant');
        $data = $request->validate([
            'order_ids' => 'required|array|min:1',
            'order_ids.*' => 'integer',
            'status' => 'required|in:pending,confirmed,processing,shipped,delivered,cancelled,returned',
        ]);

        $count = Order::where('tenant_id', $tenant->id)
            ->whereIn('id', $data['order_ids'])
            ->update(['status' => $data['status']]);

        return response()->json(['updated' => $count]);
    }

    /**
     * Mirrors Tenant\OrderController::bulkCourier() — same multi-select
     * bulk-action shape, but reuses CourierDispatchService::dispatch() per
     * order (the same guarded, friendly-error path courier() already uses)
     * instead of duplicating CourierManager's raw call + guard checks web's
     * own bulkCourier() inlines.
     */
    public function bulkCourier(Request $request, CourierDispatchService $service)
    {
        $tenant = app('currentTenant');
        $data = $request->validate([
            'order_ids' => 'required|array|min:1',
            'order_ids.*' => 'integer',
            'provider' => 'required|in:steadfast,pathao',
        ]);

        $orders = Order::where('tenant_id', $tenant->id)->whereIn('id', $data['order_ids'])->get();

        $sent = 0;
        $failed = 0;
        $errors = [];

        foreach ($orders as $order) {
            try {
                $service->dispatch($order, $data['provider']);
                $sent++;
            } catch (\RuntimeException $e) {
                $failed++;
                $errors[] = $e->getMessage();
            }
        }

        return response()->json(['sent' => $sent, 'failed' => $failed, 'errors' => $errors]);
    }
}
