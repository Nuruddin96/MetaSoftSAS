@extends('layouts.panel')
@section('title', 'অ্যাডভার্টাইজিং — পেমেন্ট হিস্টোরি')
@section('content')
<h1 class="font-disp font-bold text-2xl mb-6">💳 পেমেন্ট হিস্টোরি</h1>
@include('tenant.advertising._entries-table')
@endsection
