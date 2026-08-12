@extends('layouts.panel')
@section('title', 'অ্যাডভার্টাইজিং — চার্জ হিস্টোরি')
@section('content')
<h1 class="font-disp font-bold text-2xl mb-6">🧾 চার্জ হিস্টোরি</h1>
<p class="text-sm text-mute mb-4">দৈনিক বাজেট: <span class="font-semibold text-ink">৳{{ number_format($account->daily_budget, 2) }}/দিন</span> · রেট: <span class="font-semibold text-ink">৳{{ number_format($account->billing_rate, 2) }}/USD</span></p>
@include('tenant.advertising._entries-table')
@endsection
