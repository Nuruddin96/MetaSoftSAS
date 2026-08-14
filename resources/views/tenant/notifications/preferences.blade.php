@extends('layouts.panel')

@section('title', 'নোটিফিকেশন')

@section('content')
{{-- Enable/disable state — this card's visibility is entirely client-side
     (Notification.permission can't be read server-side), so it starts
     hidden and #notifStatusScript below decides which of the three states
     (not supported / not yet enabled / enabled) to show. --}}
<x-ui.card class="mb-6" id="notifEnableCard">
    <div class="flex items-start gap-3 text-sm">
        <i data-lucide="bell" class="w-4 h-4 text-leafdk shrink-0 mt-0.5"></i>
        <div class="flex-1">
            <p id="notifEnableText" class="font-semibold mb-2">লোড হচ্ছে...</p>
            <x-ui.button id="notifEnableBtn" type="button" variant="accent" size="sm" class="hidden">নোটিফিকেশন চালু করুন</x-ui.button>
        </div>
    </div>
</x-ui.card>

<x-ui.card>
    <p class="font-bold text-sm mb-1">কোন বিষয়ে নোটিফিকেশন পাবেন</p>
    <p class="text-mute text-xs mb-4">নিরাপত্তা সংক্রান্ত সতর্কতা (নতুন লগইন, পাসওয়ার্ড পরিবর্তন) সবসময় চালু থাকে এবং বন্ধ করা যায় না।</p>

    <form method="POST" action="{{ route('tenant.notifications.preferences.update') }}" class="space-y-1">
        @csrf
        @foreach ($categories as $key => $meta)
            <label class="flex items-center justify-between gap-3 py-3 border-b border-ink/5 last:border-0 text-sm">
                <span class="flex items-center gap-2.5">
                    <span>{{ $meta['icon'] }}</span>
                    <span>{{ $meta['label'] }}</span>
                </span>
                <input type="checkbox" name="categories[]" value="{{ $key }}" class="w-5 h-5 accent-leaf" @checked($meta['enabled'])>
            </label>
        @endforeach

        <div class="pt-4">
            <x-ui.button type="submit" variant="accent" size="sm">সেভ করুন</x-ui.button>
        </div>
    </form>
</x-ui.card>

@push('scripts')
<script>
    (function () {
        const text = document.getElementById('notifEnableText');
        const btn = document.getElementById('notifEnableBtn');
        if (!text || !btn) return;

        const supported = 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;

        // `justSubscribed` (true/false/undefined): the confirmed outcome of
        // a just-completed metasoftEnablePush() attempt. Browser
        // permission alone ('granted') is not proof the server actually
        // recorded the subscription — fetch() doesn't throw on a non-2xx
        // response, so without this, a server-side failure right after
        // granting permission would still render "চালু আছে ✓" (see the
        // pre-production review, B.4). Falls back to inferring from
        // Notification.permission only on the initial page-load render,
        // where no fresher signal exists yet.
        function render(justSubscribed) {
            if (!supported) {
                text.textContent = 'এই ব্রাউজারে নোটিফিকেশন সাপোর্ট করে না।';
                btn.classList.add('hidden');
            } else if (justSubscribed === true || (justSubscribed === undefined && Notification.permission === 'granted')) {
                text.textContent = 'নোটিফিকেশন চালু আছে ✓';
                btn.classList.add('hidden');
            } else if (justSubscribed === false && Notification.permission === 'granted') {
                // Permission is granted but the server-side subscribe
                // failed — the toast from metasoftEnablePush() already
                // explained this; keep the retry button visible.
                text.textContent = 'নোটিফিকেশন চালু করা যায়নি। আবার চেষ্টা করুন।';
                btn.classList.remove('hidden');
            } else if (Notification.permission === 'denied') {
                text.textContent = 'নোটিফিকেশন বন্ধ করা আছে — ব্রাউজারের সেটিংস থেকে চালু করুন।';
                btn.classList.add('hidden');
            } else {
                text.textContent = 'নতুন অর্ডার ও মেসেজের নোটিফিকেশন পেতে চালু করুন।';
                btn.classList.remove('hidden');
            }
        }

        btn.addEventListener('click', async () => {
            const ok = window.metasoftEnablePush ? await window.metasoftEnablePush() : false;
            render(ok);
        });

        render();
    })();
</script>
@endpush
@endsection
