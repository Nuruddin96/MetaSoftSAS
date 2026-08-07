@extends('layouts.panel')
@section('title', 'বিক্রয় রিপোর্ট')
@section('content')
<h1 class="font-disp font-bold text-2xl mb-6">বিক্রয় রিপোর্ট</h1>
@include('tenant.reports._filter')

<div class="grid grid-cols-2 lg:grid-cols-3 gap-4 mb-6">
    <div class="bg-white rounded-xl border border-ink/5 p-5"><p class="text-mute text-xs">মোট বিক্রি</p><p class="font-disp font-extrabold text-2xl mt-1">{{ number_format($revenue) }}৳</p></div>
    <div class="bg-white rounded-xl border border-ink/5 p-5"><p class="text-mute text-xs">অর্ডার সংখ্যা</p><p class="font-disp font-extrabold text-2xl mt-1">{{ $orders }}</p></div>
    <div class="bg-white rounded-xl border border-ink/5 p-5"><p class="text-mute text-xs">গড় অর্ডার</p><p class="font-disp font-extrabold text-2xl mt-1">{{ number_format($avg) }}৳</p></div>
</div>

<div class="bg-white rounded-xl border border-ink/5 p-5 mb-6">
    <p class="font-bold text-sm mb-4">বিক্রয়ের ট্রেন্ড</p>
    <canvas id="salesChart" height="90"></canvas>
</div>

<div class="grid lg:grid-cols-2 gap-6">
    <div class="bg-white rounded-xl border border-ink/5">
        <div class="px-5 py-3 border-b border-ink/5 font-bold text-sm">দিনভিত্তিক বিক্রি</div>
        <div class="max-h-96 overflow-auto">
            @forelse ($daily as $d)
                <div class="flex justify-between px-5 py-2.5 border-b border-ink/5 last:border-0 text-sm">
                    <span class="text-mute">{{ \Carbon\Carbon::parse($d->d)->format('d M') }} ({{ $d->orders }}টি)</span>
                    <span class="font-semibold">{{ number_format($d->revenue) }}৳</span>
                </div>
            @empty
                <p class="px-5 py-8 text-center text-mute text-sm">এই সময়ে কোনো বিক্রি নেই।</p>
            @endforelse
        </div>
    </div>

    <div class="space-y-6">
        <div class="bg-white rounded-xl border border-ink/5">
            <div class="px-5 py-3 border-b border-ink/5 font-bold text-sm">স্ট্যাটাস অনুযায়ী</div>
            @foreach ($byStatus as $s)
                <div class="flex justify-between px-5 py-2.5 border-b border-ink/5 last:border-0 text-sm">
                    <span class="text-mute">{{ $s->status }} ({{ $s->c }})</span>
                    <span>{{ number_format($s->t) }}৳</span>
                </div>
            @endforeach
        </div>
        <div class="bg-white rounded-xl border border-ink/5">
            <div class="px-5 py-3 border-b border-ink/5 font-bold text-sm">সোর্স অনুযায়ী</div>
            @foreach ($bySource as $s)
                <div class="flex justify-between px-5 py-2.5 border-b border-ink/5 last:border-0 text-sm">
                    <span class="text-mute">{{ ['web' => 'ওয়েবসাইট', 'pos' => 'POS', 'manual' => 'ম্যানুয়াল'][$s->source] ?? $s->source }} ({{ $s->c }})</span>
                    <span>{{ number_format($s->t) }}৳</span>
                </div>
            @endforeach
        </div>
    </div>
</div>
@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    const dailyData = @json($daily);
    new Chart(document.getElementById('salesChart'), {
        type: 'line',
        data: {
            labels: dailyData.map(d => new Date(d.d).toLocaleDateString('bn-BD', { day: 'numeric', month: 'short' })),
            datasets: [{
                label: 'বিক্রি (৳)',
                data: dailyData.map(d => d.revenue),
                borderColor: '#128155',
                backgroundColor: 'rgba(18,129,85,0.08)',
                fill: true,
                tension: 0.35,
                pointRadius: 3,
                pointBackgroundColor: '#128155',
            }]
        },
        options: {
            plugins: { legend: { display: false } },
            scales: { y: { beginAtZero: true, ticks: { callback: v => v.toLocaleString() + '৳' } } }
        }
    });
</script>
@endpush
@endsection