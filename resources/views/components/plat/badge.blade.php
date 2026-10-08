{{--
    Recognition / placement badge. Earned types (winner, jury, people,
    finalist) carry an icon; paid placement ('sponsored') is deliberately
    plain grey with a dashed outline so it can never be mistaken for an award.
--}}
@props(['type' => 'finalist', 'label', 'size' => 'sm'])
@php
    $map = [
        'winner' => ['bg-gold/25 text-golddk', 'trophy'],
        'jury' => ['bg-indigo-50 text-indigo-700', 'scale'],
        'people' => ['bg-rose-50 text-rose-700', 'users'],
        'finalist' => ['bg-mint text-leafdk', 'award'],
        'editor' => ['bg-night/80 text-white', 'star'],
        'verified' => ['bg-sky-50 text-sky-700', 'shield'],
        'sponsored' => ['bg-white/95 text-slate2 border border-dashed border-slate-300', null],
    ];
    [$cls, $icon] = $map[$type] ?? $map['finalist'];
    $sz = $size === 'xs' ? 'text-[10.5px] px-2 py-0.5 gap-1' : 'text-xs px-2.5 py-1 gap-1.5';
@endphp
<span {{ $attributes->merge(['class' => "inline-flex items-center whitespace-nowrap rounded-full font-semibold $cls $sz"]) }}>
    @if($icon)<x-plat.icon :name="$icon" class="{{ $size === 'xs' ? 'w-3 h-3' : 'w-3.5 h-3.5' }}" stroke="2.2" />@endif
    {{ $label }}
</span>
