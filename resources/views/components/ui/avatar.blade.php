{{--
    Circular initials avatar — no photo/image field exists anywhere on a
    conversation contact (Customer, Messenger psid, or WhatsApp wa_id), so
    this generates one: first letter of $name, deterministic background
    color from a hash of $name so the same contact always gets the same
    color across renders (list row today, "load more"-appended rows later).
    props: name (string|null), size ('sm'|'default', default 'default').
--}}
@props(['name' => null, 'size' => 'default'])
@php
    $label = trim((string) $name);
    $initial = $label !== '' ? mb_strtoupper(mb_substr($label, 0, 1)) : '?';
    $palette = [
        'bg-leaf/15 text-leafdk', 'bg-amber/20 text-ink', 'bg-blue-100 text-blue-700',
        'bg-purple-100 text-purple-700', 'bg-red-100 text-red-700', 'bg-teal-100 text-teal-700',
    ];
    $color = $palette[crc32($label !== '' ? $label : '?') % count($palette)];
    $sizeClass = $size === 'sm' ? 'w-8 h-8 text-xs' : 'w-11 h-11 text-sm';
@endphp
<span {{ $attributes->merge(['class' => "inline-flex items-center justify-center rounded-full font-bold shrink-0 {$sizeClass} {$color}"]) }}>
    {{ $initial }}
</span>
