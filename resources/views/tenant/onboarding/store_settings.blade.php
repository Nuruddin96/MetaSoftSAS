@extends('layouts.onboarding')

@section('title', 'স্টোর সেটিংস')

@section('content')
<h1 class="font-disp font-bold text-xl sm:text-2xl mb-1.5">স্টোর প্রস্তুত হয়ে গেছে</h1>
<p class="text-mute text-sm mb-6">আমরা কিছু সাধারণ সেটিংস আগে থেকেই সেট করে দিয়েছি। চাইলে এখনই বদলাতে পারেন, নয়তো পরে Settings থেকে বদলাতে পারবেন।</p>

<form method="POST" action="{{ route('tenant.onboarding.store_settings.store') }}" class="space-y-4">
    @csrf

    <div class="flex items-center justify-between rounded-lg border border-ink/10 px-3 py-2.5 bg-paper">
        <span class="text-sm font-medium">কারেন্সি</span>
        <span class="text-sm font-bold">{{ $store['currency'] ?? 'BDT' }} (৳)</span>
    </div>

    <div class="grid grid-cols-2 gap-3">
        <div>
            <label class="text-sm font-medium">ঢাকার ভিতরে ডেলিভারি চার্জ</label>
            <input type="number" step="0.01" min="0" name="delivery_charge_inside_dhaka"
                   value="{{ old('delivery_charge_inside_dhaka', $store['delivery_charge_inside_dhaka'] ?? 60) }}"
                   class="mt-1 w-full rounded-lg border border-ink/15 px-3 py-2.5 focus:ring-2 focus:ring-leaf outline-none">
        </div>
        <div>
            <label class="text-sm font-medium">ঢাকার বাইরে ডেলিভারি চার্জ</label>
            <input type="number" step="0.01" min="0" name="delivery_charge_outside_dhaka"
                   value="{{ old('delivery_charge_outside_dhaka', $store['delivery_charge_outside_dhaka'] ?? 120) }}"
                   class="mt-1 w-full rounded-lg border border-ink/15 px-3 py-2.5 focus:ring-2 focus:ring-leaf outline-none">
        </div>
    </div>

    <button type="submit" class="w-full py-3.5 rounded-xl bg-leaf text-white font-bold hover:bg-leafdk">
        চালিয়ে যান
    </button>
</form>
@endsection
