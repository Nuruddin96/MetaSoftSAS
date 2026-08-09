<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use Illuminate\Http\Request;

class ClientController extends Controller
{
    public function index(Request $request)
    {
        $clients = Client::when($request->q, fn ($q) => $q->where(fn ($qq) => $qq
            ->where('business_name', 'like', '%'.$request->q.'%')
            ->orWhere('client_name', 'like', '%'.$request->q.'%')
            ->orWhere('phone', 'like', '%'.$request->q.'%')))
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->orderByDesc('id')->paginate(25)->withQueryString();

        return view('super.clients.index', [
            'clients' => $clients,
            'totalDue' => Client::sum('due_amount'),
            'totalAdv' => Client::sum('advance_amount'),
        ]);
    }

    public function create()
    {
        return view('super.clients.form', ['client' => null]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $client = Client::create($data);

        return redirect()->route('super.clients.show', $client)->with('success', 'ক্লায়েন্ট যোগ হয়েছে।');
    }

    public function show(Client $client)
    {
        return view('super.clients.show', [
            'client' => $client,
            'payments' => $client->payments()->paginate(20),
        ]);
    }

    public function edit(Client $client)
    {
        return view('super.clients.form', compact('client'));
    }

    public function update(Request $request, Client $client)
    {
        $client->update($this->validated($request));

        return redirect()->route('super.clients.show', $client)->with('success', 'ক্লায়েন্টের তথ্য আপডেট হয়েছে।');
    }

    public function destroy(Client $client)
    {
        $client->delete();

        return redirect()->route('super.clients.index')->with('success', 'ক্লায়েন্ট মুছে ফেলা হয়েছে।');
    }

    protected function validated(Request $request): array
    {
        return $request->validate([
            'business_name' => 'required|string|max:200',
            'client_name' => 'required|string|max:150',
            'phone' => 'nullable|string|max:20',
            'address' => 'nullable|string|max:255',
            'service' => 'nullable|string|max:255',
            'monthly_charge' => 'nullable|numeric|min:0',
            'due_amount' => 'nullable|numeric|min:0',
            'advance_amount' => 'nullable|numeric|min:0',
            'status' => 'required|in:active,paused,ended',
            'note' => 'nullable|string|max:1000',
        ]);
    }
}
