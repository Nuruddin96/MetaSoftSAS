{{-- Single message attachment, type-aware. $url, $type (image/audio/video/file/null), $name (optional, file only).
     Every element degrades gracefully via onerror — same "hide the broken
     element, reveal a fallback message" pattern used for tenant logos in
     layouts/panel.blade.php — since Meta's original CDN URLs are time-limited
     and even our own re-hosted copy could be missing if re-hosting failed. --}}
<div class="mt-1.5">
    @if ($type === 'image')
        <img src="{{ $url }}" alt="ছবি" loading="lazy"
             class="max-w-[220px] max-h-[220px] rounded-lg border border-ink/10 object-cover cursor-pointer"
             onclick="openLightbox('{{ $url }}')"
             onerror="this.style.display='none'; this.nextElementSibling.classList.remove('hidden')">
        <p class="hidden text-xs opacity-80">⚠️ ছবিটি আর পাওয়া যাচ্ছে না</p>
    @elseif ($type === 'audio')
        <audio controls preload="none" class="max-w-[240px] h-10"
               onerror="this.style.display='none'; this.nextElementSibling.classList.remove('hidden')">
            <source src="{{ $url }}">
        </audio>
        <p class="hidden text-xs opacity-80">⚠️ অডিওটি আর পাওয়া যাচ্ছে না</p>
    @elseif ($type === 'video')
        <video controls preload="none" class="max-w-[240px] rounded-lg"
               onerror="this.style.display='none'; this.nextElementSibling.classList.remove('hidden')">
            <source src="{{ $url }}">
        </video>
        <p class="hidden text-xs opacity-80">⚠️ ভিডিওটি আর পাওয়া যাচ্ছে না</p>
    @else
        <a href="{{ $url }}" target="_blank" class="block underline text-xs">📎 {{ $name ?: 'এটাচমেন্ট দেখুন' }}</a>
    @endif
</div>
