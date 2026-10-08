{{-- Brand/person monogram placeholder, used until real logos and photos are uploaded. --}}
@props(['initials', 'from' => '#128155', 'to' => '#0C5C3C', 'size' => 48, 'round' => false, 'ring' => false])
@php $r = $round ? '9999px' : round($size * 0.26).'px'; @endphp
<span {{ $attributes->merge(['class' => 'inline-flex shrink-0 items-center justify-center font-plat font-extrabold text-white select-none'.($ring ? ' ring-4 ring-white' : '')]) }}
      style="width:{{ $size }}px;height:{{ $size }}px;border-radius:{{ $r }};background:linear-gradient(135deg,{{ $from }},{{ $to }});font-size:{{ round($size * 0.36) }}px"
      aria-hidden="true">{{ $initials }}</span>
