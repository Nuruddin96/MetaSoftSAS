{{--
    Center (thread + reply composer) + right (customer info, hidden by
    default) for one Messenger conversation — the Messenger counterpart to
    tenant/whatsapp/_conversation.blade.php; see that file's docblock for
    the swap/polling mechanics, header/drawer rationale, and why no customer
    identity repeats in this file.
--}}
<div id="conversationArea" class="flex flex-col h-[calc(100dvh-204px)] lg:h-[calc(100vh-140px)] border border-ink/10 rounded-card overflow-hidden max-w-full"
     data-conversation-key="messenger:{{ $psid }}" data-updates-url="{{ route('tenant.messenger.updates') }}"
     data-external-id="{{ $psid }}" data-channel="messenger">

    <div class="shrink-0 flex items-center justify-between gap-2 px-3 py-2.5 border-b border-ink/10 bg-white rounded-t-card">
        <div class="flex items-center gap-2.5 min-w-0">
            <a href="{{ route('tenant.inbox') }}" class="lg:hidden shrink-0 w-8 h-8 -ml-1 grid place-items-center rounded-full text-mute hover:bg-paper focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-leaf focus-visible:ring-offset-2" aria-label="তালিকায় ফিরুন">
                <i data-lucide="arrow-left" class="w-5 h-5"></i>
            </a>
            <x-ui.avatar :name="$customer->customer_name" :url="$customer->profile_pic_url ?? null" size="sm" />
            <div class="min-w-0">
                <p class="font-bold text-sm truncate leading-tight">{{ $customer->customer_name ?: 'অজানা কাস্টমার' }}</p>
                <p class="text-[11px] text-mute truncate leading-tight">🔵 Messenger</p>
            </div>
        </div>
        <button type="button" class="conv-info-toggle shrink-0 w-8 h-8 rounded-full hover:bg-paper grid place-items-center text-mute focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-leaf focus-visible:ring-offset-2" title="কাস্টমার তথ্য" aria-label="কাস্টমার তথ্য">
            <i data-lucide="info" class="w-[18px] h-[18px]"></i>
        </button>
    </div>

    <div class="flex-1 min-h-0 bg-white">
        @include('tenant.messenger._thread', ['messages' => $messages, 'fillHeight' => true])
    </div>

    <form method="POST" action="{{ route('tenant.messenger.reply', $psid) }}" enctype="multipart/form-data" class="shrink-0 border-t border-ink/10 bg-white p-2.5 rounded-b-card">
        @csrf
        <div class="flex gap-2 items-center">
            <label class="shrink-0 flex items-center justify-center w-10 h-10 rounded-full border border-ink/15 cursor-pointer hover:bg-paper transition" title="ছবি যুক্ত করুন">
                <i data-lucide="paperclip" class="w-[18px] h-[18px] text-mute"></i>
                <input type="file" name="image" accept="image/*" class="hidden" onchange="document.getElementById('imgFileName').textContent = this.files[0] ? '📎 ' + this.files[0].name : ''">
            </label>
            @include('tenant.inbox._voice_recorder', ['id' => 'msg'])
            <input name="message" placeholder="মেসেজ লিখুন..." class="flex-1 rounded-pill border border-ink/15 px-4 py-2.5 text-sm focus:ring-2 focus:ring-leaf outline-none">
            <button type="submit" class="shrink-0 w-10 h-10 rounded-full bg-leaf text-white grid place-items-center hover:bg-leafdk transition" title="পাঠান" aria-label="পাঠান">
                <i data-lucide="send" class="w-[18px] h-[18px]"></i>
            </button>
        </div>
        <p id="imgFileName" class="text-xs text-mute mt-1 px-1 truncate"></p>
    </form>

    <div class="conv-info-drawer hidden fixed inset-0 z-40">
        <div class="conv-info-backdrop absolute inset-0 bg-black/30"></div>
        <div class="absolute right-0 top-0 bottom-0 w-full max-w-sm bg-white shadow-xl overflow-y-auto p-4">
            <div class="flex items-center justify-between mb-4">
                <p class="font-bold text-sm">কাস্টমার তথ্য</p>
                <button type="button" class="conv-info-close w-8 h-8 rounded-full hover:bg-paper grid place-items-center text-mute" aria-label="বন্ধ করুন">
                    <i data-lucide="x" class="w-[18px] h-[18px]"></i>
                </button>
            </div>
            @include('tenant.inbox._customer_panel', [
                'channel' => 'messenger',
                'externalId' => $psid,
                'customerName' => $customer->customer_name,
                'statusValue' => $customer->status,
                'statusUpdateUrl' => route('tenant.messenger.status', $psid),
                'linkedOrder' => $linkedOrder,
                'matchedCustomer' => $matchedCustomer,
                'newOrderCreateUrl' => route('tenant.orders.create', ['name' => $customer->customer_name, 'channel' => 'facebook']),
                'handoffActive' => $handoffActive ?? false,
                'resumeAiUrl' => route('tenant.messenger.resume-ai', $psid),
            ])
        </div>
    </div>
</div>
