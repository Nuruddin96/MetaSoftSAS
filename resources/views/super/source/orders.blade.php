@extends('layouts.super')
@section('title', 'সোর্সিং অর্ডার')
@section('content')
<h1 class="font-disp font-bold text-2xl mb-6">টেনেন্টদের সোর্সিং অর্ডার</h1>

<form class="mb-4">
    <select name="status" onchange="this.form.submit()" class="rounded-lg border border-ink/15 px-3 py-2.5 text-sm bg-white">
        <option value="">সব স্ট্যাটাস</option>
        @foreach (['pending' => 'পেন্ডিং', 'contacted' => 'যোগাযোগ হয়েছে', 'confirmed' => 'কনফার্মড', 'shipped' => 'শিপড', 'delivered' => 'ডেলিভারড', 'cancelled' => 'বাতিল'] as $k => $v)
            <option value="{{ $k }}" @selected(request('status') === $k)>{{ $v }}</option>
        @endforeach
    </select>
</form>

<div class="space-y-4">
    @forelse ($orders as $o)
        <div class="bg-white rounded-xl border border-ink/5 p-5">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p class="font-bold">{{ $o->product?->name }} × {{ $o->quantity }}</p>
                    <p class="text-sm text-mute mt-1">
                        টেনেন্ট: <b class="text-ink">{{ $tenants[$o->tenant_id]->store_name ?? '—' }}</b>
                        · <a href="tel:{{ $o->contact_phone }}" class="text-leaf">{{ $o->contact_phone }}</a>
                    </p>
                    @if ($o->note)<p class="text-sm text-mute mt-1">নোট: {{ $o->note }}</p>@endif
                    <p class="text-xs text-mute mt-1">{{ $o->created_at->format('d M Y, h:i A') }}</p>
                </div>
                <span class="px-2 py-1 rounded text-xs bg-ink/5">{{ $o->status }}</span>
            </div>
            <form method="POST" action="{{ route('super.source.orders.update', $o) }}" class="flex flex-wrap gap-3 mt-4">
                @csrf @method('PUT')
                <select name="status" class="rounded-lg border border-ink/15 px-3 py-2 text-sm bg-white">
                    @foreach (['pending' => 'পেন্ডিং', 'contacted' => 'যোগাযোগ হয়েছে', 'confirmed' => 'কনফার্মড', 'shipped' => 'শিপড', 'delivered' => 'ডেলিভারড', 'cancelled' => 'বাতিল'] as $k => $v)
                        <option value="{{ $k }}" @selected($o->status === $k)>{{ $v }}</option>
                    @endforeach
                </select>
                <input name="admin_note" value="{{ $o->admin_note }}" placeholder="অ্যাডমিন নোট" class="flex-1 min-w-[180px] rounded-lg border border-ink/15 px-3 py-2 text-sm">
                <button class="px-4 py-2 rounded-lg bg-ink text-white text-sm font-semibold">আপডেট</button>
            </form>
        </div>
    @empty
        <p class="text-center text-mute py-12 bg-white rounded-xl border border-ink/5">কোনো সোর্সিং অর্ডার নেই।</p>
    @endforelse
</div>
<div class="mt-4">{{ $orders->links() }}</div>
@endsection
