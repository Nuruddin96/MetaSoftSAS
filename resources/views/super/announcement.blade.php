@extends('layouts.super')

@section('title', 'টেনেন্ট ঘোষণা')

@section('content')
<h1 class="font-disp font-bold text-2xl mb-2">📢 টেনেন্ট ঘোষণা</h1>
<p class="text-sm text-mute mb-6">এখানে যা লিখবেন তা সব টেনেন্টের ড্যাশবোর্ডে হেডারের ঠিক পরে দেখাবে। টেনেন্টরা এটা এডিট করতে পারবে না। খালি রাখলে/মুছে ফেললে কোনো বক্স দেখাবে না।</p>

<x-ui.card class="max-w-2xl">
    <form method="POST" action="{{ route('super.announcement.update') }}" class="space-y-3">
        @csrf
        <textarea name="message" rows="3" maxlength="500" required placeholder="যেমন: আগামী শুক্রবার (৩০ আগস্ট) রাত ১২টা থেকে ২টা পর্যন্ত সার্ভার মেইনটেন্যান্সের জন্য সাইট সাময়িক বন্ধ থাকতে পারে।"
                  class="w-full rounded-lg border border-ink/15 px-3 py-2.5 text-sm focus:ring-2 focus:ring-leaf outline-none">{{ $announcement->message ?? '' }}</textarea>
        <button class="px-5 py-2.5 rounded-lg bg-leaf text-white font-semibold text-sm hover:bg-leafdk">সেভ করুন</button>
    </form>
    @if ($announcement)
        <form method="POST" action="{{ route('super.announcement.destroy') }}" onsubmit="return confirm('ঘোষণাটি মুছে ফেলবেন? সব টেনেন্টের ড্যাশবোর্ড থেকে সরে যাবে।')" class="mt-3">
            @csrf @method('DELETE')
            <button class="px-4 py-2.5 rounded-lg bg-red-50 text-red-600 font-semibold text-sm hover:bg-red-100">মুছে ফেলুন</button>
        </form>
    @endif
</x-ui.card>
@endsection
