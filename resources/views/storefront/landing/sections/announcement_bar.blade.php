@if ($data['text'] ?? null)
    <x-landing.section :global="$global" :design="$data['design'] ?? null" class="bg-ink text-white">
        <div class="flex items-center justify-center gap-3 text-sm font-medium">
            @if ($data['link_url'] ?? null)
                <a href="{{ $data['link_url'] }}" class="underline underline-offset-2">{{ $data['text'] }}</a>
            @else
                <span>{{ $data['text'] }}</span>
            @endif
            @if ($data['dismissible'] ?? true)
                <button type="button" onclick="this.closest('section').remove()" class="ml-2 opacity-70 hover:opacity-100" aria-label="বন্ধ করুন">✕</button>
            @endif
        </div>
    </x-landing.section>
@endif
