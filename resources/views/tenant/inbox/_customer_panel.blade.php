{{--
    Customer-info drawer content: linked order/customer history and the
    (de-emphasized but fully functional) triage status control — shared by
    whatsapp/_conversation.blade.php and messenger/_conversation.blade.php,
    which both render this inside an off-canvas drawer (hidden by default,
    opened via the chat header's info icon — see _shell_scripts.blade.php's
    delegated click handler). Nothing here changes what updateStatus()/the
    order-linking queries actually do — presentation only.

    Deliberately does NOT repeat an avatar+name header of its own — the
    drawer wrapper already has a "কাস্টমার তথ্য" title, and the chat header
    (part of _conversation.blade.php, still visible behind/beside the
    drawer) already shows avatar+name+phone/channel once. Repeating it here
    was the "customer name shown 2-3 times" bug this refinement fixes.

    Expected vars:
      $channel          'whatsapp'|'messenger'
      $externalId        wa_id or psid
      $customerName
      $statusValue        current status enum value for this conversation
      $statusUpdateUrl    route('tenant.whatsapp.status'|'tenant.messenger.status', $externalId)
      $linkedOrder        ?Order
      $matchedCustomer    ?Customer
      $newOrderCreateUrl  route('tenant.orders.create', [...]) prefilled for this channel
      $handoffActive      bool — Phase 13, whether AiHandoffService::isActive() is true for this conversation
      $resumeAiUrl        route('tenant.whatsapp.resume-ai'|'tenant.messenger.resume-ai', $externalId)
--}}
@php
    $isWhatsapp = $channel === 'whatsapp';
    $statusLabels = ['new' => 'নতুন', 'contacted' => 'যোগাযোগ হয়েছে', 'converted' => 'অর্ডারে রূপান্তরিত', 'ignored' => 'বাদ'];
    $orderStatusLabel = ['pending' => 'পেন্ডিং', 'confirmed' => 'কনফার্মড', 'processing' => 'প্রসেসিং', 'shipped' => 'শিপড', 'delivered' => 'ডেলিভারড', 'cancelled' => 'বাতিল', 'returned' => 'রিটার্ন'];
@endphp
<div class="space-y-4">
    @if ($handoffActive ?? false)
        <x-ui.card padding="sm" tone="amber">
            <p class="font-bold text-sm mb-1">🙋 মানুষের কাছে হস্তান্তরিত</p>
            <p class="text-xs text-mute mb-3">কাস্টমার একজন মানুষের সাথে কথা বলতে চেয়েছেন — Personal Assistant এই কনভারসেশনে আর নিজে থেকে রিপ্লাই দিচ্ছে না, যতক্ষণ না আপনি নিচের বাটনে ক্লিক করেন।</p>
            <form method="POST" action="{{ $resumeAiUrl }}">
                @csrf
                <button type="submit" class="w-full text-center py-2.5 rounded-btn bg-leaf text-white font-semibold text-sm hover:bg-leafdk transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-leaf focus-visible:ring-offset-2">Personal Assistant আবার চালু করুন</button>
            </form>
        </x-ui.card>
    @endif

    <x-ui.card padding="sm">
        <p class="font-bold text-sm mb-2">যোগাযোগ</p>
        <div class="text-xs text-mute space-y-1">
            @if ($isWhatsapp)
                <p>📞 <a href="tel:{{ $externalId }}" class="hover:underline">{{ $externalId }}</a></p>
            @else
                <p>PSID: {{ $externalId }}</p>
            @endif
        </div>
    </x-ui.card>

    @if ($matchedCustomer)
        <x-ui.card padding="sm">
            <p class="font-bold text-sm mb-2">👤 কাস্টমার তথ্য</p>
            <div class="grid grid-cols-3 gap-2 text-center mb-2">
                <div>
                    <p class="text-sm font-bold">{{ $matchedCustomer->total_orders ?? 0 }}</p>
                    <p class="text-[10px] text-mute">অর্ডার</p>
                </div>
                <div>
                    <p class="text-sm font-bold">{{ number_format($matchedCustomer->total_spent ?? 0) }}৳</p>
                    <p class="text-[10px] text-mute">খরচ</p>
                </div>
                <div>
                    <p class="text-sm font-bold">{{ number_format($matchedCustomer->due_balance ?? 0) }}৳</p>
                    <p class="text-[10px] text-mute">বাকি</p>
                </div>
            </div>
            @if (\Illuminate\Support\Facades\Route::has('tenant.customers.show'))
                <a href="{{ route('tenant.customers.show', $matchedCustomer) }}" class="block text-center text-xs font-semibold text-leaf hover:underline">প্রোফাইল দেখুন →</a>
            @endif
        </x-ui.card>
    @endif

    @if ($linkedOrder)
        <x-ui.card padding="sm">
            <p class="font-bold text-sm mb-2">🧾 লিংকড অর্ডার</p>
            <a href="{{ route('tenant.orders.show', $linkedOrder) }}"
               class="block rounded-btn border border-ink/10 px-3 py-2.5 hover:bg-paper/60 transition">
                <span class="font-semibold text-sm text-leaf">{{ $linkedOrder->order_number }}</span>
                <span class="text-xs px-2 py-0.5 rounded-pill font-semibold bg-amber/15 text-ink ml-1">{{ $orderStatusLabel[$linkedOrder->status] ?? $linkedOrder->status }}</span>
                @if ($linkedOrder->items()->exists())
                    <p class="text-xs text-mute mt-1">{{ number_format($linkedOrder->total) }}৳</p>
                @else
                    <p class="text-xs text-mute mt-1">প্রোডাক্ট এখনো বাছাই করা হয়নি</p>
                @endif
            </a>
        </x-ui.card>
    @elseif ($newOrderCreateUrl)
        <x-ui.card padding="sm">
            <p class="font-bold text-sm mb-2">🧾 নতুন অর্ডার</p>
            <p class="text-xs text-mute mb-3">কাস্টমার ফোন নাম্বার দিলে এখানে অর্ডার এমনিতেই তৈরি হয়ে যাবে। প্রয়োজনে ম্যানুয়ালিও শুরু করতে পারেন।</p>
            <a href="{{ $newOrderCreateUrl }}"
               class="block text-center py-2.5 rounded-btn border border-ink/15 font-semibold text-sm hover:bg-paper transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-leaf focus-visible:ring-offset-2">ম্যানুয়ালি অর্ডার শুরু করুন</a>
        </x-ui.card>
    @endif

    <x-ui.card padding="sm">
        <p class="font-bold text-sm mb-2">কনভারসেশন স্ট্যাটাস</p>
        <form method="POST" action="{{ $statusUpdateUrl }}">
            @csrf
            <select name="status" onchange="this.form.submit()" class="w-full rounded-btn border border-ink/15 px-3 py-2 text-xs bg-white">
                @foreach ($statusLabels as $k => $v)
                    <option value="{{ $k }}" @selected($statusValue === $k)>{{ $v }}</option>
                @endforeach
            </select>
        </form>
    </x-ui.card>
</div>
