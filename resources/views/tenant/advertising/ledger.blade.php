@extends('layouts.panel')
@section('title', 'অ্যাডভার্টাইজিং — ব্যালেন্স / লেজার')
@section('content')
<div class="flex items-center justify-between mb-6">
    <div>
        <h1 class="font-disp font-bold text-2xl">📒 ব্যালেন্স / লেজার</h1>
        <p class="text-sm text-mute mt-1">বর্তমান ব্যালেন্স: <span class="font-semibold text-ink">৳{{ number_format($account->balance, 2) }}</span></p>
    </div>
</div>
@include('tenant.advertising._entries-table')
@endsection
