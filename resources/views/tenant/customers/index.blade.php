@extends('layouts.panel')

@section('title', 'কাস্টমার')

@section('content')
<div class="flex flex-wrap items-center justify-between gap-3 mb-6">
    <h1 class="font-disp font-bold text-2xl">কাস্টমার</h1>
    <div class="flex items-center gap-3">
        <div class="bg-amber/15 border border-amber/40 rounded-btn px-4 py-2 text-sm">
            মোট বাকি: <b>{{ number_format($totalDue) }}৳</b>
        </div>
        <x-ui.button type="button" variant="accent" size="sm" onclick="document.getElementById('newCustModal').classList.remove('hidden')">
            + নতুন কাস্টমার
        </x-ui.button>
    </div>
</div>

<div id="newCustModal" class="hidden fixed inset-0 bg-ink/40 z-50 grid place-items-center px-4" onclick="if(event.target===this)this.classList.add('hidden')">
    <x-ui.card class="w-full max-w-sm">
        <p class="font-bold mb-4">নতুন কাস্টমার যোগ করুন</p>
        <form method="POST" action="{{ route('tenant.customers.store') }}" class="space-y-3">
            @csrf
            <input name="name" required placeholder="নাম" class="w-full rounded-btn border border-ink/15 px-3 py-2.5 text-sm focus:ring-2 focus:ring-leaf outline-none">
            <input name="phone" required placeholder="মোবাইল নাম্বার (01XXXXXXXXX)" class="w-full rounded-btn border border-ink/15 px-3 py-2.5 text-sm focus:ring-2 focus:ring-leaf outline-none">
            <textarea name="address" rows="2" placeholder="ঠিকানা (ঐচ্ছিক)" class="w-full rounded-btn border border-ink/15 px-3 py-2.5 text-sm focus:ring-2 focus:ring-leaf outline-none"></textarea>
            <div class="flex gap-2">
                <button type="button" onclick="document.getElementById('newCustModal').classList.add('hidden')"
                        class="flex-1 py-2.5 rounded-btn border border-ink/15 text-sm hover:bg-paper transition">বাতিল</button>
                <x-ui.button type="submit" variant="accent" size="sm" class="flex-1">যোগ করুন</x-ui.button>
            </div>
        </form>
    </x-ui.card>
</div>

<form class="flex flex-wrap gap-3 mb-4">
    <input name="q" value="{{ request('q') }}" placeholder="নাম বা ফোন..."
           class="rounded-btn border border-ink/15 px-3 py-2.5 text-sm w-full md:w-64 focus:ring-2 focus:ring-leaf outline-none">
    <label class="flex items-center gap-2 text-sm">
        <input type="checkbox" name="due" value="1" @checked(request('due')) onchange="this.form.submit()"> শুধু বাকি আছে যাদের
    </label>
</form>

