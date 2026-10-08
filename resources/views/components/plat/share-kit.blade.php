{{--
    Copy / share buttons for a public link (platform.js handles data-copy
    and data-share). Messenger uses the fb-messenger:// app link, so it is
    only offered on small screens where the Messenger app is likely.
--}}
@props(['url', 'text' => '', 'title' => 'MetaSoft BD', 'compact' => false, 'onDark' => false])
@php
    $btn = 'inline-flex items-center justify-center gap-2 rounded-xl text-sm font-bold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-leaf '.($compact ? 'px-3 py-2.5' : 'px-4 py-3');
    $msg = trim($text.' '.$url);
@endphp
<div {{ $attributes->merge(['class' => 'grid grid-cols-2 gap-2 sm:flex sm:flex-wrap']) }}>
    <button type="button" data-copy="{{ $url }}" class="{{ $btn }} {{ $onDark ? 'bg-white text-night hover:bg-cloud' : 'bg-night text-white hover:bg-navy' }}">
        <x-plat.icon name="copy" class="w-4 h-4" /> Copy link
    </button>
    <button type="button" data-share data-share-url="{{ $url }}" data-share-title="{{ $title }}" data-share-text="{{ $text }}" class="{{ $btn }} {{ $onDark ? 'border border-white/30 bg-white/10 text-white hover:bg-white/20' : 'border border-hair bg-white hover:border-leaf' }}">
        <x-plat.icon name="share" class="w-4 h-4" /> Share
    </button>
    <a href="https://wa.me/?text={{ rawurlencode($msg) }}" target="_blank" rel="noopener" class="{{ $btn }} bg-[#25D366] text-white hover:brightness-95">
        @include('partials.icon', ['platform' => 'whatsapp', 'class' => 'w-4 h-4']) WhatsApp
    </a>
    <a href="https://www.facebook.com/sharer/sharer.php?u={{ rawurlencode($url) }}" target="_blank" rel="noopener" class="{{ $btn }} bg-[#1877F2] text-white hover:brightness-95">
        @include('partials.icon', ['platform' => 'facebook', 'class' => 'w-4 h-4']) Facebook
    </a>
    <a href="fb-messenger://share/?link={{ rawurlencode($url) }}" class="{{ $btn }} border border-hair bg-white text-[#0866FF] hover:border-[#0866FF] sm:hidden">
        <x-plat.icon name="mail" class="w-4 h-4" /> Messenger
    </a>
</div>
