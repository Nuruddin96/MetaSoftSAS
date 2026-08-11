{{--
    Center (thread + reply composer) + right (customer info, hidden by
    default) for one WhatsApp conversation — the part that gets swapped in
    place when a conversation row is clicked (WhatsAppInboxController::
    show()'s ?panel=1 branch returns just this), and also included as-is
    inside the full page (whatsapp/show.blade.php). Root node carries the
    data-* attributes the shell's JS reads after a swap to (re)start
    polling for the right conversation.

    Single compact header (avatar + name + phone + channel) — customer
    identity does NOT repeat inside this file; the customer-info panel
    (which used to render its own avatar+name unconditionally alongside
    this header) now only appears in the off-canvas drawer, opened via the
    info icon, not in the default chat view. Height is bounded so the
    composer stays pinned at the bottom of THIS panel specifically (flex
    column: header/shrink-0, thread/flex-1, composer/shrink-0) rather than
    flowing down the page past the viewport, per the chat-UI refinement.
--}}
<div id="conversationArea" class="flex flex-col h-[calc(100dvh-204px)] lg:h-[calc(100vh-140px)] border border-ink/10 rounded-card overflow-hidden max-w-full"
     data-conversation-key="whatsapp:{{ $waId }}" data-updates-url="{{ route('tenant.whatsapp.updates') }}"
     data-external-id="{{ $waId }}" data-channel="whatsapp">

    <div class="shrink-0 flex items-center justify-between gap-2 px-3 py-2.5 border-b border-ink/10 bg-white rounded-t-card">
        <div class="flex items-center gap-2.5 min-w-0">
            <a href="{{ route('tenant.inbox') }}" class="lg:hidden shrink-0 w-8 h-8 -ml-1 grid place-items-center rounded-full text-mute hover:bg-paper focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-leaf focus-visible:ring-offset-2" aria-label="তালিকায় ফিরুন">
                <i data-lucide="arrow-left" class="w-5 h-5"></i>
            </a>
            <x-ui.avatar :name="$customer->customer_name" size="sm" />
            <div class="min-w-0">
                <p class="font-bold text-sm truncate leading-tight">{{ $customer->customer_name ?: 'অজানা কাস্টমার' }}</p>
                <p class="text-[11px] text-mute truncate leading-tight">🟢 WhatsApp{{ $waId ? ' • '.$waId : '' }}</p>
            </div>
        </div>
        <button type="button" class="conv-info-toggle shrink-0 w-8 h-8 rounded-full hover:bg-paper grid place-items-center text-mute focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-leaf focus-visible:ring-offset-2" title="কাস্টমার তথ্য" aria-label="কাস্টমার তথ্য">
            <i data-lucide="info" class="w-[18px] h-[18px]"></i>
        </button>
    </div>

    @unless ($connected)
        <p class="shrink-0 px-3 py-1.5 bg-amber/10 text-[11px] text-ink border-b border-ink/5">
            এই নম্বরটি ডিসকানেক্টেড — রিপ্লাই পাঠানো যাবে না। <a href="{{ route('tenant.settings') }}" class="font-semibold hover:underline">আবার কানেক্ট করুন</a>
        </p>
    @endunless

    <div class="flex-1 min-h-0 bg-white">
        @include('tenant.whatsapp._thread', ['messages' => $messages])
    </div>

    <form method="POST" action="{{ route('tenant.whatsapp.reply', $waId) }}" enctype="multipart/form-data" class="shrink-0 border-t border-ink/10 bg-white p-2.5 rounded-b-card">
        @csrf
        <div class="flex gap-2 items-center">
            <label class="shrink-0 flex items-center justify-center w-10 h-10 rounded-full border border-ink/15 cursor-pointer hover:bg-paper transition" title="ছবি যুক্ত করুন">
                <i data-lucide="paperclip" class="w-[18px] h-[18px] text-mute"></i>
                <input type="file" name="image" accept="image/*" class="hidden" onchange="document.getElementById('waImgFileName').textContent = this.files[0] ? '📎 ' + this.files[0].name : ''">
            </label>
            <input name="message" placeholder="মেসেজ লিখুন..." class="flex-1 rounded-pill border border-ink/15 px-4 py-2.5 text-sm focus:ring-2 focus:ring-leaf outline-none">
            <button type="submit" class="shrink-0 w-10 h-10 rounded-full bg-leaf text-white grid place-items-center hover:bg-leafdk transition" title="পাঠান" aria-label="পাঠান">
                <i data-lucide="send" class="w-[18px] h-[18px]"></i>
            </button>
        </div>
        <p id="waImgFileName" class="text-xs text-mute mt-1 px-1 truncate"></p>
    </form>

    {{-- Customer info drawer — hidden by default, opened via the header's
         info icon (see _shell_scripts.blade.php's delegated click handler).
         Nested inside #conversationArea (not a page-level sibling) so it's
         included automatically whenever this partial is swapped in, and
         always reflects the currently-open conversation rather than a
         previous one left stale in the DOM. --}}
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
                'channel' => 'whatsapp',
                'externalId' => $waId,
                'customerName' => $customer->customer_name,
                'statusValue' => $customer->status,
                'statusUpdateUrl' => route('tenant.whatsapp.status', $waId),
                'linkedOrder' => $linkedOrder,
                'matchedCustomer' => $matchedCustomer,
                'newOrderCreateUrl' => route('tenant.orders.create', ['name' => $customer->customer_name, 'channel' => 'whatsapp']),
            ])
        </div>
    </div>
</div>
