@extends('layouts.panel')

@section('title', 'WordPress কানেক্ট')

@section('content')
<h1 class="font-disp font-bold text-2xl mb-2">WordPress কানেক্ট</h1>
<p class="text-sm text-mute mb-6">আপনার WordPress ওয়েবসাইট কানেক্ট করুন — এরপর প্রোডাক্ট, অর্ডার ও কাস্টমার MetaSoftSAS প্যানেল থেকেই ম্যানেজ করতে পারবেন।</p>

@if ($notReady)
    <x-ui.card>
        <p class="text-sm text-mute">WordPress ইন্টিগ্রেশন এখনো প্রস্তুত হয়নি — একটু পর আবার চেষ্টা করুন।</p>
    </x-ui.card>
@else
    <div class="max-w-2xl space-y-6">
        {{-- Connection status --}}
        <x-ui.card padding="sm">
            <div class="flex items-center justify-between gap-4">
                <div>
                    <p class="font-semibold">
                        @if ($connection && $connection->isConnected())
                            {{ $connection->site_name ?? $connection->site_url }}
                        @else
                            কোনো সাইট কানেক্ট করা নেই
                        @endif
                    </p>
                    @if ($connection)
                        <p class="text-xs text-mute mt-0.5">{{ $connection->site_url }}</p>
                        @if ($connection->last_verified_at)
                            <p class="text-xs text-mute mt-0.5">সর্বশেষ যাচাই: {{ $connection->last_verified_at->diffForHumans() }}</p>
                        @endif
                    @endif
                </div>

                @if ($connection && $connection->isConnected())
                    <x-ui.badge tone="leaf">✅ কানেক্টেড</x-ui.badge>
                @elseif ($connection && $connection->status === 'needs_reconnect')
                    <x-ui.badge tone="amber">⚠️ পুনরায় কানেক্ট করুন</x-ui.badge>
                @else
                    <x-ui.badge tone="white">কানেক্ট নেই</x-ui.badge>
                @endif
            </div>

            @if ($connection && $connection->isConnected())
                <div class="flex gap-3 mt-4">
                    <form method="POST" action="{{ route('tenant.wordpress.verify') }}">
                        @csrf
                        <x-ui.button type="submit" variant="outline" size="sm">সংযোগ যাচাই করুন</x-ui.button>
                    </form>
                    <form method="POST" action="{{ route('tenant.wordpress.disconnect') }}"
                          onsubmit="return confirm('সত্যিই ডিসকানেক্ট করতে চান?');">
                        @csrf
                        <x-ui.button type="submit" variant="outline" size="sm" class="!text-red-600 !border-red-200">ডিসকানেক্ট করুন</x-ui.button>
                    </form>
                </div>
            @endif
        </x-ui.card>

        {{-- Step-by-step connect instructions --}}
        <x-ui.card padding="sm">
            <p class="font-semibold mb-3">{{ $connection && $connection->isConnected() ? 'পুনরায় কানেক্ট করুন' : 'কীভাবে কানেক্ট করবেন' }}</p>

            <ol class="text-sm text-mute space-y-2 list-decimal list-inside mb-4">
                <li>নিচের বাটনে ক্লিক করে <strong>MetaSoft Connector</strong> প্লাগইন ডাউনলোড করুন এবং আপনার WordPress সাইটে ইনস্টল ও অ্যাক্টিভেট করুন।</li>
                <li>একটি কানেকশন কী (Key) তৈরি করুন — এটি ৩০ মিনিটের জন্য বৈধ থাকবে।</li>
                <li>WordPress অ্যাডমিন প্যানেলে <strong>Settings → MetaSoft Connector</strong>-এ গিয়ে কী-টি পেস্ট করে "Connect to MetaSoftSAS" ক্লিক করুন।</li>
            </ol>

            <div class="flex flex-wrap gap-3 mb-4">
                <x-ui.button href="{{ route('tenant.wordpress.plugin-download') }}" variant="outline" size="sm">
                    প্লাগইন ডাউনলোড করুন
                </x-ui.button>

                <form method="POST" action="{{ route('tenant.wordpress.generate-key') }}">
                    @csrf
                    <x-ui.button type="submit" variant="accent" size="sm">কানেকশন কী তৈরি করুন</x-ui.button>
                </form>
            </div>

            @if (session('connection_key'))
                <div class="bg-ink/5 rounded-btn p-4">
                    <p class="text-xs text-mute mb-1">আপনার কানেকশন কী (এই পেজ ছাড়লে আর দেখা যাবে না):</p>
                    <code class="block text-sm font-mono break-all select-all">{{ session('connection_key') }}</code>
                    <p class="text-xs text-mute mt-2">মেয়াদ শেষ: {{ \Illuminate\Support\Carbon::parse(session('connection_key_expires_at'))->format('h:i A, d M') }}</p>
                </div>
            @endif
        </x-ui.card>
    </div>
@endif
@endsection
