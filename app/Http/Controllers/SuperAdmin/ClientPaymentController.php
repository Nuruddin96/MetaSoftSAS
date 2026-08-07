<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientPayment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ClientPaymentController extends Controller
{
    /** Regular Client Payment — global ledger across all clients */
    public function index(Request $request)
    {
        $payments = ClientPayment::with('client')
            ->when($request->client_id, fn ($q) => $q->where('client_id', $request->client_id))
            ->when($request->gateway, fn ($q) => $q->where('gateway', $request->gateway))
            ->when($request->type, fn ($q) => $q->where('payment_type', $request->type))
            ->when($request->from, fn ($q) => $q->where('payment_date', '>=', $request->from))
            ->when($request->to, fn ($q) => $q->where('payment_date', '<=', $request->to))
            ->orderByDesc('payment_date')->orderByDesc('id')
            ->paginate(30)->withQueryString();

        return view('super.clients.payments', [
            'payments' => $payments,
            'clients'  => Client::orderBy('business_name')->get(),
            'totalBdt' => (clone $this->filtered($request))->where('currency', 'BDT')->sum('amount'),
            'totalUsd' => (clone $this->filtered($request))->where('currency', 'USD')->sum('amount'),
        ]);
    }

    protected function filtered(Request $request)
    {
        return ClientPayment::when($request->client_id, fn ($q) => $q->where('client_id', $request->client_id))
            ->when($request->gateway, fn ($q) => $q->where('gateway', $request->gateway))
            ->when($request->type, fn ($q) => $q->where('payment_type', $request->type))
            ->when($request->from, fn ($q) => $q->where('payment_date', '>=', $request->from))
            ->when($request->to, fn ($q) => $q->where('payment_date', '<=', $request->to));
    }

    public function store(Request $request, Client $client)
    {
        $data = $request->validate([
            'payment_type'  => 'required|in:monthly,ad_spend,advance,other',
            'amount'        => 'required|numeric|min:0.01',
            'currency'      => 'required|in:BDT,USD',
            'gateway'       => 'required|in:bkash,nagad,bank,cash,other',
            'month_for'     => 'nullable|string|max:50',
            'payment_date'  => 'required|date',
            'attachment'    => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120',
            'note'          => 'nullable|string|max:255',
        ]);

        if ($request->hasFile('attachment')) {
            $data['attachment_path'] = $request->file('attachment')->store('client-payments/' . $client->id, 'public');
        }
        unset($data['attachment']);
        $data['client_id'] = $client->id;

        ClientPayment::create($data);

        return back()->with('success', 'পেমেন্ট এন্ট্রি যোগ হয়েছে।');
    }

    public function destroy(ClientPayment $payment)
    {
        if ($payment->attachment_path) {
            Storage::disk('public')->delete($payment->attachment_path);
        }
        $clientId = $payment->client_id;
        $payment->delete();

        return back()->with('success', 'পেমেন্ট এন্ট্রি মুছে ফেলা হয়েছে।');
    }
}
