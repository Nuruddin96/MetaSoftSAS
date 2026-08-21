<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\CustomerResource;
use App\Models\Customer;
use App\Services\Api\CustomerDueService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Edit Customer is deliberately NOT implemented — no update() action exists
 * anywhere in Tenant\CustomerController or routes/web.php's customer group
 * (confirmed by direct route-file inspection, not just controller absence).
 */
class CustomerController extends Controller
{
    public function index(Request $request)
    {
        $tenant = app('currentTenant');

        $customers = Customer::where('tenant_id', $tenant->id)
            ->when($request->q, fn ($q) => $q
                ->where('name', 'like', '%'.$request->q.'%')
                ->orWhere('phone', 'like', '%'.$request->q.'%'))
            ->when($request->due, fn ($q) => $q->where('due_balance', '>', 0))
            ->orderByDesc('id')->paginate(25);

        return response()->json([
            'data' => CustomerResource::collection($customers->items()),
            'meta' => [
                'current_page' => $customers->currentPage(),
                'last_page' => $customers->lastPage(),
                'per_page' => $customers->perPage(),
                'total' => $customers->total(),
            ],
        ]);
    }

    public function show(Request $request, int $customer)
    {
        $tenant = app('currentTenant');
        $customer = Customer::where('tenant_id', $tenant->id)->findOrFail($customer);

        $resource = new CustomerResource($customer);
        $resource->recentOrders = $customer->orders()->latest()->limit(20)->get();

        return response()->json($resource->toArray($request));
    }

    public function store(Request $request)
    {
        $tenant = app('currentTenant');

        $data = $request->validate([
            'name' => 'required|string|max:150',
            'phone' => 'required|regex:/^01[3-9][0-9]{8}$/|unique:customers,phone,NULL,id,tenant_id,'.$tenant->id,
            'email' => 'nullable|email|max:150',
            'address' => 'nullable|string|max:1000',
            'note' => 'nullable|string|max:500',
        ], [
            'phone.regex' => 'সঠিক মোবাইল নাম্বার দিন (01XXXXXXXXX)।',
            'phone.unique' => 'এই নাম্বারে কাস্টমার আগেই আছে।',
        ]);

        $customer = Customer::create($data);

        return response()->json((new CustomerResource($customer))->toArray($request), 201);
    }

    /**
     * Primary endpoint the Flutter app actually calls for BOTH "add due"
     * and "settle/receive due" — CustomersApi posts a `type` field
     * ('due'|'payment') to this one path rather than two separate ones.
     * Also mirrors the web app's `customers.due.receive` route name
     * (POST .../due = receive), so `type` defaults to 'payment' when
     * omitted. See addDue() below for the dedicated .../due/add path the
     * web app also exposes (kept for parity, not required by Flutter).
     */
    public function due(Request $request, int $customer, CustomerDueService $service)
    {
        $data = $request->validate([
            'type' => 'nullable|in:due,payment',
            'amount' => 'required|numeric|min:1',
            'note' => 'nullable|string|max:255',
        ]);

        return ($data['type'] ?? 'payment') === 'due'
            ? $this->addDue($request, $customer, $service, $data)
            : $this->receiveDue($request, $customer, $service, $data);
    }

    public function addDue(Request $request, int $customer, CustomerDueService $service, ?array $data = null)
    {
        $tenant = app('currentTenant');
        $data ??= $request->validate(['amount' => 'required|numeric|min:1', 'note' => 'nullable|string|max:255']);

        $customer = Customer::where('tenant_id', $tenant->id)->findOrFail($customer);
        $customer = $service->addDue($customer, (float) $data['amount'], $data['note'] ?? null, $request->user()->id);

        return response()->json((new CustomerResource($customer))->toArray($request));
    }

    public function receiveDue(Request $request, int $customer, CustomerDueService $service, ?array $data = null)
    {
        $tenant = app('currentTenant');
        $data ??= $request->validate(['amount' => 'required|numeric|min:1', 'note' => 'nullable|string|max:255']);

        $customer = Customer::where('tenant_id', $tenant->id)->findOrFail($customer);

        try {
            $customer = $service->receiveDue($customer, (float) $data['amount'], $data['note'] ?? null, $request->user()->id);
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages(['amount' => [$e->getMessage()]]);
        }

        return response()->json((new CustomerResource($customer))->toArray($request));
    }
}
