@php
    $resolver = app(\App\Services\LandingPage\DesignResolver::class);
    $sd = $resolver->resolveSection($global, $data['design'] ?? null);
    $cid = 'cd-'.substr(md5(json_encode($data)), 0, 8);
@endphp
@if ($data['end_at'] ?? null)
    <x-landing.section :global="$global" :design="$data['design'] ?? null">
        @if ($data['heading'] ?? null)
            <h2 class="{{ $resolver->headingFontClass($global) }} font-bold {{ $resolver->headingClasses($sd) }} mb-4">{{ $data['heading'] }}</h2>
        @endif
        <div id="{{ $cid }}" data-end="{{ $data['end_at'] }}" data-expired-text="{{ $data['expired_text'] ?? '' }}"
             class="flex justify-center gap-3 font-disp font-extrabold text-2xl">
            <div><span class="cd-d">--</span><p class="text-xs font-normal text-mute">দিন</p></div>
            <div><span class="cd-h">--</span><p class="text-xs font-normal text-mute">ঘণ্টা</p></div>
            <div><span class="cd-m">--</span><p class="text-xs font-normal text-mute">মিনিট</p></div>
            <div><span class="cd-s">--</span><p class="text-xs font-normal text-mute">সেকেন্ড</p></div>
        </div>
    </x-landing.section>

    @push('scripts')
    <script>
        (function () {
            const el = document.getElementById(@json($cid));
            if (!el) return;
            const end = new Date(el.dataset.end).getTime();

            function tick() {
                const diff = end - Date.now();
                if (isNaN(end) || diff <= 0) {
                    el.innerHTML = '<p class="text-mute text-lg font-normal">' + (el.dataset.expiredText || '') + '</p>';
                    clearInterval(timer);
                    return;
                }
                const d = Math.floor(diff / 86400000);
                const h = Math.floor((diff % 86400000) / 3600000);
                const m = Math.floor((diff % 3600000) / 60000);
                const s = Math.floor((diff % 60000) / 1000);
                el.querySelector('.cd-d').textContent = d;
                el.querySelector('.cd-h').textContent = String(h).padStart(2, '0');
                el.querySelector('.cd-m').textContent = String(m).padStart(2, '0');
                el.querySelector('.cd-s').textContent = String(s).padStart(2, '0');
            }

            tick();
            const timer = setInterval(tick, 1000);
        })();
    </script>
    @endpush
@endif
