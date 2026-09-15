{{--
    Honest courier tracking timeline for a Steadfast-sent order. Only 3 real
    stages are shown — "sent to courier" (true the moment a consignment_id
    exists), "processing" and the final outcome — because Steadfast's public
    API only ever returns one coarse delivery_status field (see
    SteadfastService::statusBucket()'s docblock), not a granular
    pickup/in-transit/at-hub/out-for-delivery breakdown. The raw Steadfast
    status string is always shown below the timeline too, so nothing is
    hidden behind the simplified stages.

    Props:
      order: App\Models\Order (must have courier_provider === 'steadfast'
             and a courier_consignment_id — caller's responsibility to check)
--}}
@props(['order'])
@php
    $bucket = \App\Services\Courier\SteadfastService::statusBucket($order->courier_status);
    $isFinal = in_array($bucket, ['delivered', 'cancelled'], true);
    $steps = [
        ['label' => 'পিকআপ রিকোয়েস্ট হয়েছে', 'state' => 'done'],
        ['label' => 'প্রসেসিং (কুরিয়ারের কাছে)', 'state' => $isFinal ? 'done' : 'current'],
        ['label' => $bucket === 'cancelled' ? 'বাতিল' : 'ডেলিভার্ড', 'state' => $isFinal ? ($bucket === 'cancelled' ? 'failed' : 'done') : 'pending'],
    ];
@endphp
<div {{ $attributes->merge(['class' => 'space-y-2']) }}>
    @foreach ($steps as $step)
        <div class="flex items-center gap-2 text-xs">
            <span @class([
                'w-4 h-4 rounded-pill grid place-items-center shrink-0',
                'bg-leaf text-white' => $step['state'] === 'done',
                'bg-red-500 text-white' => $step['state'] === 'failed',
                'border-2 border-leaf' => $step['state'] === 'current',
                'border border-ink/20' => $step['state'] === 'pending',
            ])>
                @if ($step['state'] === 'done')
                    <i data-lucide="check" class="w-2.5 h-2.5"></i>
                @elseif ($step['state'] === 'failed')
                    <i data-lucide="x" class="w-2.5 h-2.5"></i>
                @endif
            </span>
            <span class="{{ in_array($step['state'], ['done', 'failed', 'current'], true) ? 'text-ink font-medium' : 'text-mute' }}">{{ $step['label'] }}</span>
        </div>
    @endforeach
    @if ($order->courier_status)
        <p class="text-[11px] text-mute pt-1">Steadfast স্ট্যাটাস: <span class="font-medium text-ink">{{ $order->courier_status }}</span></p>
    @endif
</div>
