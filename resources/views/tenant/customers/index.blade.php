@extends('layouts.panel')

@section('title', 'কাস্টমার')

@section('content')
<div class="flex flex-wrap items-center justify-between gap-3 mb-6">
    <h1 class="font-disp font-bold text-2xl">কাস্টমার</h1>
    <div class="flex items-center gap-3">
        <div class="bg-amber/15 border border-amber/40 rounded-lg px-4 py-2 text-sm">
            মোট বাকি: <b>{{ number_format($totalDue) }}৳</b>
        </div>
        <button onclick="document.getElementById('newCustModal').classList.remove('hidden')"
                class="px-4 py-2.5 rounded-lg bg-leaf text-white font-semibold text-sm hover:bg-leafdk">+ নতুন কাস্টমার</button>
    </div>
</div>

<div id="newCustModal" class="hidden fixed inset-0 bg-ink/40 z-50 grid place-items-center px-4" onclick="if(event.target===this)this.classList.add('hidden')">
    <div class="bg-white rounded-2xl p-6 w-full max-w-sm">
        <p class="font-bold mb-4">নতুন কাস্টমার যোগ করুন</p>
        <form method="POST" action="{{ route('tenant.customers.store') }}" class="space-y-3">
            @csrf
            <input name="name" required placeholder="নাম" class="w-full rounded-lg border border-ink/15 px-3 py-2.5 text-sm">
            <input name="phone" required placeholder="মোবাইল নাম্বার (01XXXXXXXXX)" class="w-full rounded-lg border border-ink/15 px-3 py-2.5 text-sm">
            <textarea name="address" rows="2" placeholder="ঠিকানা (ঐচ্ছিক)" class="w-full rounded-lg border border-ink/15 px-3 py-2.5 text-sm"></textarea>
            <div class="flex gap-2">
                <button type="button" onclick="document.getElementById('newCustModal').classList.add('hidden')"
                        class="flex-1 py-2.5 rounded-lg border border-ink/15 text-sm">বাতিল</button>
                <button class="flex-1 py-2.5 rounded-lg bg-leaf text-white font-semibold text-sm hover:bg-leafdk">যোগ করুন</button>
            </div>
        </form>
    </div>
</div>

<form class="flex flex-wrap gap-3 mb-4">
    <input name="q" value="{{ request('q') }}" placeholder="নাম বা ফোন..."
           class="rounded-lg border border-ink/15 px-3 py-2.5 text-sm w-full md:w-64 focus:ring-2 focus:ring-leaf outline-none">
    <label class="flex items-center gap-2 text-sm">
        <input type="checkbox" name="due" value="1" @checked(request('due')) onchange="this.form.submit()"> শুধু বাকি আছে যাদের
    </label>
</form>

<div class="bg-white rounded-xl border border-ink/5 overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="text-left text-mute"><tr class="border-b border-ink/5">
            <th class="px-4 py-3">কাস্টমার</th><th class="px-4 py-3">অর্ডার</th>
            <th class="px-4 py-3">মোট কেনাকাটা</th><th class="px-4 py-3">বাকি</th>
        </tr></thead>
        <tbody>
        @forelse ($customers as $c)
            <tr class="border-b border-ink/5 last:border-0 hover:bg-paper/60 cursor-pointer"
                onclick="window.location='{{ route('tenant.customers.show', $c) }}'">
                <td class="px-4 py-3"><p class="font-medium">{{ $c->name }}</p><p class="text-xs text-mute">{{ $c->phone }}</p></td>
                <td class="px-4 py-3">{{ $c->total_orders }}টি</td>
                <td class="px-4 py-3">{{ number_format($c->total_spent) }}৳</td>
                <td class="px-4 py-3 {{ $c->due_balance > 0 ? 'text-red-600 font-bold' : 'text-mute' }}">{{ number_format($c->due_balance) }}৳</td>
            </tr>
        @empty
            <tr><td colspan="4" class="px-4 py-12 text-center text-mute">কোনো কাস্টমার নেই।</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
<div class="mt-4">{{ $customers->links() }}</div>
@endsection
