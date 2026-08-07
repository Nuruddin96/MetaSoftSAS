@extends('layouts.panel')
@section('title', 'মেসেঞ্জার ইনবক্স')
@section('content')
<h1 class="font-disp font-bold text-2xl mb-2">📩 মেসেঞ্জার ইনবক্স</h1>

@if (! $connected)
    <div class="bg-amber/15 border border-amber/40 rounded-xl p-5 text-sm mb-6">
        এখনো কোনো Facebook Page কানেক্ট করা হয়নি। <a href="{{ route('tenant.settings') }}" class="text-leafdk font-semibold hover:underline">সেটিংসে গিয়ে কানেক্ট করুন</a>।
    </div>
@endif

<div class="bg-white rounded-xl border border-ink/5 divide-y divide-ink/5">
    @forelse ($conversations as $c)
        <a href="{{ route('tenant.messenger.show', $c->sender_psid) }}"
           class="flex items-center justify-between px-5 py-4 hover:bg-paper/60 {{ $c->status === 'new' ? 'bg-leaf/5' : '' }}">
            <div class="min-w-0 flex-1">
                <div class="flex items-center gap-2">
                    <p class="font-medium">{{ $c->customer_name ?: 'অজানা কাস্টমার' }}</p>
                    @if ($c->status === 'new')<span class="w-2 h-2 rounded-full bg-leaf"></span>@endif
                </div>
                <p class="text-sm text-mute truncate max-w-md">
                    {{ $c->direction === 'out' ? 'আপনি: ' : '' }}{{ $c->message_text ?: '📎 ছবি/ফাইল পাঠিয়েছে' }}
                </p>
            </div>
            <div class="text-right shrink-0 ml-3">
                <p class="text-xs text-mute">{{ $c->created_at?->diffForHumans() }}</p>
                <span class="text-xs px-2 py-0.5 rounded {{ ['new'=>'bg-leaf/10 text-leafdk','contacted'=>'bg-amber/15','converted'=>'bg-ink/5 text-mute','ignored'=>'bg-red-50 text-red-600'][$c->status] ?? '' }}">
                    {{ ['new'=>'নতুন','contacted'=>'যোগাযোগ হয়েছে','converted'=>'অর্ডারে রূপান্তরিত','ignored'=>'বাদ'][$c->status] ?? $c->status }}
                </span>
            </div>
        </a>
    @empty
        <p class="px-5 py-16 text-center text-mute text-sm">এখনো কোনো মেসেজ আসেনি। Facebook Page-এ কাস্টমার মেসেজ করলে এখানে দেখা যাবে।</p>
    @endforelse
</div>
<div class="mt-4">{{ $conversations->links() }}</div>
@endsection
