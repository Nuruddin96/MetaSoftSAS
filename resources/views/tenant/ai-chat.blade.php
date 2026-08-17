@extends('layouts.panel')

@section('title', 'Personal Assistant')

@section('content')
<div class="max-w-3xl mx-auto">
    <div class="flex items-center justify-between mb-4">
        <div>
            <h1 class="font-disp font-bold text-xl">🤖 Personal Assistant</h1>
            <p class="text-xs text-mute mt-0.5">আপনার অর্ডার, প্রোডাক্ট, কাস্টমার ও সেলস সম্পর্কে জিজ্ঞেস করুন।</p>
        </div>
        <div class="text-right text-xs">
            <p class="text-mute">ক্রেডিট ব্যালেন্স</p>
            @if (is_null($aiCreditBalance))
                <p class="font-semibold text-mute">বরাদ্দ করা হয়নি</p>
            @elseif ((float) $aiCreditBalance <= 0)
                <p class="font-semibold text-red-600">০ (শেষ)</p>
            @else
                <p class="font-semibold text-leafdk">{{ number_format((float) $aiCreditBalance, 2) }}</p>
            @endif
        </div>
    </div>

    @if (! $aiAgentEnabled)
        <div class="rounded-xl border border-amber-200 bg-amber-50 text-amber-800 p-4 text-sm mb-4">
            Personal Assistant বন্ধ আছে। <a href="{{ route('tenant.settings') }}" class="font-semibold underline">Settings</a> থেকে "Personal Assistant চালু" টিক দিন।
        </div>
    @elseif (is_null($aiCreditBalance) || (float) $aiCreditBalance <= 0)
        <div class="rounded-xl border border-red-200 bg-red-50 text-red-700 p-4 text-sm mb-4">
            AI ক্রেডিট শেষ হয়ে গেছে (অথবা এখনো বরাদ্দ হয়নি)। নতুন ক্রেডিটের জন্য সুপার অ্যাডমিনের সাথে যোগাযোগ করুন।
        </div>
    @endif

    @if (session('error'))
        <div class="rounded-xl border border-red-200 bg-red-50 text-red-700 p-3 text-sm mb-4">{{ session('error') }}</div>
    @endif

    <div class="bg-white rounded-xl border border-ink/5 flex flex-col" style="height: 60vh;">
        <div id="aiChatMessages" class="flex-1 overflow-y-auto p-4 space-y-3">
            @forelse ($messages as $m)
                <div class="flex {{ $m->role === 'user' ? 'justify-end' : 'justify-start' }}">
                    <div class="max-w-[85%] break-words {{ $m->role === 'user' ? 'bg-leaf text-white' : 'bg-paper text-ink' }} rounded-card px-4 py-2.5 text-sm whitespace-pre-wrap">
                        {{ $m->content }}

                        {{-- HIGH RISK confirmation prompt — only shown while
                             the linked AiPendingAction is still awaiting a
                             decision. Confirming/rejecting is what actually
                             triggers (or permanently skips) the mutation —
                             see Tenant\AiChatController::confirm()/reject(). --}}
                        @if ($m->pendingAction && $m->pendingAction->status === 'pending' && ! $m->pendingAction->isExpired())
                            <div class="mt-3 pt-3 border-t border-ink/10 flex gap-2">
                                <form method="POST" action="{{ route('tenant.ai-chat.actions.confirm', $m->pendingAction) }}">
                                    @csrf
                                    <button type="submit" class="text-xs font-semibold bg-leaf text-white px-3 py-1.5 rounded-pill hover:bg-leafdk transition">✅ নিশ্চিত করুন</button>
                                </form>
                                <form method="POST" action="{{ route('tenant.ai-chat.actions.reject', $m->pendingAction) }}">
                                    @csrf
                                    <button type="submit" class="text-xs font-semibold bg-ink/10 text-ink px-3 py-1.5 rounded-pill hover:bg-ink/15 transition">❌ বাতিল</button>
                                </form>
                            </div>
                        @elseif ($m->pendingAction && $m->pendingAction->status === 'pending' && $m->pendingAction->isExpired())
                            <p class="mt-2 text-xs text-mute">⏱️ কনফার্মেশনের মেয়াদ শেষ হয়ে গেছে।</p>
                        @endif
                    </div>
                </div>
            @empty
                <p class="text-center text-mute text-sm py-10">এখনো কোনো কথোপকথন নেই — নিচে একটি প্রশ্ন লিখে শুরু করুন।<br>যেমন: "আজকের সেলস কেমন?" অথবা "ORD-000123 অর্ডারের স্ট্যাটাস কী?"</p>
            @endforelse
        </div>

        <form id="aiChatForm" method="POST" action="{{ route('tenant.ai-chat.send') }}" class="border-t border-ink/5 p-3 flex gap-2">
            @csrf
            <input type="text" name="message" required maxlength="2000" autocomplete="off"
                   placeholder="{{ $aiAgentEnabled ? 'একটি প্রশ্ন লিখুন...' : 'Personal Assistant বন্ধ আছে' }}"
                   {{ $aiAgentEnabled ? '' : 'disabled' }}
                   class="flex-1 rounded-btn border border-ink/15 px-3.5 py-2.5 text-sm focus:ring-2 focus:ring-leaf outline-none disabled:bg-ink/5 disabled:text-mute">
            <x-ui.button type="submit" variant="accent" size="sm" :disabled="! $aiAgentEnabled">পাঠান</x-ui.button>
        </form>
    </div>
</div>

@push('scripts')
<script>
(function () {
    const messages = document.getElementById('aiChatMessages');
    if (messages) messages.scrollTop = messages.scrollHeight;

    const form = document.getElementById('aiChatForm');
    if (!form) return;

    // No AJAX/streaming yet — a normal form submit, just a plain "sending..."
    // state on the button so a multi-second (possibly multi-tool-call) AI
    // reply doesn't look like the page is frozen or the click missed.
    form.addEventListener('submit', function () {
        const btn = form.querySelector('button[type="submit"]');
        if (btn) {
            btn.disabled = true;
            btn.textContent = 'পাঠানো হচ্ছে...';
        }
    });
})();
</script>
@endpush
@endsection
