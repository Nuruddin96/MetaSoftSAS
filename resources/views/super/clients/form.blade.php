@extends('layouts.super')
@section('title', $client ? 'ক্লায়েন্ট এডিট' : 'নতুন ক্লায়েন্ট')
@section('content')
<h1 class="font-disp font-bold text-2xl mb-6">{{ $client ? 'ক্লায়েন্ট এডিট' : 'নতুন ক্লায়েন্ট যোগ করুন' }}</h1>

<form method="POST" action="{{ $client ? route('super.clients.update', $client) : route('super.clients.store') }}"
      class="max-w-2xl bg-white rounded-xl border border-ink/5 p-6 space-y-4">
    @csrf
    @if ($client) @method('PUT') @endif

    <div class="grid md:grid-cols-2 gap-4">
        <div>
            <label class="text-sm font-medium">বিজনেসের নাম *</label>
            <input name="business_name" value="{{ old('business_name', $client?->business_name) }}" required class="mt-1 w-full rounded-lg border border-ink/15 px-3 py-2.5">
        </div>
        <div>
            <label class="text-sm font-medium">ক্লায়েন্টের নাম *</label>
            <input name="client_name" value="{{ old('client_name', $client?->client_name) }}" required class="mt-1 w-full rounded-lg border border-ink/15 px-3 py-2.5">
        </div>
        <div>
            <label class="text-sm font-medium">ফোন নাম্বার</label>
            <input name="phone" value="{{ old('phone', $client?->phone) }}" class="mt-1 w-full rounded-lg border border-ink/15 px-3 py-2.5">
        </div>
        <div>
            <label class="text-sm font-medium">স্ট্যাটাস</label>
            <select name="status" class="mt-1 w-full rounded-lg border border-ink/15 px-3 py-2.5 bg-white">
                @foreach (['active' => 'অ্যাক্টিভ', 'paused' => 'পজড', 'ended' => 'শেষ'] as $k => $v)
                    <option value="{{ $k }}" @selected(old('status', $client?->status ?? 'active') === $k)>{{ $v }}</option>
                @endforeach
            </select>
        </div>
    </div>

    <div>
        <label class="text-sm font-medium">ঠিকানা</label>
        <input name="address" value="{{ old('address', $client?->address) }}" class="mt-1 w-full rounded-lg border border-ink/15 px-3 py-2.5">
    </div>

    <div>
        <label class="text-sm font-medium">কোন সার্ভিস নিয়েছে</label>
        <input name="service" value="{{ old('service', $client?->service) }}" placeholder="যেমন: Package 3 — Digital Marketing"
               class="mt-1 w-full rounded-lg border border-ink/15 px-3 py-2.5">
    </div>

    <div class="grid md:grid-cols-3 gap-4">
        <div>
            <label class="text-sm font-medium">মাসিক চার্জ (৳)</label>
            <input name="monthly_charge" type="number" step="0.01" min="0" value="{{ old('monthly_charge', $client?->monthly_charge) }}" class="mt-1 w-full rounded-lg border border-ink/15 px-3 py-2.5">
        </div>
        <div>
            <label class="text-sm font-medium">বর্তমান ডিউ (৳)</label>
            <input name="due_amount" type="number" step="0.01" min="0" value="{{ old('due_amount', $client?->due_amount ?? 0) }}" class="mt-1 w-full rounded-lg border border-ink/15 px-3 py-2.5">
        </div>
        <div>
            <label class="text-sm font-medium">বর্তমান অগ্রিম (৳)</label>
            <input name="advance_amount" type="number" step="0.01" min="0" value="{{ old('advance_amount', $client?->advance_amount ?? 0) }}" class="mt-1 w-full rounded-lg border border-ink/15 px-3 py-2.5">
        </div>
    </div>

    <div>
        <label class="text-sm font-medium">নোট</label>
        <textarea name="note" rows="3" class="mt-1 w-full rounded-lg border border-ink/15 px-3 py-2.5 text-sm">{{ old('note', $client?->note) }}</textarea>
    </div>

    <button class="px-6 py-2.5 rounded-lg bg-leaf text-white font-semibold text-sm hover:bg-leafdk">{{ $client ? 'আপডেট করুন' : 'যোগ করুন' }}</button>
</form>
@endsection
