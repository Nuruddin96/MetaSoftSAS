@extends('layouts.panel')
@section('title', 'এলাকাভিত্তিক রিপোর্ট')
@section('content')
<h1 class="font-disp font-bold text-2xl mb-6">কোথা থেকে অর্ডার আসছে</h1>
@include('tenant.reports._filter')

@php $maxDiv = $byDivision->max('orders') ?: 1; @endphp

<div class="grid lg:grid-cols-2 gap-6">
    <div class="bg-white rounded-xl border border-ink/5">
        <div class="px-5 py-3 border-b border-ink/5 font-bold text-sm">বিভাগ অনুযায়ী</div>
        @forelse ($byDivision as $d)
            <div class="px-5 py-3 border-b border-ink/5 last:border-0">
                <div class="flex justify-between text-sm mb-1.5">
                    <span class="font-medium">{{ $d->name }}</span>
                    <span class="text-mute">{{ $d->orders }}টি · {{ number_format($d->revenue) }}৳</span>
                </div>
                <div class="h-2 rounded-full bg-ink/5 overflow-hidden">
                    <div class="h-full bg-leaf rounded-full" style="width: {{ round($d->orders / $maxDiv * 100) }}%"></div>
                </div>
            </div>
        @empty
            <p class="px-5 py-8 text-center text-mute text-sm">এই সময়ে কোনো অর্ডার নেই।</p>
        @endforelse
    </div>

    <div class="bg-white rounded-xl border border-ink/5">
        <div class="px-5 py-3 border-b border-ink/5 font-bold text-sm">শীর্ষ জেলা</div>
        <div class="max-h-96 overflow-auto">
            @foreach ($byDistrict as $i => $d)
                <div class="flex justify-between px-5 py-2.5 border-b border-ink/5 last:border-0 text-sm">
                    <span><span class="text-mute text-xs mr-2">{{ $i + 1 }}</span>{{ $d->name }}</span>
                    <span class="text-mute">{{ $d->orders }}টি · {{ number_format($d->revenue) }}৳</span>
                </div>
            @endforeach
        </div>
    </div>
</div>
@endsection