{{-- bulk Messenger bar — mirrors tenant/orders/index.blade.php's #bulkBar pattern --}}
<div id="bulkBar" class="hidden mb-4 bg-ink text-white rounded-card px-4 py-3 flex flex-wrap items-center gap-3 text-sm">
    <span><span id="selCount">0</span>জন সিলেক্ট করা হয়েছে</span>
    <button type="button" onclick="document.getElementById('bulkMessengerModal').classList.remove('hidden')"
            class="px-3 py-1.5 rounded-btn bg-white/15 hover:bg-white/25 transition">📩 Messenger মেসেজ পাঠান</button>
</div>

<div id="bulkMessengerModal" class="hidden fixed inset-0 bg-ink/40 z-50 grid place-items-center px-4" onclick="if(event.target===this)this.classList.add('hidden')">
    <x-ui.card class="w-full max-w-sm">
        <p class="font-bold mb-1">Messenger মেসেজ পাঠান</p>
        <p class="text-xs text-mute mb-4">শুধু যেসব গ্রাহক আগে Messenger-এ পেজে মেসেজ করেছেন তারাই পাবেন — বাকিদের কাছে পাঠানো যাবে না, ফলাফলে সেটা দেখানো হবে।</p>
        <form method="POST" action="{{ route('tenant.customers.bulk-messenger') }}" enctype="multipart/form-data" class="space-y-3" id="bulkMessengerForm">
            @csrf
            <textarea name="message" rows="3" placeholder="মেসেজ লিখুন (ঐচ্ছিক যদি ছবি দেন)" class="w-full rounded-btn border border-ink/15 px-3 py-2.5 text-sm focus:ring-2 focus:ring-leaf outline-none"></textarea>
            <input type="file" name="image" accept="image/*" class="w-full text-xs border border-dashed border-ink/25 rounded-btn px-2 py-2 cursor-pointer hover:bg-paper transition file:mr-2 file:px-2 file:py-1 file:rounded-btn file:border-0 file:bg-ink/5 file:text-xs file:font-semibold file:cursor-pointer">
            <div class="flex gap-2">
                <button type="button" onclick="document.getElementById('bulkMessengerModal').classList.add('hidden')"
                        class="flex-1 py-2.5 rounded-btn border border-ink/15 text-sm hover:bg-paper transition">বাতিল</button>
                <x-ui.button type="submit" variant="accent" size="sm" class="flex-1">পাঠান</x-ui.button>
            </div>
        </form>
    </x-ui.card>
</div>

@if (session('bulkMessengerResults'))
    <div class="mb-4 border border-ink/10 rounded-card overflow-hidden">
        <div class="px-4 py-2 bg-paper text-xs font-semibold text-mute">পাঠানোর ফলাফল</div>
        <div class="divide-y divide-ink/5 text-sm">
            @foreach (session('bulkMessengerResults') as $r)
                <div class="px-4 py-2 flex items-center justify-between gap-3">
                    <span>{{ $r['customer_name'] ?? ('#'.$r['customer_id']) }}</span>
                    @if ($r['status'] === 'sent')
                        <span class="text-leafdk text-xs font-semibold shrink-0">✅ পাঠানো হয়েছে</span>
                    @else
                        <span class="text-red-600 text-xs shrink-0">❌ {{ $r['reason'] }}</span>
                    @endif
                </div>
            @endforeach
        </div>
    </div>
@endif

<x-ui.card padding="none" class="overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="text-left text-mute"><tr class="border-b border-ink/5">
            <th class="px-4 py-3"><input type="checkbox" id="selectAll"></th>
            <th class="px-4 py-3">কাস্টমার</th><th class="px-4 py-3">অর্ডার</th>
            <th class="px-4 py-3">মোট কেনাকাটা</th><th class="px-4 py-3">বাকি</th>
        </tr></thead>
        <tbody>
        @forelse ($customers as $c)
            <tr class="border-b border-ink/5 last:border-0 hover:bg-paper/60">
                <td class="px-4 py-3" onclick="event.stopPropagation()">
                    <input type="checkbox" class="cust-check" value="{{ $c->id }}">
                </td>
                <td class="px-4 py-3 cursor-pointer" onclick="window.location='{{ route('tenant.customers.show', $c) }}'">
                    <p class="font-medium">{{ $c->name }}</p><p class="text-xs text-mute">{{ $c->phone }}</p>
                </td>
                <td class="px-4 py-3">{{ $c->total_orders }}টি</td>
                <td class="px-4 py-3">{{ number_format($c->total_spent) }}৳</td>
                <td class="px-4 py-3 {{ $c->due_balance > 0 ? 'text-red-600 font-bold' : 'text-mute' }}">{{ number_format($c->due_balance) }}৳</td>
            </tr>
        @empty
            <tr><td colspan="5" class="px-4 py-14 text-center text-mute">
                <i data-lucide="users" class="w-8 h-8 mx-auto mb-3 text-mute/40"></i>
                কোনো কাস্টমার নেই।
            </td></tr>
        @endforelse
        </tbody>
    </table>
</x-ui.card>
<div class="mt-4">{{ $customers->links() }}</div>

@push('scripts')
<script>
(function () {
    const selectAll = document.getElementById('selectAll');
    const bulkBar = document.getElementById('bulkBar');
    const selCount = document.getElementById('selCount');
    const form = document.getElementById('bulkMessengerForm');
    if (!selectAll || !form) return;

    function checks() { return Array.from(document.querySelectorAll('.cust-check')); }

    function refreshBulkBar() {
        const selected = checks().filter(c => c.checked);
        bulkBar.classList.toggle('hidden', selected.length === 0);
        selCount.textContent = selected.length;
        form.querySelectorAll('input[name="customer_ids[]"]').forEach(el => el.remove());
        selected.forEach(c => {
            const input = document.createElement('input');
            input.type = 'hidden'; input.name = 'customer_ids[]'; input.value = c.value;
            form.appendChild(input);
        });
    }

    selectAll.addEventListener('change', () => {
        checks().forEach(c => { c.checked = selectAll.checked; });
        refreshBulkBar();
    });
    checks().forEach(c => c.addEventListener('change', refreshBulkBar));
})();
</script>
@endpush
@endsection
