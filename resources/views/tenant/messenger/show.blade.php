@extends('layouts.panel')
@section('title', $customer->customer_name ?: 'কথোপকথন')
@section('content')
<div class="flex items-center justify-between mb-6">
    <div>
        <h1 class="font-disp font-bold text-2xl">{{ $customer->customer_name ?: 'অজানা কাস্টমার' }}</h1>
        <p class="text-xs text-mute">Messenger PSID: {{ $psid }}</p>
    </div>
    <a href="{{ route('tenant.messenger.index') }}" class="text-sm text-mute hover:text-ink rounded focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-leaf focus-visible:ring-offset-2">← ইনবক্সে ফিরুন</a>
</div>

<div class="grid lg:grid-cols-3 gap-6">
    <div class="lg:col-span-2">
        <x-ui.card class="space-y-4 max-h-[500px] overflow-y-auto">
            @foreach ($messages as $m)
                <div class="flex {{ $m->direction === 'out' ? 'justify-end' : 'justify-start' }}">
                    <div class="max-w-md {{ $m->direction === 'out' ? 'bg-leaf text-white' : 'bg-paper text-ink' }} rounded-card px-4 py-2.5 text-sm">
                        @if ($m->message_text){{ $m->message_text }}@endif
                        @if ($m->attachment_url)
                            <a href="{{ $m->attachment_url }}" target="_blank" class="block underline text-xs mt-1">📎 এটাচমেন্ট দেখুন</a>
                        @endif
                        <p class="text-[10px] mt-1 opacity-70">{{ $m->created_at?->format('d M, h:i A') }}</p>
                    </div>
                </div>
            @endforeach
        </x-ui.card>

        <form method="POST" action="{{ route('tenant.messenger.reply', $psid) }}" class="flex gap-2 mt-4">
            @csrf
            <input name="message" required placeholder="রিপ্লাই লিখুন..." class="flex-1 rounded-btn border border-ink/15 px-3 py-2.5 text-sm focus:ring-2 focus:ring-leaf outline-none">
            <x-ui.button type="submit" variant="accent" size="sm">পাঠান</x-ui.button>
        </form>
    </div>

    <div class="space-y-4">
        <x-ui.card padding="sm">
            <p class="font-bold text-sm mb-3">স্ট্যাটাস</p>
            <form method="POST" action="{{ route('tenant.messenger.status', $psid) }}">
                @csrf
                <select name="status" onchange="this.form.submit()" class="w-full rounded-btn border border-ink/15 px-3 py-2.5 text-sm bg-white">
                    @foreach (['new'=>'নতুন','contacted'=>'যোগাযোগ হয়েছে','converted'=>'অর্ডারে রূপান্তরিত','ignored'=>'বাদ'] as $k=>$v)
                        <option value="{{ $k }}" @selected($customer->status === $k)>{{ $v }}</option>
                    @endforeach
                </select>
            </form>
        </x-ui.card>

        <x-ui.card padding="sm">
            <p class="font-bold text-sm mb-2">🧾 অর্ডারে রূপান্তর করুন</p>
            <p class="text-xs text-mute mb-3">নাম প্রি-ফিল হয়ে যাবে, ফোন নাম্বার ও প্রোডাক্ট বাছাই করে দিন</p>
            <a href="{{ route('tenant.orders.create', ['name' => $customer->customer_name, 'channel' => 'facebook']) }}"
               class="block text-center py-2.5 rounded-btn bg-ink text-white font-semibold text-sm hover:bg-ink/90 transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-leaf focus-visible:ring-offset-2">নতুন অর্ডার তৈরি করুন</a>
        </x-ui.card>
    </div>
</div>
@endsection
