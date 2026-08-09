<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\DueLedger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CustomerController extends Controller
{
    public function index(Request $request)
    {
        $customers = Customer::when($request->q, fn ($q) => $q
            ->where('name', 'like', '%'.$request->q.'%')
            ->orWhere('phone', 'like', '%'.$request->q.'%'))
            ->when($request->due, fn ($q) => $q->where('due_balance', '>', 0))
            ->orderByDesc('id')->paginate(25)->withQueryString();

        return view('tenant.customers.index', [
            'customers' => $customers,
            'totalDue' => Customer::sum('due_balance'),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:150',
            'phone' => 'required|regex:/^01[3-9][0-9]{8}$/|unique:customers,phone,NULL,id,tenant_id,'.app('currentTenant')->id,
            'email' => 'nullable|email|max:150',
            'address' => 'nullable|string|max:1000',
            'note' => 'nullable|string|max:500',
        ], [
            'phone.regex' => 'সঠিক মোবাইল নাম্বার দিন (01XXXXXXXXX)।',
            'phone.unique' => 'এই নাম্বারে কাস্টমার আগেই আছে।',
        ]);

        Customer::create($data);

        return back()->with('success', 'কাস্টমার যোগ হয়েছে।');
    }

    public function show(Customer $customer)
    {
        return view('tenant.customers.show', [
            'customer' => $customer,
            'orders' => $customer->orders()->latest()->limit(20)->get(),
            'ledger' => $customer->dueLedger()->latest()->limit(30)->get(),
        ]);
    }

    /** কাস্টমারের বাকি বাড়ান (ম্যানুয়ালি যোগ করা — যেমন আগের কোনো বাকি এন্ট্রি করতে) */
    public function addDue(Request $request, Customer $customer)
    {
        $data = $request->validate([
            'amount' => 'required|numeric|min:1',
            'note' => 'nullable|string|max:255',
        ]);

        DB::transaction(function () use ($customer, $data) {
            $customer->increment('due_balance', $data['amount']);

            DueLedger::create([
                'customer_id' => $customer->id,
                'type' => 'due',
                'amount' => $data['amount'],
                'balance_after' => $customer->fresh()->due_balance,
                'note' => $data['note'] ?? 'ম্যানুয়ালি বাকি যোগ করা হয়েছে',
                'user_id' => auth('tenant')->id(),
            ]);
        });

        return back()->with('success', number_format($data['amount']).'৳ বাকি যোগ হয়েছে।');
    }

    /** বাকি আদায় */
    public function receiveDue(Request $request, Customer $customer)
    {
        $data = $request->validate([
            'amount' => 'required|numeric|min:1',
            'note' => 'nullable|string|max:255',
        ]);

        $amount = min((float) $data['amount'], (float) $customer->due_balance);

        if ($amount <= 0) {
            return back()->with('error', 'এই কাস্টমারের কোনো বাকি নেই।');
        }

        DB::transaction(function () use ($customer, $amount, $data) {
            $customer->decrement('due_balance', $amount);

            DueLedger::create([
                'customer_id' => $customer->id,
                'type' => 'payment',
                'amount' => $amount,
                'balance_after' => $customer->fresh()->due_balance,
                'note' => $data['note'] ?? 'বাকি আদায়',
                'user_id' => auth('tenant')->id(),
            ]);
        });

        return back()->with('success', number_format($amount).'৳ আদায় হয়েছে।');
    }
}
