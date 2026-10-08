{{-- Brand/person logo: the uploaded image when there is one (`src`), otherwise a monogram on a gradient. --}}
@props(['initials', 'from' => '#128155', 'to' => '#0C5C3C', 'size' => 48, 'round' => false, 'ring' => false, 'src' => null, 'alt' => ''])
@php $r = $round ? '9999px' : round($size * 0.26).'px'; @endphp
@if($src)
    <img src="{{ $src }}" alt="{{ $alt }}" width="{{ $size }}" height="{{ $size }}" loading="lazy"
         {{ $attributes->merge(['class' => 'shrink-0 bg-white object-cover'.($ring ? ' ring-4 ring-white' : '')]) }}
         style="width:{{ $size }}px;height:{{ $size }}px;border-radius:{{ $r }}">
@else
<span {{ $attributes->merge(['class' => 'inline-flex shrink-0 items-center justify-center font-plat font-extrabold text-white select-none'.($ring ? ' ring-4 ring-white' : '')]) }}
      style="width:{{ $size }}px;height:{{ $size }}px;border-radius:{{ $r }};background:linear-gradient(135deg,{{ $from }},{{ $to }});font-size:{{ round($size * 0.36) }}px"
      aria-hidden="true">{{ $initials }}</span>
@endif
