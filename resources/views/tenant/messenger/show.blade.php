@extends('layouts.panel')
@section('title', $customer->customer_name ?: 'কথোপকথন')
@section('content')
<div class="grid lg:grid-cols-[340px_1fr] gap-0 lg:gap-6">
    <div class="hidden lg:block lg:h-[calc(100vh-140px)] lg:sticky lg:top-20 border border-ink/10 rounded-card overflow-hidden bg-white">
        @include('tenant.inbox._list', [
            'conversations' => $listConversations, 'nextCursor' => $listNextCursor, 'hasMore' => $listHasMore,
            'channel' => null, 'search' => null, 'unread' => false, 'activeConversationKey' => 'messenger:'.$psid,
        ])
    </div>

    <div id="conversationPane" class="min-w-0">
        @include('tenant.messenger._conversation')
    </div>
</div>

@include('tenant.inbox._shell_scripts')
@endsection
