{{--
    Circular avatar — renders a real photo when $url is given, falling back
    to initials otherwise: first letter of $name, deterministic background
    color from a hash of $name so the same contact always gets the same
    color across renders (list row, chat header, customer panel, "load
    more"-appended rows).

    No caller passes $url yet. Persisting a real photo (Messenger's Graph
    API profile_pic, WhatsApp has none at all — see the chat-UI refinement's
    investigation notes) would need a new database column, which this pass
    was explicitly scoped not to add — this component is deliberately
    already URL-ready so that decision can be made later without touching
    any of its call sites.
    props: name (string|null), url (string|null), size ('sm'|'default', default 'default').
--}}
@props(['name' => null, 'url' => null, 'size' => 'default'])
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
@if ($url)
    {{-- Same "hide broken image, reveal sibling fallback" pattern already
         used in tenant/whatsapp/_attachment.blade.php — a dead/expired
         photo URL degrades to the initials span, never a broken-image icon. --}}
    <span {{ $attributes->merge(['class' => "relative inline-block shrink-0 {$sizeClass}"]) }}>
        <img src="{{ $url }}" alt="{{ $label ?: 'কাস্টমার' }}" loading="lazy"
             class="rounded-full object-cover w-full h-full"
             onerror="this.style.display='none'; this.nextElementSibling.classList.remove('hidden')">
        <span class="hidden absolute inset-0 items-center justify-center rounded-full font-bold {{ $color }}">{{ $initial }}</span>
    </span>
@else
    <span {{ $attributes->merge(['class' => "inline-flex items-center justify-center rounded-full font-bold shrink-0 {$sizeClass} {$color}"]) }}>
        {{ $initial }}
    </span>
@endif
