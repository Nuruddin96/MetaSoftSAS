{{--
    Center (thread + reply composer) + right (customer info) panes for one
    Messenger conversation — the Messenger counterpart to
    tenant/whatsapp/_conversation.blade.php; see that file's docblock for
    the swap/polling mechanics this root's data-* attributes feed.
--}}
<div id="conversationArea" data-conversation-key="messenger:{{ $psid }}" data-updates-url="{{ route('tenant.messenger.updates') }}" data-external-id="{{ $psid }}" data-channel="messenger">
    <div class="flex items-center justify-between mb-4">
        <div class="flex items-center gap-3 min-w-0">
            <x-ui.avatar :name="$customer->customer_name" size="sm" class="lg:hidden" />
            <div class="min-w-0">
                <h1 class="font-disp font-bold text-lg truncate">{{ $customer->customer_name ?: 'অজানা কাস্টমার' }}</h1>
                <p class="text-xs text-mute">Messenger PSID: {{ $psid }}</p>
            </div>
        </div>
        <a href="{{ route('tenant.inbox') }}" class="lg:hidden text-sm text-mute hover:text-ink shrink-0 rounded focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-leaf focus-visible:ring-offset-2">← তালিকা</a>
    </div>

    <div class="grid lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2">
            <x-ui.card>
                @include('tenant.messenger._thread', ['messages' => $messages])
            </x-ui.card>

            <form method="POST" action="{{ route('tenant.messenger.reply', $psid) }}" enctype="multipart/form-data" class="mt-4 space-y-1.5">
                @csrf
                <div class="flex gap-2">
                    <input name="message" placeholder="রিপ্লাই লিখুন..." class="flex-1 rounded-btn border border-ink/15 px-3 py-2.5 text-sm focus:ring-2 focus:ring-leaf outline-none">
                    <label class="shrink-0 flex items-center justify-center w-10 h-10 rounded-btn border border-ink/15 cursor-pointer hover:bg-paper transition" title="ছবি যুক্ত করুন">
                        🖼️
                        <input type="file" name="image" accept="image/*" class="hidden" onchange="document.getElementById('imgFileName').textContent = this.files[0] ? '📎 ' + this.files[0].name : ''">
                    </label>
                    <x-ui.button type="submit" variant="accent" size="sm">পাঠান</x-ui.button>
                </div>
                <p id="imgFileName" class="text-xs text-mute"></p>
            </form>
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
        ])
    </div>
</div>
