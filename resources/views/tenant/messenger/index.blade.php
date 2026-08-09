@extends('layouts.panel')
@section('title', 'মেসেঞ্জার ইনবক্স')
@section('content')
<h1 class="font-disp font-bold text-2xl mb-2">📩 মেসেঞ্জার ইনবক্স</h1>

@if (! $connected)
    <x-ui.card tone="amber" padding="sm" class="text-sm mb-6">
        এখনো কোনো Facebook Page কানেক্ট করা হয়নি। <a href="{{ route('tenant.settings') }}" class="text-leafdk font-semibold hover:underline">সেটিংসে গিয়ে কানেক্ট করুন</a>।
    </x-ui.card>
@endif

<x-ui.card id="conversationList" padding="none" class="divide-y divide-ink/5"
    data-after-id="{{ $conversations->max('id') ?? 0 }}"
    data-show-url-template="{{ route('tenant.messenger.show', '__PSID__') }}">
    @forelse ($conversations as $c)
        <a id="conv-{{ $c->sender_psid }}" href="{{ route('tenant.messenger.show', $c->sender_psid) }}"
           class="conv-row flex items-center justify-between px-5 py-4 hover:bg-paper/60 transition {{ $c->status === 'new' ? 'bg-leaf/5' : '' }}">
            <div class="min-w-0 flex-1">
                <div class="flex items-center gap-2">
                    <p class="conv-name font-medium">{{ $c->customer_name ?: 'অজানা কাস্টমার' }}</p>
                    <span class="conv-dot w-2 h-2 rounded-full bg-leaf {{ $c->status === 'new' ? '' : 'hidden' }}"></span>
                </div>
                <p class="conv-msg text-sm text-mute truncate max-w-md">
                    {{ $c->direction === 'out' ? 'আপনি: ' : '' }}{{ $c->message_text ?: '📎 ছবি/ফাইল পাঠিয়েছে' }}
                </p>
            </div>
            <div class="text-right shrink-0 ml-3">
                <p class="conv-time text-xs text-mute">{{ $c->created_at?->diffForHumans() }}</p>
                <span class="conv-status text-xs px-2.5 py-1 rounded-pill font-semibold {{ ['new'=>'bg-leaf/10 text-leafdk','contacted'=>'bg-amber/15 text-ink','converted'=>'bg-ink/5 text-mute','ignored'=>'bg-red-50 text-red-600'][$c->status] ?? '' }}">
                    {{ ['new'=>'নতুন','contacted'=>'যোগাযোগ হয়েছে','converted'=>'অর্ডারে রূপান্তরিত','ignored'=>'বাদ'][$c->status] ?? $c->status }}
                </span>
            </div>
        </a>
    @empty
        <div id="emptyState" class="px-5 py-16 text-center text-mute text-sm">
            <i data-lucide="message-circle" class="w-8 h-8 mx-auto mb-3 text-mute/40"></i>
            এখনো কোনো মেসেজ আসেনি। Facebook Page-এ কাস্টমার মেসেজ করলে এখানে দেখা যাবে।
        </div>
    @endforelse
</x-ui.card>
<div class="mt-4">{{ $conversations->links() }}</div>

@push('scripts')
<script>
(function () {
    const container = document.getElementById('conversationList');
    if (!container) return;

    let afterId = parseInt(container.dataset.afterId || '0', 10);
    const showUrlTemplate = container.dataset.showUrlTemplate;

    const statusLabel = { new: 'নতুন', contacted: 'যোগাযোগ হয়েছে', converted: 'অর্ডারে রূপান্তরিত', ignored: 'বাদ' };
    const statusClass = { new: 'bg-leaf/10 text-leafdk', contacted: 'bg-amber/15 text-ink', converted: 'bg-ink/5 text-mute', ignored: 'bg-red-50 text-red-600' };

    function upsertRow(c) {
        let row = document.getElementById('conv-' + c.psid);

        if (!row) {
            document.getElementById('emptyState')?.remove();
            row = document.createElement('a');
            row.id = 'conv-' + c.psid;
            row.href = showUrlTemplate.replace('__PSID__', c.psid);
            row.innerHTML = `
                <div class="min-w-0 flex-1">
                    <div class="flex items-center gap-2">
                        <p class="conv-name font-medium"></p>
                        <span class="conv-dot w-2 h-2 rounded-full bg-leaf"></span>
                    </div>
                    <p class="conv-msg text-sm text-mute truncate max-w-md"></p>
                </div>
                <div class="text-right shrink-0 ml-3">
                    <p class="conv-time text-xs text-mute"></p>
                    <span class="conv-status text-xs px-2.5 py-1 rounded-pill font-semibold"></span>
                </div>`;
        }

        row.className = 'conv-row flex items-center justify-between px-5 py-4 hover:bg-paper/60 transition' + (c.status === 'new' ? ' bg-leaf/5' : '');
        row.querySelector('.conv-name').textContent = c.customer_name || 'অজানা কাস্টমার';
        row.querySelector('.conv-msg').textContent = (c.direction === 'out' ? 'আপনি: ' : '') + (c.message_text || '📎 ছবি/ফাইল পাঠিয়েছে');
        row.querySelector('.conv-time').textContent = c.time_label;
        row.querySelector('.conv-dot').classList.toggle('hidden', c.status !== 'new');
        const statusEl = row.querySelector('.conv-status');
        statusEl.textContent = statusLabel[c.status] || c.status;
        statusEl.className = 'conv-status text-xs px-2.5 py-1 rounded-pill font-semibold ' + (statusClass[c.status] || '');

        container.prepend(row);
    }

    async function poll() {
        try {
            const res = await fetch(`{{ route('tenant.messenger.updates') }}?after_id=${afterId}`, { headers: { Accept: 'application/json' } });
            if (!res.ok) return;
            const data = await res.json();
            afterId = data.latest_id;
            (data.conversations || []).forEach(upsertRow);
        } catch (e) {
            // silent — next tick tries again
        }
    }

    setInterval(poll, 5000);
})();
</script>
@endpush
@endsection
